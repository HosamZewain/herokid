<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\OrderStatusRegistry;
use App\Support\Phone;
use Illuminate\Support\Collection;

class RelatedCustomerCheckoutService
{
    /**
     * @return array{total: int, checkouts: Collection<int, array<string, mixed>>}
     */
    public function forGroup(array $group, int $limit = 8): array
    {
        $phone = Phone::forWhatsApp($group['phone'] ?? null);
        $phoneValues = Phone::equivalentValues($group['phone'] ?? null);

        if ($phone === null || $phoneValues === []) {
            return ['total' => 0, 'checkouts' => collect()];
        }

        $orders = Order::query()
            ->with([
                'checkoutReference:id,checkout_group_key,short_reference',
                'story:id,title',
                'items:id,order_id,item_type,title,quantity',
            ])
            ->where('checkout_group_key', '!=', $group['key'])
            ->whereIn('delivery_details->phone', $phoneValues)
            ->latest('created_at')
            ->get()
            ->filter(fn (Order $order): bool => Phone::forWhatsApp(data_get($order->delivery_details, 'phone')) === $phone)
            ->groupBy(fn (Order $order): string => $order->checkoutGroupKey())
            ->map(function (Collection $checkoutOrders): array {
                $checkoutOrders = $checkoutOrders->sortBy('id')->values();
                $first = $checkoutOrders->first();
                $statuses = $checkoutOrders->pluck('status')->filter()->unique()->values();
                $titles = $checkoutOrders
                    ->flatMap(fn (Order $order): Collection => $order->items
                        ->filter(fn ($item): bool => in_array($item->item_type, ['story', 'product', 'product_add_on'], true))
                        ->map(fn ($item): string => $item->title))
                    ->filter()
                    ->unique()
                    ->values();

                return [
                    'representative_id' => (int) $first->id,
                    'reference' => $first->checkoutReference?->short_reference ?: $first->checkoutGroupKey(),
                    'created_at' => $checkoutOrders->min('created_at'),
                    'status_label' => $statuses->count() === 1
                        ? OrderStatusRegistry::label(OrderStatusRegistry::TYPE_ORDER, (string) $statuses->first())
                        : 'حالات متعددة',
                    'titles' => $titles->all(),
                ];
            })
            ->sortByDesc('created_at')
            ->values();

        return [
            'total' => $orders->count(),
            'checkouts' => $orders->take(max(1, $limit))->values(),
        ];
    }
}
