<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\OrderPaymentStatus;
use App\Support\OrderStatusRegistry;
use App\Support\OrderWorkflowStatus;
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
        'story:id,title,price',
        'items.product:id,name_ar,inventory_mode,stock_quantity',
        'items.variant:id,product_id,name_ar,sku,stock_quantity',
    ];

    private const DETAIL_RELATIONS = [
        ...self::INDEX_RELATIONS,
        'items.linkedAddOns.product:id,name_ar',
        'statusLogs',
        'previews',
        'productionProject.assignedTo:id,name',
    ];

    public function paginate(Request $request): array
    {
        $catalogType = $this->catalogType($request);
        $lifecycle = $this->lifecycle($request);
        $includeDeleted = $this->includeDeleted($request, $lifecycle);
        $query = $this->groupedFilteredQuery($request, $includeDeleted, $catalogType, $lifecycle);

        $perPage = in_array($request->integer('per_page', 25), [25, 50, 100], true)
            ? $request->integer('per_page', 25)
            : 25;

        $groups = (clone $query)
            ->selectRaw('checkout_group_key, MAX(created_at) as latest_at, MIN(id) as representative_id')
            ->groupBy('checkout_group_key')
            ->orderByDesc('latest_at')
            ->paginate($perPage)
            ->withQueryString();

        $keys = $groups->getCollection()->pluck('checkout_group_key');
        $orders = $this->ordersForKeys($keys, $includeDeleted)->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

        $groups->setCollection($groups->getCollection()
            ->map(fn ($group): array => $this->present($orders->get($group->checkout_group_key, collect()), $includeDeleted))
            ->filter()
            ->values());

        $allKeys = (clone $query)->distinct()->pluck('checkout_group_key');
        $matchingOrders = $this->visibleOrdersForStats(
            $this->ordersForStats($allKeys, $includeDeleted),
            $includeDeleted,
        );
        $financialStats = $this->financialStats($matchingOrders);

        return [
            'groups' => $groups,
            'stats' => [
                'checkouts' => $allKeys->count(),
                'stories' => $matchingOrders->filter(fn (Order $order): bool => $this->isStoryOrder($order))->count(),
                'products' => (int) $matchingOrders->flatMap->items->whereIn('item_type', ['product', 'product_add_on'])->sum('quantity'),
                ...$financialStats,
            ],
            'trash' => $request->query('view') === 'trash',
            'catalogType' => $catalogType,
            'lifecycle' => $lifecycle,
        ];
    }

    public function export(Request $request): Collection
    {
        $catalogType = $this->catalogType($request);
        $lifecycle = $this->lifecycle($request);
        $includeDeleted = $this->includeDeleted($request, $lifecycle);
        $query = $this->groupedFilteredQuery($request, $includeDeleted, $catalogType, $lifecycle);
        $keys = (clone $query)
            ->selectRaw('checkout_group_key, MAX(created_at) as latest_at')
            ->groupBy('checkout_group_key')
            ->orderByDesc('latest_at')
            ->pluck('checkout_group_key');
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

        return compact('checkouts', 'records');
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
            'representative_id' => (int) $first->id,
            'direct_order_id' => $storyOrders->isNotEmpty()
                ? (int) $storyOrders->first()->id
                : null,
            'created_at' => $orders->min('created_at'),
            'latest_at' => $orders->max('created_at'),
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

        if ($request->filled('from')) {
            try {
                $query->where('created_at', '>=', CarbonImmutable::parse((string) $request->query('from'))->startOfDay());
            } catch (\Throwable) {
                // Ignore malformed query dates and keep the list usable.
            }
        }

        if ($request->filled('to')) {
            try {
                $query->where('created_at', '<=', CarbonImmutable::parse((string) $request->query('to'))->endOfDay());
            } catch (\Throwable) {
                // Ignore malformed query dates and keep the list usable.
            }
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('checkout_group_key', 'like', '%'.$term.'%')
                    ->orWhere('order_number', 'like', '%'.$term.'%')
                    ->orWhere('parent_name', 'like', '%'.$term.'%')
                    ->orWhere('child_name', 'like', '%'.$term.'%')
                    ->orWhere('delivery_details->phone', 'like', '%'.$term.'%')
                    ->orWhereHas('story', fn (Builder $story): Builder => $story->where('title', 'like', '%'.$term.'%'))
                    ->orWhereHas('items', fn (Builder $items): Builder => $items
                        ->where('title', 'like', '%'.$term.'%')
                        ->orWhere('sku', 'like', '%'.$term.'%'));
            });
        }

        return $query;
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
                'shipping_status',
                'discount_cents',
                'delivery_details',
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
                    'status' => $statuses->count() === 1 ? $statuses->first() : 'mixed',
                    'payment_status' => $paymentStatus,
                    'shipping_status' => $shippingStatuses->count() === 1 ? $shippingStatuses->first() : 'mixed',
                ];
            })
            ->values();

        $cancelled = $checkouts->filter(fn (array $checkout): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $checkout['status']) === 'cancelled');
        $paid = $checkouts->filter(fn (array $checkout): bool => OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_PAYMENT, $checkout['payment_status']) === 'paid_in_full');

        return [
            'total_value_cents' => (int) $checkouts->sum('total_cents'),
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

        return in_array($type, ['stories', 'products'], true) ? $type : 'stories';
    }

    private function lifecycle(Request $request): string
    {
        if ($request->query('view') === 'trash') {
            return 'cancelled';
        }

        $lifecycle = (string) $request->query('lifecycle', 'active');

        return in_array($lifecycle, ['active', 'finished', 'cancelled'], true) ? $lifecycle : 'active';
    }

    private function includeDeleted(Request $request, string $lifecycle): bool
    {
        return $lifecycle === 'cancelled'
            && (bool) $request->user()?->hasPermission('orders.delete');
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
