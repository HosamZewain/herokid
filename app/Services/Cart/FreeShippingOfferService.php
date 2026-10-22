<?php

namespace App\Services\Cart;

use App\Models\Setting;
use Carbon\CarbonImmutable;

class FreeShippingOfferService
{
    public const KEY = 'website_free_shipping_offer';

    public function configuration(): array
    {
        // Read directly so disabling an offer takes effect at checkout, even with cached settings.
        $value = Setting::query()->where('key', self::KEY)->value('value');

        return array_merge(['enabled' => false, 'minimum_cents' => 100000, 'starts_at' => null, 'ends_at' => null],
            json_decode($value ?? '{}', true) ?: []);
    }

    public function active(): ?array
    {
        $offer = $this->configuration();
        if (! $offer['enabled'] || ! $offer['starts_at'] || ! $offer['ends_at']) {
            return null;
        }

        $now = CarbonImmutable::now();
        if ($now->lt(CarbonImmutable::parse($offer['starts_at'])) || $now->gte(CarbonImmutable::parse($offer['ends_at']))) {
            return null;
        }

        return $offer;
    }

    public function quote(int $subtotalCents, int $discountCents, int $deliveryCents): array
    {
        $offer = $this->active();
        $deliveryCents = max(0, $deliveryCents);
        $eligible = $offer && $subtotalCents > 0 && max(0, $subtotalCents - $discountCents) >= $offer['minimum_cents'];

        return [
            'original_delivery_fee' => $deliveryCents / 100,
            'shipping_discount' => $eligible ? $deliveryCents / 100 : 0,
            'delivery_fee' => $eligible ? 0 : $deliveryCents / 100,
            'free_shipping_offer' => $eligible ? $offer : null,
        ];
    }
}
