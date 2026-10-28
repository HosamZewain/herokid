<?php

namespace App\Services\Catalog;

use App\Models\PricingPackage;
use App\Models\Story;
use App\Services\Pricing\StoryPricingService;
use Illuminate\Support\Collection;

class PackageComparison
{
    /** Prices are cents throughout. Variable story pools produce an honest range. */
    public function totals(PricingPackage $package, ?Collection $stories = null): array
    {
        $package->loadMissing(['items.product', 'items.variant']);
        $stories ??= $package->availableStoriesQuery()->get();
        $pricing = new StoryPricingService;
        $prices = $stories->map(fn (Story $story) => (int) round($pricing->effectivePrice($story) * 100));
        $fixed = $package->items->sum(fn ($item) => ($item->product?->effectivePriceCents($item->variant) ?? 0) * $item->quantity);
        $minimum = $fixed + $package->story_count * ($prices->min() ?? 0);
        $maximum = $fixed + $package->story_count * ($prices->max() ?? 0);
        $price = (int) round((float) $package->price * 100);

        return ['minimum' => $minimum, 'maximum' => $maximum, 'saving' => $minimum - $price,
            'maximum_saving' => $maximum - $price,
            'percent' => $minimum > 0 ? round(($minimum - $price) * 100 / $minimum, 2) : 0];
    }

    public function sentence(PricingPackage $package, ?Collection $stories = null): string
    {
        $totals = $this->totals($package, $stories);
        $price = CustomerKnowledge::money((float) $package->price);
        $total = CustomerKnowledge::money($totals['minimum'] / 100);
        $saving = CustomerKnowledge::money($totals['saving'] / 100);
        if ($totals['minimum'] !== $totals['maximum']) {
            return 'السعر: '.$price.' ج.م. قيمة العناصر منفصلة من '.$total.' إلى '.CustomerKnowledge::money($totals['maximum'] / 100).' ج.م حسب القصص المختارة؛ فرق السعر من '.$saving.' إلى '.CustomerKnowledge::money($totals['maximum_saving'] / 100).' ج.م.';
        }
        if ($totals['saving'] <= 0) {
            return 'السعر: '.$price.' ج.م. قيمة العناصر لو اتشروا منفصلين: '.$total.' ج.م.';
        }

        return 'السعر: '.$price.' ج.م بدل '.$total.' ج.م لو اتشروا منفصلين — توفير '.$saving.' ج.م ('.CustomerKnowledge::money($totals['percent']).'%).';
    }
}
