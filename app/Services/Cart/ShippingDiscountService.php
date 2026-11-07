<?php

namespace App\Services\Cart;

use App\Models\Setting;

class ShippingDiscountService
{
    public const KEY = 'general_shipping_discount';

    public function configuration(): array
    {
        return array_merge(['enabled' => false, 'type' => 'fixed', 'value' => 0],
            json_decode(Setting::where('key', self::KEY)->value('value') ?? '{}', true) ?: []);
    }

    /** Immutable pricing snapshot for a newly quoted delivery address. */
    public function quote(int $baseCents): array
    {
        $baseCents = max(0, $baseCents);
        $configuration = $this->configuration();
        $feeCents = $this->feeCents($baseCents, $configuration);

        return [
            'original_delivery_fee' => $baseCents / 100,
            'shipping_discount' => ($baseCents - $feeCents) / 100,
            'delivery_fee' => $feeCents / 100,
            'general_shipping_discount' => $configuration['enabled'] ? $configuration : null,
            'free_shipping_offer' => null,
        ];
    }

    /** Value is cents for fixed discounts, basis points for percentages. */
    public function feeCents(int $baseCents, ?array $configuration = null): int
    {
        $baseCents = max(0, $baseCents);
        $config = $configuration ?? $this->configuration();
        if (! $config['enabled']) {
            return $baseCents;
        }
        $value = max(0, (int) $config['value']);
        $discount = $config['type'] === 'percent'
            ? (int) round($baseCents * min(10000, $value) / 10000)
            : $value;

        // Charge whole pounds after the discount, always rounding the payable fee up.
        return intdiv(max(0, $baseCents - $discount) + 99, 100) * 100;
    }
}
