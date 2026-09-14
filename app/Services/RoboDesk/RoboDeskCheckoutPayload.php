<?php

namespace App\Services\RoboDesk;

use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\Phone;

class RoboDeskCheckoutPayload
{
    public function __construct(private readonly RoboDeskSettings $settings) {}

    public function build(string $checkoutGroupKey): array
    {
        $representative = Order::query()->where('checkout_group_key', $checkoutGroupKey)->orderBy('id')->firstOrFail();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($representative->id);

        return [
            'checkout_reference' => $checkoutGroupKey,
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
            'instapay_url' => $this->settings->instaPayUrl(),
            'whatsapp_number' => $this->settings->whatsAppNumber(),
        ];
    }
}
