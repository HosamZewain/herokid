<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderGroupMergeAlias;
use App\Models\OrderPaymentEvent;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderDateTime;
use App\Support\OrderPaymentStatus;
use App\Support\OrderSource;
use App\Support\OrderStatusRegistry;
use App\Support\OrderWorkflowStatus;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminOrderGroupService
{
    private const DASHBOARD_STATUSES = [
        'new',
        'preview_uploaded',
        'shipped',
        'delivered',
    ];

    private const INDEX_RELATIONS = [
        'user:id,name,role',
        'createdByAdmin:id,name',
        'paymentUpdatedBy:id,name',
        'groupAssignment.assignee:id,name',
        'checkoutReference:id,checkout_group_key,short_reference,reference_month,monthly_sequence',
        'bookletPreview:id,order_id,uuid,status,current_version_id,public_token_encrypted',
        'productPreviewGallery:id,checkout_group_key,status,public_token_encrypted',
        'productPreviewGallery.previews:id,product_gallery_id',
        'story:id,title,price',
        'items.product:id,name_ar,inventory_mode,stock_quantity,production_prompt_template',
        'items.variant:id,product_id,name_ar,sku,stock_quantity',
    ];

    private const DETAIL_RELATIONS = [
        ...self::INDEX_RELATIONS,
        'items.linkedAddOns.product:id,name_ar',
        'statusLogs',
        'previews',
        'attachments.uploader:id,name',
        'productionProject.assignedTo:id,name',
    ];

    public function paginate(Request $request, bool $includeStatistics = true): array
    {
        $catalogType = $this->catalogType($request);
        $lifecycle = $this->lifecycle($request);
        $includeDeleted = $this->includeDeleted($request, $lifecycle);
        $query = $this->groupedFilteredQuery($request, $includeDeleted, $catalogType, $lifecycle);

        $perPage = in_array($request->integer('per_page', 25), [25, 50, 100], true)
            ? $request->integer('per_page', 25)
            : 25;

        $groups = (clone $query)
            ->selectRaw('checkout_group_key, MAX(created_at) as latest_at, MAX(updated_at) as latest_updated_at, MIN(id) as representative_id')
            ->groupBy('checkout_group_key');

        $this->applyGroupOrdering($groups, $request);

        $groups = $groups->paginate($perPage)
            ->withQueryString();

        $keys = $groups->getCollection()->pluck('checkout_group_key');
        $orders = $this->ordersForKeys($keys, $includeDeleted)->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

        $groups->setCollection($groups->getCollection()
            ->map(fn ($group): array => $this->present($orders->get($group->checkout_group_key, collect()), $includeDeleted))
            ->filter()
            ->values());

        $stats = null;

        if ($includeStatistics) {
            $allKeys = (clone $query)->distinct()->pluck('checkout_group_key');
            $matchingOrders = $this->visibleOrdersForStats(
                $this->ordersForStats($allKeys, $includeDeleted),
                $includeDeleted,
            );
            $financialStats = $this->financialStats($matchingOrders);

            $stats = [
                'checkouts' => $allKeys->count(),
                'stories' => $matchingOrders->filter(fn (Order $order): bool => $this->isStoryOrder($order))->count(),
                'products' => (int) $matchingOrders->flatMap->items->whereIn('item_type', ['product', 'product_add_on'])->sum('quantity'),
                ...$financialStats,
            ];
        }

        return [
            'groups' => $groups,
            'stats' => $stats,
            'trash' => $request->query('view') === 'trash',
            'catalogType' => $catalogType,
            'lifecycle' => $lifecycle,
            'assignmentUsers' => User::query()
                ->where('role', 'admin')
                ->where(function (Builder $query): void {
                    $query
                        ->where('is_active', true)
                        ->orWhereHas('assignedOrderGroups');
                })
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
            'filterProducts' => Product::query()
                ->whereExists(fn ($items) => $items
                    ->selectRaw('1')
                    ->from('order_items')
                    ->whereColumn('order_items.product_id', 'products.id'))
                ->orderBy('name_ar')
                ->get(['id', 'name_ar', 'slug']),
        ];
    }

    public function export(Request $request): Collection
    {
        $catalogType = $this->catalogType($request);
        $lifecycle = $this->lifecycle($request);
        $includeDeleted = $this->includeDeleted($request, $lifecycle);
        $query = $this->groupedFilteredQuery($request, $includeDeleted, $catalogType, $lifecycle);
        $grouped = (clone $query)
            ->selectRaw('checkout_group_key, MAX(created_at) as latest_at, MAX(updated_at) as latest_updated_at')
            ->groupBy('checkout_group_key');
        $this->applyGroupOrdering($grouped, $request);
        $keys = $grouped->pluck('checkout_group_key');
        $orders = $this->ordersForKeys($keys, $includeDeleted)
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

        return $keys
            ->map(fn (string $key): array => $this->present($orders->get($key, collect()), $includeDeleted))
            ->filter()
            ->values();
    }

    public function findByRepresentative(int $representativeId): array
    {
        $representative = Order::withTrashed()->findOrFail($representativeId);
        $orders = Order::withTrashed()
            ->with(self::DETAIL_RELATIONS)
            ->where('checkout_group_key', $representative->checkoutGroupKey())
            ->orderBy('id')
            ->get();

        return $this->present($orders, $orders->every->trashed());
    }

    public function dashboardStats(): array
    {
        $byStatus = Order::query()
            ->selectRaw('status, COUNT(*) as record_count, COUNT(DISTINCT checkout_group_key) as checkout_count')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $checkouts = [
            'total' => Order::query()->distinct()->count('checkout_group_key'),
        ];
        $records = [
            'total' => (int) $byStatus->sum('record_count'),
        ];

        foreach (self::DASHBOARD_STATUSES as $status) {
            $checkouts[$status] = (int) ($byStatus->get($status)?->checkout_count ?? 0);
            $records[$status] = (int) ($byStatus->get($status)?->record_count ?? 0);
        }

        foreach (['shipped', 'delivered'] as $behavior) {
            $keys = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, $behavior);
            $checkouts[$behavior] = (int) $byStatus->only($keys)->sum('checkout_count');
            $records[$behavior] = (int) $byStatus->only($keys)->sum('record_count');
        }

        $today = OrderDateTime::display(now())->toDateString();
        $todayStart = OrderDateTime::utcStartOfDay($today);
        $todayEnd = OrderDateTime::utcEndOfDay($today);
        $todayKeys = Order::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->distinct()
            ->pluck('checkout_group_key');
        $todayFinancial = $this->financialStats($this->ordersForStats($todayKeys, false));
        $todayPayments = $this->paymentActivityBetween($todayStart, $todayEnd);

        $yesterday = OrderDateTime::display(now())->subDay()->toDateString();
        $yesterdayStart = OrderDateTime::utcStartOfDay($yesterday);
        $yesterdayEnd = OrderDateTime::utcEndOfDay($yesterday);
        $yesterdayCheckouts = Order::query()
            ->whereBetween('created_at', [$yesterdayStart, $yesterdayEnd])
            ->distinct()
            ->count('checkout_group_key');
        $newCheckoutDifference = $todayKeys->count() - $yesterdayCheckouts;

        $activeKeys = Order::query()
            ->whereNotIn('checkout_group_key', $this->cancelledCheckoutKeys())
            ->whereIn('checkout_group_key', $this->unfinishedCheckoutKeys())
            ->distinct()
            ->pluck('checkout_group_key');
        $activeFinancial = $this->financialStats($this->ordersForStats($activeKeys, false));
        $unassignedCheckouts = $activeKeys->isEmpty()
            ? 0
            : Order::query()
                ->whereIn('checkout_group_key', $activeKeys)
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('order_group_assignments')
                    ->whereColumn('order_group_assignments.checkout_group_key', 'orders.checkout_group_key'))
                ->distinct()
                ->count('checkout_group_key');

        return [
            'checkouts' => $checkouts,
            'records' => $records,
            'today' => [
                'new_checkouts' => $todayKeys->count(),
                'yesterday_checkouts' => $yesterdayCheckouts,
                'new_checkouts_difference' => $newCheckoutDifference,
                'new_checkouts_change_percent' => $yesterdayCheckouts > 0
                    ? (int) round(($newCheckoutDifference / $yesterdayCheckouts) * 100)
                    : null,
                'order_value_cents' => $todayFinancial['total_value_cents'],
                'average_order_cents' => $todayKeys->isNotEmpty()
                    ? (int) round($todayFinancial['total_value_cents'] / $todayKeys->count())
                    : 0,
                'payment_checkouts' => $todayPayments['count'],
                'payments_cents' => $todayPayments['amount_cents'],
                'payment_events' => $todayPayments['events'],
            ],
            'operations' => [
                'active_checkouts' => $activeKeys->count(),
                'unassigned_checkouts' => $unassignedCheckouts,
                'active_value_cents' => $activeFinancial['total_value_cents'],
                'collected_cents' => $activeFinancial['collected_cents'],
                'outstanding_cents' => $activeFinancial['outstanding_cents'],
            ],
            'last_seven_days' => $this->lastSevenDaysDashboardStats(),
        ];
    }

    /**
     * Daily checkout intake for the latest seven Cairo calendar days.
     *
     * Story checkouts contain at least one story; product checkouts contain no
     * stories. This keeps both categories exclusive and counts delivery once.
     *
     * @return array<int, array<string, int|string>>
     */
    private function lastSevenDaysDashboardStats(): array
    {
        $today = OrderDateTime::display(now())->startOfDay();
        $dates = collect(range(6, 0))
            ->map(fn (int $daysAgo): CarbonImmutable => $today->subDays($daysAgo));
        $start = OrderDateTime::utcStartOfDay($dates->first()->toDateString());
        $end = OrderDateTime::utcEndOfDay($dates->last()->toDateString());

        $createdCheckouts = Order::withTrashed()
            ->selectRaw('checkout_group_key, MIN(created_at) as first_created_at')
            ->whereNotNull('checkout_group_key')
            ->groupBy('checkout_group_key')
            ->havingRaw('MIN(created_at) >= ? AND MIN(created_at) <= ?', [$start, $end])
            ->get();

        $checkoutDates = $createdCheckouts->mapWithKeys(function (Order $checkout): array {
            $createdAt = CarbonImmutable::parse((string) $checkout->getRawOriginal('first_created_at'));

            return [(string) $checkout->checkout_group_key => OrderDateTime::display($createdAt)->toDateString()];
        });
        $checkoutKeys = $checkoutDates->keys();
        $ordersByCheckout = $this->visibleOrdersForStats(
            $this->ordersForStats($checkoutKeys, true),
            true,
        )->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

        $paymentActivity = $this->paymentEventsBetween($start, $end)
            ->map(function (OrderPaymentEvent $event): array {
                return [
                    'date' => OrderDateTime::display($event->occurred_at)->toDateString(),
                    'amount_cents' => (int) $event->amount_delta_cents,
                ];
            })
            ->groupBy('date');

        $cancelledStatuses = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled');
        $cancelledActivity = $cancelledStatuses === []
            ? collect()
            : DB::table('order_status_logs as status_logs')
                ->join('orders as status_orders', 'status_orders.id', '=', 'status_logs.order_id')
                ->where('status_logs.status_type', OrderStatusRegistry::TYPE_ORDER)
                ->whereIn('status_logs.status', $cancelledStatuses)
                ->whereBetween('status_logs.created_at', [$start, $end])
                ->get(['status_orders.checkout_group_key', 'status_logs.created_at'])
                ->map(function (object $event): array {
                    return [
                        'date' => OrderDateTime::display(CarbonImmutable::parse((string) $event->created_at))->toDateString(),
                        'checkout_group_key' => (string) $event->checkout_group_key,
                    ];
                })
                ->groupBy('date');

        return $dates->map(function (CarbonImmutable $date) use (
            $checkoutDates,
            $ordersByCheckout,
            $paymentActivity,
            $cancelledActivity,
        ): array {
            $dateString = $date->toDateString();
            $dayKeys = $checkoutDates
                ->filter(fn (string $createdDate): bool => $createdDate === $dateString)
                ->keys();
            $dayOrders = $dayKeys
                ->flatMap(fn (string $key): Collection => $ordersByCheckout->get($key, collect()))
                ->values();
            $storyKeys = $dayOrders
                ->groupBy(fn (Order $order): string => $order->checkoutGroupKey())
                ->filter(fn (Collection $orders): bool => $orders->contains(fn (Order $order): bool => $this->isStoryOrder($order)))
                ->keys();
            $productKeys = $dayKeys->diff($storyKeys)->values();
            $storyFinancial = $this->financialStats($dayOrders->whereIn('checkout_group_key', $storyKeys)->values());
            $productFinancial = $this->financialStats($dayOrders->whereIn('checkout_group_key', $productKeys)->values());
            $totalValueCents = $storyFinancial['total_value_cents'] + $productFinancial['total_value_cents'];
            $newCheckouts = $dayKeys->count();

            return [
                'date' => $dateString,
                'day_label' => $date->translatedFormat('l'),
                'date_label' => $date->translatedFormat('d/m'),
                'new_checkouts' => $newCheckouts,
                'story_checkouts' => $storyKeys->count(),
                'product_checkouts' => $productKeys->count(),
                'story_value_cents' => $storyFinancial['total_value_cents'],
                'product_value_cents' => $productFinancial['total_value_cents'],
                'total_value_cents' => $totalValueCents,
                'payments_cents' => (int) collect($paymentActivity->get($dateString, collect()))->sum('amount_cents'),
                'cancelled_checkouts' => collect($cancelledActivity->get($dateString, collect()))
                    ->pluck('checkout_group_key')
                    ->unique()
                    ->count(),
                'average_order_cents' => $newCheckouts > 0
                    ? (int) round($totalValueCents / $newCheckouts)
                    : 0,
            ];
        })->all();
    }

    /**
     * @return array{
     *     count:int,
     *     amount_cents:int,
     *     events:array<int, array<string, int|string|null>>
     * }
     */
    private function paymentActivityBetween(mixed $start, mixed $end): array
    {
        $events = $this->paymentEventsBetween($start, $end);

        return [
            'count' => $events->where('event_type', 'payment_received')->count(),
            'amount_cents' => (int) $events->sum('amount_delta_cents'),
            'events' => $events
                ->sortByDesc('occurred_at')
                ->map(function (OrderPaymentEvent $event): array {
                    return [
                        'id' => $event->id,
                        'order_id' => $event->order_id,
                        'checkout_group_key' => $event->checkout_group_key,
                        'reference' => $event->order?->checkoutReference?->short_reference
                            ?: $event->checkout_group_key,
                        'event_type' => $event->event_type,
                        'status_label' => OrderPaymentStatus::label($event->new_status),
                        'amount_delta_cents' => (int) $event->amount_delta_cents,
                        'new_paid_amount_cents' => (int) $event->new_paid_amount_cents,
                        'payment_method' => $event->payment_method,
                        'actor_name' => $event->actor?->name ?: 'النظام',
                        'occurred_at_label' => OrderDateTime::display($event->occurred_at)?->format('d/m/Y h:i A'),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /** @return Collection<int, OrderPaymentEvent> */
    private function paymentEventsBetween(mixed $start, mixed $end): Collection
    {
        return OrderPaymentEvent::query()
            ->with(['actor:id,name', 'order.checkoutReference'])
            ->whereBetween('occurred_at', [$start, $end])
            ->where('affects_collection_stats', true)
            ->orderBy('occurred_at')
            ->get();
    }

    public function recent(int $limit = 8): Collection
    {
        $groups = Order::query()
            ->selectRaw('checkout_group_key, MAX(created_at) as latest_at')
            ->groupBy('checkout_group_key')
            ->orderByDesc('latest_at')
            ->limit(max(1, $limit))
            ->get();

        $keys = $groups->pluck('checkout_group_key');
        $orders = $this->ordersForKeys($keys, false)
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

        return $groups
            ->map(fn ($group): array => $this->present(
                $orders->get($group->checkout_group_key, collect()),
                false
            ))
            ->filter()
            ->values();
    }

    public function ordersForGroup(Order $order, bool $withTrashed = false): Collection
    {
        $query = $withTrashed ? Order::withTrashed() : Order::query();

        return $query
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->orderBy('id')
            ->get();
    }

    public function present(Collection $orders, bool $trash = false): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        $orders = $orders->sortBy('id')->values();
        $activeOrders = $orders->reject->trashed()->values();
        $visibleOrders = $trash && $activeOrders->isEmpty() ? $orders : $activeOrders;
        $first = $visibleOrders->first() ?: $orders->first();
        $items = $visibleOrders->flatMap->items->values();
        $storyOrders = $activeOrders->filter(fn (Order $order): bool => $this->isStoryOrder($order))->values();
        $displayStoryOrders = $visibleOrders->filter(fn (Order $order): bool => $this->isStoryOrder($order))->values();
        $directProducts = $items->where('item_type', 'product')->values();
        $addOns = $items->where('item_type', 'product_add_on')->values();
        $statuses = $visibleOrders->pluck('status')->filter()->unique()->values();
        $printingStatuses = $visibleOrders->pluck('printing_status')
            ->map(fn (?string $status): string => in_array($status, OrderWorkflowStatus::printingStatuses(false), true)
                ? $status
                : OrderWorkflowStatus::PRINTING_NOT_STARTED)
            ->unique()->values();
        $shippingStatuses = $visibleOrders->pluck('shipping_status')
            ->map(fn (?string $status): string => in_array($status, OrderWorkflowStatus::shippingStatuses(false), true)
                ? $status
                : OrderWorkflowStatus::SHIPPING_NOT_READY)
            ->unique()->values();
        $deliveryCents = (int) round(max(0, (float) data_get($first->delivery_details, 'delivery_fee', 0)) * 100);
        $discountCents = (int) $visibleOrders->max('discount_cents');
        $itemsCents = (int) $items->sum('total_price_cents');

        if ($itemsCents === 0) {
            $itemsCents = (int) round($visibleOrders->sum(fn (Order $order): float => (float) (data_get($order->delivery_details, 'item_price') ?? $order->story?->price ?? 0)) * 100);
        }

        $phone = (string) (data_get($first->delivery_details, 'phone') ?? '');
        $customerKey = $first->user && $first->user->role !== 'admin'
            ? 'user-'.$first->user->id
            : 'guest-'.sha1($phone ?: 'order-'.$first->id);
        $totalCents = max(0, $itemsCents + $deliveryCents - $discountCents);
        $paymentStatus = in_array($first->payment_status, OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_PAYMENT, false), true)
            ? $first->payment_status
            : OrderPaymentStatus::UNPAID;
        $paidAmountCents = min($totalCents, max(0, (int) $first->paid_amount_cents));

        return [
            'key' => $first->checkoutGroupKey(),
            'short_reference' => $first->checkoutReference?->short_reference,
            'representative_id' => (int) $first->id,
            'direct_order_id' => $storyOrders->isNotEmpty()
                ? (int) $storyOrders->first()->id
                : null,
            'created_at' => $orders->min('created_at'),
            'latest_at' => $orders->max('created_at'),
            'updated_at' => $orders->max('updated_at'),
            'orders' => $orders,
            'active_orders' => $activeOrders,
            'deleted_orders' => $orders->filter->trashed()->values(),
            'story_orders' => $storyOrders,
            'display_story_orders' => $displayStoryOrders,
            'direct_products' => $directProducts,
            'add_ons' => $addOns,
            'order_numbers' => $visibleOrders->pluck('order_number')->values()->all(),
            'customer_name' => $first->parent_name ?: $first->user?->name ?: 'زائر',
            'customer_key' => $customerKey,
            'phone' => $phone,
            'delivery' => $first->delivery_details ?? [],
            'order_source' => $first->order_source ?: 'website',
            'source_notes' => $first->source_notes,
            'created_by_admin' => $first->createdByAdmin,
            'assignment' => $first->groupAssignment,
            'assigned_admin' => $first->groupAssignment?->assignee,
            'assigned_at' => $first->groupAssignment?->assigned_at,
            'story_count' => $displayStoryOrders->count(),
            'product_quantity' => (int) $directProducts->sum('quantity'),
            'add_on_quantity' => (int) $addOns->sum('quantity'),
            'child_names' => $displayStoryOrders->pluck('child_name')->filter()->unique()->values()->all(),
            'story_titles' => $displayStoryOrders->map(fn (Order $order): string => $order->items->firstWhere('item_type', 'story')?->title ?: $order->story?->title ?: 'قصة مخصصة')->values()->all(),
            'product_titles' => $directProducts->pluck('title')->filter()->unique()->values()->all(),
            'add_on_titles' => $addOns->pluck('title')->filter()->unique()->values()->all(),
            'statuses' => $statuses->all(),
            'status' => $statuses->count() === 1 ? $statuses->first() : 'mixed',
            'status_label' => $statuses->count() === 1 ? OrderStatusRegistry::label(OrderStatusRegistry::TYPE_ORDER, $statuses->first()) : 'حالات متعددة',
            'printing_status' => $printingStatuses->count() === 1 ? $printingStatuses->first() : 'mixed',
            'printing_status_label' => $printingStatuses->count() === 1
                ? OrderWorkflowStatus::printingLabel($printingStatuses->first())
                : 'حالات طباعة متعددة',
            'shipping_status' => $shippingStatuses->count() === 1 ? $shippingStatuses->first() : 'mixed',
            'shipping_status_label' => $shippingStatuses->count() === 1
                ? OrderWorkflowStatus::shippingLabel($shippingStatuses->first())
                : 'حالات شحن متعددة',
            'items_cents' => $itemsCents,
            'delivery_cents' => $deliveryCents,
            'discount_cents' => $discountCents,
            'discount_reason' => $visibleOrders->pluck('discount_reason')->filter()->first(),
            'total_cents' => $totalCents,
            'payment_status' => $paymentStatus,
            'payment_status_label' => OrderPaymentStatus::label($paymentStatus),
            'paid_amount_cents' => $paidAmountCents,
            'remaining_amount_cents' => max(0, $totalCents - $paidAmountCents),
            'payment_method' => $first->payment_method,
            'payment_updated_at' => $first->payment_updated_at,
            'payment_updated_by' => $first->paymentUpdatedBy,
            'trashed' => $activeOrders->isEmpty(),
        ];
    }

    private function filteredQuery(
        Request $request,
        bool $includeDeleted,
        string $catalogType,
        string $lifecycle,
    ): Builder {
        $query = $includeDeleted ? Order::withTrashed() : Order::query();
        $query->whereIn('checkout_group_key', $this->checkoutKeysForCatalogType($catalogType));
        $this->applyLifecycleFilter($query, $lifecycle);
        $status = (string) $request->query('status', '');

        if ($status !== '' && $status !== 'mixed') {
            $query->where('status', $status);
        }

        if ($request->filled('payment_status')) {
            $paymentStatus = (string) $request->query('payment_status');

            if (in_array($paymentStatus, OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_PAYMENT, false), true)) {
                $query->where('payment_status', $paymentStatus);
            }
        }

        if ($request->filled('printing_status') && in_array($request->query('printing_status'), OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_PRINTING, false), true)) {
            $query->where('printing_status', $request->query('printing_status'));
        }

        if ($request->filled('shipping_status') && in_array($request->query('shipping_status'), OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_SHIPPING, false), true)) {
            $query->where('shipping_status', $request->query('shipping_status'));
        }

        if ($request->filled('order_source') && array_key_exists((string) $request->query('order_source'), OrderSource::options())) {
            $query->where('order_source', $request->query('order_source'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', trim((string) $request->query('payment_method')));
        }

        if ($request->filled('from')) {
            try {
                $query->where('created_at', '>=', OrderDateTime::utcStartOfDay((string) $request->query('from')));
            } catch (\Throwable) {
                // Ignore malformed query dates and keep the list usable.
            }
        }

        if ($request->filled('to')) {
            try {
                $query->where('created_at', '<=', OrderDateTime::utcEndOfDay((string) $request->query('to')));
            } catch (\Throwable) {
                // Ignore malformed query dates and keep the list usable.
            }
        }

        if ($request->filled('product_id')) {
            $productId = $request->integer('product_id');

            if ($productId > 0) {
                $query->whereExists(function ($items) use ($productId, $includeDeleted): void {
                    $items
                        ->selectRaw('1')
                        ->from('order_items as filtered_product_items')
                        ->join('orders as filtered_product_orders', 'filtered_product_orders.id', '=', 'filtered_product_items.order_id')
                        ->whereColumn('filtered_product_orders.checkout_group_key', 'orders.checkout_group_key')
                        ->where('filtered_product_items.product_id', $productId);

                    if (! $includeDeleted) {
                        $items->whereNull('filtered_product_orders.deleted_at');
                    }
                });
            }
        }

        $this->applyEventFilter($query, $request);

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $phoneValues = preg_match('/\A[\d\s()+-]{7,}\z/', $term) === 1
                ? Phone::equivalentValues($term)
                : [];
            $mergedTargetKeys = OrderGroupMergeAlias::query()
                ->where('source_short_reference', 'like', '%'.$term.'%')
                ->orWhere('source_checkout_group_key', 'like', '%'.$term.'%')
                ->pluck('target_checkout_group_key');
            $query->where(function (Builder $builder) use ($term, $phoneValues, $mergedTargetKeys): void {
                $builder
                    ->where('checkout_group_key', 'like', '%'.$term.'%')
                    ->orWhereHas('checkoutReference', fn (Builder $reference): Builder => $reference
                        ->where('short_reference', 'like', '%'.$term.'%'))
                    ->orWhere('order_number', 'like', '%'.$term.'%')
                    ->orWhere('parent_name', 'like', '%'.$term.'%')
                    ->orWhere('child_name', 'like', '%'.$term.'%')
                    ->orWhere('delivery_details->phone', 'like', '%'.$term.'%')
                    ->orWhereHas('story', fn (Builder $story): Builder => $story->where('title', 'like', '%'.$term.'%'))
                    ->orWhereHas('items', fn (Builder $items): Builder => $items
                        ->where('title', 'like', '%'.$term.'%')
                        ->orWhere('sku', 'like', '%'.$term.'%'));
                if ($phoneValues !== []) {
                    $builder->orWhereIn('delivery_details->phone', $phoneValues);
                }
                if ($mergedTargetKeys->isNotEmpty()) {
                    $builder->orWhereIn('checkout_group_key', $mergedTargetKeys);
                }
            });
        }

        $assignment = (string) $request->query('assignment', '');
        if ($assignment === 'mine' && $request->user()) {
            $query->whereExists(fn ($assigned) => $assigned
                ->selectRaw('1')
                ->from('order_group_assignments')
                ->whereColumn('order_group_assignments.checkout_group_key', 'orders.checkout_group_key')
                ->where('order_group_assignments.assigned_to_user_id', $request->user()->id));
        } elseif ($assignment === 'unassigned') {
            $query->whereNotExists(fn ($assigned) => $assigned
                ->selectRaw('1')
                ->from('order_group_assignments')
                ->whereColumn('order_group_assignments.checkout_group_key', 'orders.checkout_group_key'));
        } elseif ($assignment === 'assigned') {
            $query->whereExists(fn ($assigned) => $assigned
                ->selectRaw('1')
                ->from('order_group_assignments')
                ->whereColumn('order_group_assignments.checkout_group_key', 'orders.checkout_group_key'));
        } elseif (preg_match('/\Auser:(\d+)\z/', $assignment, $matches) === 1) {
            $query->whereExists(fn ($assigned) => $assigned
                ->selectRaw('1')
                ->from('order_group_assignments')
                ->whereColumn('order_group_assignments.checkout_group_key', 'orders.checkout_group_key')
                ->where('order_group_assignments.assigned_to_user_id', (int) $matches[1]));
        }

        return $query;
    }

    private function applyEventFilter(Builder $query, Request $request): void
    {
        $event = trim((string) $request->query('event', ''));

        if ($event === '') {
            return;
        }

        [$type, $value] = array_pad(explode(':', $event, 2), 2, '');
        $from = $this->eventBoundary($request, 'event_from', false);
        $to = $this->eventBoundary($request, 'event_to', true);

        if (in_array($type, [
            OrderStatusRegistry::TYPE_ORDER,
            OrderStatusRegistry::TYPE_PRINTING,
            OrderStatusRegistry::TYPE_SHIPPING,
        ], true) && OrderStatusRegistry::isValid($type, $value, false)) {
            $query->whereExists(function ($events) use ($type, $value, $from, $to): void {
                $events
                    ->selectRaw('1')
                    ->from('order_status_logs as filtered_status_events')
                    ->join('orders as filtered_status_orders', 'filtered_status_orders.id', '=', 'filtered_status_events.order_id')
                    ->whereColumn('filtered_status_orders.checkout_group_key', 'orders.checkout_group_key')
                    ->where('filtered_status_events.status_type', $type)
                    ->where('filtered_status_events.status', $value);

                if ($from) {
                    $events->where('filtered_status_events.created_at', '>=', $from);
                }

                if ($to) {
                    $events->where('filtered_status_events.created_at', '<=', $to);
                }
            });

            return;
        }

        $validPaymentStatus = $type === OrderStatusRegistry::TYPE_PAYMENT
            && OrderStatusRegistry::isValid(OrderStatusRegistry::TYPE_PAYMENT, $value, false);
        $validPaymentEvent = $type === 'payment_event' && in_array($value, ['received', 'reversed'], true);

        if (! $validPaymentStatus && ! $validPaymentEvent) {
            return;
        }

        $query->whereExists(function ($events) use ($validPaymentStatus, $value, $from, $to): void {
            $events
                ->selectRaw('1')
                ->from('order_payment_events as filtered_payment_events')
                ->whereColumn('filtered_payment_events.checkout_group_key', 'orders.checkout_group_key');

            if ($validPaymentStatus) {
                $events->where('filtered_payment_events.new_status', $value);
            } elseif ($value === 'received') {
                $events
                    ->where('filtered_payment_events.event_type', 'payment_received')
                    ->where('filtered_payment_events.amount_delta_cents', '>', 0)
                    ->where('filtered_payment_events.affects_collection_stats', true);
            } else {
                $events->where('filtered_payment_events.event_type', 'payment_reversed');
            }

            if ($from) {
                $events->where('filtered_payment_events.occurred_at', '>=', $from);
            }

            if ($to) {
                $events->where('filtered_payment_events.occurred_at', '<=', $to);
            }
        });
    }

    private function eventBoundary(Request $request, string $field, bool $endOfDay): ?CarbonImmutable
    {
        if (! $request->filled($field)) {
            return null;
        }

        try {
            return $endOfDay
                ? OrderDateTime::utcEndOfDay((string) $request->query($field))
                : OrderDateTime::utcStartOfDay((string) $request->query($field));
        } catch (\Throwable) {
            return null;
        }
    }

    private function applyGroupOrdering(Builder $query, Request $request): void
    {
        $sort = (string) $request->query('sort', 'created_at');
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $column = $sort === 'updated_at' ? 'latest_updated_at' : 'latest_at';

        $query->orderBy($column, $direction)->orderByRaw('MIN(id) '.$direction);
    }

    private function groupedFilteredQuery(
        Request $request,
        bool $includeDeleted,
        string $catalogType,
        string $lifecycle,
    ): Builder {
        $query = $this->filteredQuery($request, $includeDeleted, $catalogType, $lifecycle);

        if ($request->query('status') === 'mixed') {
            $mixedKeys = (clone $query)
                ->get(['checkout_group_key', 'status'])
                ->groupBy('checkout_group_key')
                ->filter(fn (Collection $orders): bool => $orders->pluck('status')->unique()->count() > 1)
                ->keys();

            $query->whereIn('checkout_group_key', $mixedKeys);
        }

        return $query;
    }

    private function ordersForKeys(Collection $keys, bool $includeDeleted): Collection
    {
        if ($keys->isEmpty()) {
            return collect();
        }

        $query = $includeDeleted ? Order::withTrashed() : Order::query();

        return $query
            ->with(self::INDEX_RELATIONS)
            ->whereIn('checkout_group_key', $keys)
            ->orderBy('id')
            ->get();
    }

    private function ordersForStats(Collection $keys, bool $includeDeleted): Collection
    {
        if ($keys->isEmpty()) {
            return collect();
        }

        $query = $includeDeleted ? Order::withTrashed() : Order::query();

        return $query
            ->select([
                'id',
                'story_id',
                'checkout_group_key',
                'status',
                'payment_status',
                'paid_amount_cents',
                'payment_updated_at',
                'shipping_status',
                'discount_cents',
                'delivery_details',
                'deleted_at',
            ])
            ->with([
                'story:id,price',
                'items:id,order_id,item_type,quantity,total_price_cents',
            ])
            ->whereIn('checkout_group_key', $keys)
            ->get();
    }

    private function financialStats(Collection $orders): array
    {
        $checkouts = $orders
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey())
            ->map(function (Collection $group): array {
                $group = $group->sortBy('id')->values();
                $first = $group->first();
                $itemsCents = (int) $group->flatMap->items->sum('total_price_cents');

                if ($itemsCents === 0) {
                    $itemsCents = (int) round($group->sum(
                        fn (Order $order): float => (float) (data_get($order->delivery_details, 'item_price') ?? $order->story?->price ?? 0)
                    ) * 100);
                }

                $deliveryCents = (int) round(max(0, (float) data_get($first->delivery_details, 'delivery_fee', 0)) * 100);
                $discountCents = (int) $group->max('discount_cents');
                $totalCents = max(0, $itemsCents + $deliveryCents - $discountCents);
                $statuses = $group->pluck('status')->filter()->unique();
                $shippingStatuses = $group->pluck('shipping_status')
                    ->map(fn (?string $status): string => in_array($status, OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_SHIPPING, false), true)
                        ? $status
                        : OrderWorkflowStatus::SHIPPING_NOT_READY)
                    ->unique();
                $paymentStatus = in_array($first->payment_status, OrderStatusRegistry::keys(OrderStatusRegistry::TYPE_PAYMENT, false), true)
                    ? $first->payment_status
                    : OrderPaymentStatus::UNPAID;

                return [
                    'total_cents' => $totalCents,
                    'paid_amount_cents' => min($totalCents, max(0, (int) $first->paid_amount_cents)),
                    'status' => $statuses->count() === 1 ? $statuses->first() : 'mixed',
                    'payment_status' => $paymentStatus,
                    'shipping_status' => $shippingStatuses->count() === 1 ? $shippingStatuses->first() : 'mixed',
                ];
            })
            ->values();

        $cancelled = $checkouts->filter(fn (array $checkout): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $checkout['status']) === 'cancelled');
        $paid = $checkouts->filter(fn (array $checkout): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_PAYMENT, $checkout['payment_status']) === 'paid_in_full');
        $withPayments = $checkouts->filter(fn (array $checkout): bool => $checkout['paid_amount_cents'] > 0);
        $totalValueCents = (int) $checkouts->sum('total_cents');

        return [
            'total_value_cents' => $totalValueCents,
            'average_order_cents' => $checkouts->isNotEmpty()
                ? (int) round($totalValueCents / $checkouts->count())
                : 0,
            'collected_cents' => (int) $checkouts->sum('paid_amount_cents'),
            'payment_checkouts' => $withPayments->count(),
            'outstanding_cents' => (int) $checkouts->sum(
                fn (array $checkout): int => max(0, $checkout['total_cents'] - $checkout['paid_amount_cents'])
            ),
            'cancelled_checkouts' => $cancelled->count(),
            'cancelled_value_cents' => (int) $cancelled->sum('total_cents'),
            'paid_checkouts' => $paid->count(),
            'paid_value_cents' => (int) $paid->sum('total_cents'),
            'shipped_checkouts' => $checkouts->filter(fn (array $checkout): bool => in_array(
                OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $checkout['shipping_status']),
                ['shipped', 'delivered'],
                true,
            ))->count(),
        ];
    }

    private function isStoryOrder(Order $order): bool
    {
        return $order->story_id !== null || $order->items->contains('item_type', 'story');
    }

    private function visibleOrdersForStats(Collection $orders, bool $includeDeleted): Collection
    {
        if (! $includeDeleted) {
            return $orders;
        }

        return $orders
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey())
            ->flatMap(function (Collection $group): Collection {
                $active = $group->reject->trashed()->values();

                return $active->isNotEmpty() ? $active : $group->values();
            })
            ->values();
    }

    private function catalogType(Request $request): string
    {
        if ($request->query('view') === 'trash') {
            return 'all';
        }

        $type = (string) $request->query('catalog_type', 'stories');

        return in_array($type, ['all', 'stories', 'products'], true) ? $type : 'stories';
    }

    private function lifecycle(Request $request): string
    {
        if ($request->query('view') === 'trash') {
            return 'cancelled';
        }

        $lifecycle = (string) $request->query('lifecycle', 'active');

        return in_array($lifecycle, ['all', 'active', 'finished', 'cancelled'], true) ? $lifecycle : 'active';
    }

    private function includeDeleted(Request $request, string $lifecycle): bool
    {
        if (! in_array($lifecycle, ['all', 'cancelled'], true)) {
            return false;
        }

        return (bool) $request->user()?->hasPermission('orders.delete')
            || ($request->attributes->getBoolean('order_report')
                && (bool) $request->user()?->hasPermission('order_reports.view'));
    }

    private function checkoutKeysForCatalogType(string $catalogType): \Illuminate\Database\Query\Builder
    {
        if ($catalogType === 'all') {
            return DB::table('orders as catalog_orders')
                ->select('catalog_orders.checkout_group_key')
                ->whereNotNull('catalog_orders.checkout_group_key')
                ->distinct();
        }

        $storyExists = fn ($query) => $query
            ->selectRaw('1')
            ->from('orders as story_orders')
            ->whereColumn('story_orders.checkout_group_key', 'catalog_orders.checkout_group_key')
            ->where(function ($story): void {
                $story->whereNotNull('story_orders.story_id')
                    ->orWhereExists(fn ($items) => $items
                        ->selectRaw('1')
                        ->from('order_items as story_items')
                        ->whereColumn('story_items.order_id', 'story_orders.id')
                        ->where('story_items.item_type', 'story'));
            });

        return DB::table('orders as catalog_orders')
            ->select('catalog_orders.checkout_group_key')
            ->whereNotNull('catalog_orders.checkout_group_key')
            ->when(
                $catalogType === 'stories',
                fn ($query) => $query->whereExists($storyExists),
                fn ($query) => $query->whereNotExists($storyExists),
            )
            ->distinct();
    }

    private function applyLifecycleFilter(Builder $query, string $lifecycle): void
    {
        if ($lifecycle === 'all') {
            return;
        }

        $cancelledKeys = $this->cancelledCheckoutKeys();
        $unfinishedKeys = $this->unfinishedCheckoutKeys();

        if ($lifecycle === 'cancelled') {
            $query->whereIn('checkout_group_key', $cancelledKeys);

            return;
        }

        $query->whereNotIn('checkout_group_key', $cancelledKeys);

        if ($lifecycle === 'finished') {
            $query->whereNotIn('checkout_group_key', $unfinishedKeys);
        } else {
            $query->whereIn('checkout_group_key', $unfinishedKeys);
        }
    }

    private function cancelledCheckoutKeys(): \Illuminate\Database\Query\Builder
    {
        $cancelledOrderKeys = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled');

        return DB::table('orders as cancelled_orders')
            ->select('cancelled_orders.checkout_group_key')
            ->whereNotNull('cancelled_orders.checkout_group_key')
            ->whereNotExists(function ($query) use ($cancelledOrderKeys): void {
                $query->selectRaw('1')
                    ->from('orders as live_orders')
                    ->whereColumn('live_orders.checkout_group_key', 'cancelled_orders.checkout_group_key')
                    ->whereNull('live_orders.deleted_at')
                    ->when(
                        $cancelledOrderKeys !== [],
                        fn ($live) => $live->where(fn ($status) => $status
                            ->whereNull('live_orders.status')
                            ->orWhereNotIn('live_orders.status', $cancelledOrderKeys)),
                    );
            })
            ->distinct();
    }

    private function unfinishedCheckoutKeys(): \Illuminate\Database\Query\Builder
    {
        $doneOrderKeys = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'delivered');
        $donePaymentKeys = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_PAYMENT, 'paid_in_full');
        $donePrintingKeys = array_values(array_unique(array_merge(
            OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_PRINTING, 'completed'),
            OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_PRINTING, 'not_required'),
        )));
        $doneShippingKeys = array_values(array_unique(array_merge(
            OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'delivered'),
            OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'not_required'),
        )));

        return DB::table('orders as unfinished_orders')
            ->select('unfinished_orders.checkout_group_key')
            ->whereNull('unfinished_orders.deleted_at')
            ->whereNotNull('unfinished_orders.checkout_group_key')
            ->where(function ($query) use ($doneOrderKeys, $donePaymentKeys, $donePrintingKeys, $doneShippingKeys): void {
                $this->whereStatusIsNotDone($query, 'unfinished_orders.status', $doneOrderKeys);
                $this->orWhereStatusIsNotDone($query, 'unfinished_orders.payment_status', $donePaymentKeys);
                $this->orWhereStatusIsNotDone($query, 'unfinished_orders.printing_status', $donePrintingKeys);
                $this->orWhereStatusIsNotDone($query, 'unfinished_orders.shipping_status', $doneShippingKeys);
            })
            ->distinct();
    }

    private function whereStatusIsNotDone($query, string $column, array $doneKeys): void
    {
        $query->where(fn ($status) => $status
            ->whereNull($column)
            ->when($doneKeys !== [], fn ($value) => $value->orWhereNotIn($column, $doneKeys)));
    }

    private function orWhereStatusIsNotDone($query, string $column, array $doneKeys): void
    {
        $query->orWhere(fn ($status) => $status
            ->whereNull($column)
            ->when($doneKeys !== [], fn ($value) => $value->orWhereNotIn($column, $doneKeys)));
    }
}
