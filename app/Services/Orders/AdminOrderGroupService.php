<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\OrderPaymentStatus;
use App\Support\OrderWorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
        $trash = $request->query('view') === 'trash';
        $query = $this->filteredQuery($request, $trash);

        if ($request->query('status') === 'mixed') {
            $mixedKeys = (clone $query)
                ->get(['checkout_group_key', 'status'])
                ->groupBy('checkout_group_key')
                ->filter(fn (Collection $orders): bool => $orders->pluck('status')->unique()->count() > 1)
                ->keys();

            $query->whereIn('checkout_group_key', $mixedKeys);
        }

        $perPage = in_array($request->integer('per_page', 15), [15, 30, 50], true)
            ? $request->integer('per_page', 15)
            : 15;

        $groups = (clone $query)
            ->selectRaw('checkout_group_key, MAX(created_at) as latest_at, MIN(id) as representative_id')
            ->groupBy('checkout_group_key')
            ->orderByDesc('latest_at')
            ->paginate($perPage)
            ->withQueryString();

        $keys = $groups->getCollection()->pluck('checkout_group_key');
        $orders = $this->ordersForKeys($keys, $trash)->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

        $groups->setCollection($groups->getCollection()
            ->map(fn ($group): array => $this->present($orders->get($group->checkout_group_key, collect()), $trash))
            ->filter()
            ->values());

        $allKeys = (clone $query)->distinct()->pluck('checkout_group_key');
        $matchingOrders = $this->ordersForStats($allKeys, $trash);
        $financialStats = $this->financialStats($matchingOrders);

        return [
            'groups' => $groups,
            'stats' => [
                'checkouts' => $allKeys->count(),
                'stories' => $matchingOrders->filter(fn (Order $order): bool => $this->isStoryOrder($order))->count(),
                'products' => (int) $matchingOrders->flatMap->items->whereIn('item_type', ['product', 'product_add_on'])->sum('quantity'),
                ...$financialStats,
            ],
            'trash' => $trash,
        ];
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
            ->map(fn (?string $status): string => in_array($status, OrderWorkflowStatus::PRINTING_STATUSES, true)
                ? $status
                : OrderWorkflowStatus::PRINTING_NOT_STARTED)
            ->unique()->values();
        $shippingStatuses = $visibleOrders->pluck('shipping_status')
            ->map(fn (?string $status): string => in_array($status, OrderWorkflowStatus::SHIPPING_STATUSES, true)
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
        $paymentStatus = in_array($first->payment_status, OrderPaymentStatus::STATUSES, true)
            ? $first->payment_status
            : OrderPaymentStatus::UNPAID;
        $paidAmountCents = min($totalCents, max(0, (int) $first->paid_amount_cents));

        return [
            'key' => $first->checkoutGroupKey(),
            'representative_id' => (int) $first->id,
            'direct_order_id' => ! $trash && $storyOrders->isNotEmpty()
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
            'status_label' => $statuses->count() === 1 ? (string) __('order_status.'.$statuses->first()) : 'حالات متعددة',
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

    private function filteredQuery(Request $request, bool $trash): Builder
    {
        $query = $trash ? Order::onlyTrashed() : Order::query();
        $status = (string) $request->query('status', '');

        if ($status !== '' && $status !== 'mixed') {
            $query->where('status', $status);
        }

        if ($request->filled('payment_status')) {
            $paymentStatus = (string) $request->query('payment_status');

            if (in_array($paymentStatus, OrderPaymentStatus::STATUSES, true)) {
                $query->where('payment_status', $paymentStatus);
            }
        }

        if ($request->filled('printing_status') && in_array($request->query('printing_status'), OrderWorkflowStatus::PRINTING_STATUSES, true)) {
            $query->where('printing_status', $request->query('printing_status'));
        }

        if ($request->filled('shipping_status') && in_array($request->query('shipping_status'), OrderWorkflowStatus::SHIPPING_STATUSES, true)) {
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

    private function ordersForKeys(Collection $keys, bool $trash): Collection
    {
        if ($keys->isEmpty()) {
            return collect();
        }

        $query = $trash ? Order::onlyTrashed() : Order::query();

        return $query
            ->with(self::INDEX_RELATIONS)
            ->whereIn('checkout_group_key', $keys)
            ->orderBy('id')
            ->get();
    }

    private function ordersForStats(Collection $keys, bool $trash): Collection
    {
        if ($keys->isEmpty()) {
            return collect();
        }

        $query = $trash ? Order::onlyTrashed() : Order::query();

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
                    ->map(fn (?string $status): string => in_array($status, OrderWorkflowStatus::SHIPPING_STATUSES, true)
                        ? $status
                        : OrderWorkflowStatus::SHIPPING_NOT_READY)
                    ->unique();
                $paymentStatus = in_array($first->payment_status, OrderPaymentStatus::STATUSES, true)
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

        $cancelled = $checkouts->where('status', 'cancelled');
        $paid = $checkouts->where('payment_status', OrderPaymentStatus::PAID_IN_FULL);

        return [
            'total_value_cents' => (int) $checkouts->sum('total_cents'),
            'cancelled_checkouts' => $cancelled->count(),
            'cancelled_value_cents' => (int) $cancelled->sum('total_cents'),
            'paid_checkouts' => $paid->count(),
            'paid_value_cents' => (int) $paid->sum('total_cents'),
            'shipped_checkouts' => $checkouts->where('shipping_status', OrderWorkflowStatus::SHIPPING_SHIPPED)->count(),
        ];
    }

    private function isStoryOrder(Order $order): bool
    {
        return $order->story_id !== null || $order->items->contains('item_type', 'story');
    }
}
