<?php

namespace App\ViewModels\Catalog;

use Illuminate\Database\Eloquent\Model;

final readonly class UnifiedCatalogItem
{
    /**
     * @param  array<int, string>  $ageValues
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public string $id,
        public string $type,
        public Model $sourceModel,
        public string $title,
        public string $slug,
        public string $description,
        public string $shortDescription,
        public ?string $imageUrl,
        public float $price,
        public string $priceLabel,
        public string $ageRange,
        public array $ageValues,
        public ?string $category,
        public ?string $categorySlug,
        public string $categorySource,
        public array $tags,
        public string $personalizationType,
        public string $personalizationLabel,
        public bool $isFeatured,
        public int $sortOrder,
        public string $detailUrl,
        public string $ctaLabel,
        public string $badgeLabel,
        public string $searchableText,
        public string $section,
        public int $createdTimestamp,
        public ?float $originalPrice = null,
        public ?string $originalPriceLabel = null,
        public ?string $offerLabel = null,
    ) {}
}
