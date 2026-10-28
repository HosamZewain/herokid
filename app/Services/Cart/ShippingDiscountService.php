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

        return max(0, $baseCents - $discount);
    }
}
