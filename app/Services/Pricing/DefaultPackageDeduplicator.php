<?php

namespace App\Services\Pricing;

use App\Models\PricingPackage;

class DefaultPackageDeduplicator
{
    /**
     * The installer used these stable slugs. Older admin-created packages used
     * Arabic-derived slugs, so matching by slug alone created duplicate offers.
     *
     * @var array<string, array{name: string, story_count: int}>
     */
    private const DEFAULT_PACKAGES = [
        'three-personalized-stories' => [
            'name' => 'باقة ٣ قصص مخصصة',
            'story_count' => 3,
        ],
        'three-stories-coloring-maze' => [
            'name' => 'باقة ٣ قصص + تلوين + متاهات',
            'story_count' => 3,
        ],
        'five-stories-coloring-maze' => [
            'name' => 'باقة ٥ قصص + تلوين + متاهات',
            'story_count' => 5,
        ],
    ];

    /** @return list<int> IDs of generated duplicates made private */
    public function deactivateGeneratedDuplicates(): array
    {
        $deactivated = [];

        foreach (self::DEFAULT_PACKAGES as $generatedSlug => $identity) {
            $generated = PricingPackage::query()->where('slug', $generatedSlug)->first();
            if (! $generated) {
                continue;
            }

            $adminPackage = $this->findAdminEquivalent(
                $identity['name'],
                $identity['story_count'],
                $generatedSlug,
            );
            if (! $adminPackage) {
                continue;
            }

            $generated->update([
                'active' => false,
                'show_in_store' => false,
                'show_on_homepage' => false,
            ]);
            $deactivated[] = $generated->id;
        }

        return $deactivated;
    }

    public function findAdminEquivalent(string $name, int $storyCount, string $generatedSlug): ?PricingPackage
    {
        return PricingPackage::query()
            ->where('name', $name)
            ->where('story_count', $storyCount)
            ->where('slug', '!=', $generatedSlug)
            ->orderBy('id')
            ->first();
    }
}
