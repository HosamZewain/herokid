<?php

namespace App\Services\RoboDesk;

use App\Models\Order;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\Phone;

/**
 * Produces the variable bag each action exposes to its payload template.
 *
 * Every key here is documented in the action's variables() list and rendered as
 * an available {{ placeholder }} on the admin screen, so the two never drift.
 */
class RoboDeskVariableBuilder
{
    public function __construct(
        private readonly AdminOrderGroupService $groups,
        private readonly RoboDeskSettings $settings,
    ) {}

    public function forCheckout(?string $checkoutGroupKey): array
    {
        if (blank($checkoutGroupKey)) {
            return [];
        }

        $representative = Order::query()
            ->where('checkout_group_key', $checkoutGroupKey)
            ->orderBy('id')
            ->first();

        if (! $representative) {
            return ['checkout_reference' => $checkoutGroupKey];
        }

        $group = $this->groups->findByRepresentative($representative->id);
        $delivery = (array) ($representative->delivery_details ?? []);

        return [
            'checkout_reference' => $checkoutGroupKey,
            'short_reference' => $group['short_reference'] ?? null,
            'customer_name' => $group['customer_name'] ?? $representative->parent_name,
            'customer_phone' => Phone::forWhatsApp((string) ($group['phone'] ?? '')),
            'order_numbers' => $group['order_numbers'] ?? [],
            'order_number' => $representative->order_number,
            'children' => $group['child_names'] ?? [],
            'items_summary' => $this->itemsSummary($group),
            'items_total' => $this->money($group['items_cents'] ?? 0),
            'delivery_fee' => $this->money($group['delivery_cents'] ?? 0),
            'discount' => $this->money($group['discount_cents'] ?? 0),
            'total' => $this->money($group['total_cents'] ?? 0),
            'paid_amount' => $this->money($group['paid_amount_cents'] ?? 0),
            'remaining_amount' => $this->money($group['remaining_amount_cents'] ?? 0),
            'currency' => 'EGP',
            'delivery_country' => $delivery['country'] ?? null,
            'delivery_governorate' => $delivery['governorate'] ?? null,
            'delivery_city' => $delivery['city'] ?? null,
            'delivery_street' => $delivery['street'] ?? null,
            'delivery_address' => $this->address($delivery),
            'customer_notes' => $this->notes($representative),
            'order_status' => $group['status'] ?? $representative->status,
            'payment_status' => $group['payment_status'] ?? $representative->payment_status,
            'shipping_status' => $group['shipping_status'] ?? null,
            'instapay_url' => $this->settings->instaPayUrl(),
            'whatsapp_number' => $this->settings->whatsAppNumber(),
        ];
    }

    private function itemsSummary(array $group): string
    {
        return collect(array_merge(
            $group['story_titles'] ?? [],
            $group['product_titles'] ?? [],
            $group['add_on_titles'] ?? [],
        ))->filter()->implode('، ');
    }

    private function address(array $delivery): string
    {
        return collect([
            $delivery['country'] ?? null,
            $delivery['governorate'] ?? null,
            $delivery['city'] ?? null,
            $delivery['street'] ?? null,
            $delivery['address_details'] ?? null,
        ])->filter()->implode(' - ');
    }

    private function notes(Order $order): string
    {
        return collect([$order->notes, $order->gift_note])->filter()->implode(' | ');
    }

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
