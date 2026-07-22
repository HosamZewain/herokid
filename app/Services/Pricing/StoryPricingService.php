<?php

namespace App\Services\Pricing;

use App\Models\Setting;
use App\Models\Story;

class StoryPricingService
{
    /** @var array<string, string|null>|null */
    private ?array $settings = null;

    public function usesGlobalPrice(): bool
    {
        return ($this->settings()['story_global_price_enabled'] ?? '0') === '1';
    }

    public function regularPrice(Story $story): float
    {
        if (! $this->usesGlobalPrice()) {
            return max(0, (float) $story->price);
        }

        return max(0, (float) ($this->settings()['story_regular_price'] ?? 399));
    }

    public function hasActiveOffer(Story $story): bool
    {
        if (! $this->usesGlobalPrice() || ($this->settings()['story_offer_enabled'] ?? '0') !== '1') {
            return false;
        }

        $offerPrice = (float) ($this->settings()['story_offer_price'] ?? 0);

        return $offerPrice > 0 && $offerPrice < $this->regularPrice($story);
    }

    public function effectivePrice(Story $story): float
    {
        return $this->hasActiveOffer($story)
            ? (float) ($this->settings()['story_offer_price'] ?? 0)
            : $this->regularPrice($story);
    }

    public function offerLabel(): string
    {
        return trim((string) ($this->settings()['story_offer_label'] ?? 'عرض خاص')) ?: 'عرض خاص';
    }

    /** @return array{regular_price: float, effective_price: float, offer_applied: bool, offer_label: ?string} */
    public function snapshot(Story $story): array
    {
        $offerApplied = $this->hasActiveOffer($story);

        return [
            'regular_price' => $this->regularPrice($story),
            'effective_price' => $this->effectivePrice($story),
            'offer_applied' => $offerApplied,
            'offer_label' => $offerApplied ? $this->offerLabel() : null,
        ];
    }

    /** @return array<string, string|null> */
    private function settings(): array
    {
        return $this->settings ??= Setting::query()
            ->whereIn('key', [
                'story_global_price_enabled',
                'story_regular_price',
                'story_offer_enabled',
                'story_offer_price',
                'story_offer_label',
            ])
            ->pluck('value', 'key')
            ->all();
    }
}
