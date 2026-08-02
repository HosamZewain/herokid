<?php

namespace App\Services\Pricing;

class PackagePricingService
{
    /** @return array<int, int> */
    public function allocate(int $packagePriceCents, array $componentPriceCents): array
    {
        $packagePriceCents = max(0, $packagePriceCents);
        $weights = array_map(fn ($value): int => max(0, (int) $value), array_values($componentPriceCents));

        if ($weights === []) {
            return [];
        }

        $weightTotal = array_sum($weights);
        $remaining = $packagePriceCents;
        $allocations = [];
        $lastIndex = array_key_last($weights);

        foreach ($weights as $index => $weight) {
            $allocated = $index === $lastIndex
                ? $remaining
                : ($weightTotal > 0 ? (int) floor($packagePriceCents * ($weight / $weightTotal)) : 0);
            $allocations[] = $allocated;
            $remaining -= $allocated;
        }

        return $allocations;
    }
}
