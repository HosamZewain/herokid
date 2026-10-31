<?php

namespace App\Services\RoboDesk;

use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\Phone;

class RoboDeskCheckoutPayload
{
    public function build(string $checkoutGroupKey): array
    {
        $representative = Order::query()->where('checkout_group_key', $checkoutGroupKey)->orderBy('id')->firstOrFail();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($representative->id);

        $details = $group['display_story_orders']->map(fn (Order $order): string => ($order->items->firstWhere('item_type', 'story')?->title ?: $order->story?->title ?: 'قصة مخصصة')
            .' × '.max(1, (int) $order->items->where('item_type', 'story')->sum('quantity')))
            ->concat($group['direct_products']->concat($group['add_ons'])->map(fn ($item): string => $item->title.' × '.max(1, (int) $item->quantity)));

        return [
            'order_details' => $details->implode(' · '),
            'checkout_reference' => $checkoutGroupKey,
            'display_reference' => $group['short_reference'] ?: $group['order_numbers'][0],
            'order_numbers' => $group['order_numbers'],
            'customer' => [
                'name' => $group['customer_name'],
                'phone' => Phone::forWhatsApp((string) $group['phone']),
            ],
            'children' => $group['child_names'],
            'stories' => $group['story_titles'],
            'products' => array_values(array_merge($group['product_titles'], $group['add_on_titles'])),
            'amounts' => [
                'items' => $group['items_cents'] / 100,
                'delivery' => $group['delivery_cents'] / 100,
                'discount' => $group['discount_cents'] / 100,
                'total' => $group['total_cents'] / 100,
                'paid' => $group['paid_amount_cents'] / 100,
                'remaining' => $group['remaining_amount_cents'] / 100,
                'currency' => 'EGP',
            ],
            'statuses' => [
                'order' => $group['status'],
                'payment' => $group['payment_status'],
                'printing' => $group['printing_status'],
                'shipping' => $group['shipping_status'],
            ],
            'instapay_url' => config('robodesk.instapay_url'),
            'whatsapp_number' => config('robodesk.whatsapp_number'),
        ];
    }
}
