<?php

namespace App\Services\Mobile;

use App\ViewModels\Catalog\UnifiedCatalogItem;

class MobileCatalogPresenter
{
    public function item(UnifiedCatalogItem $item): array
    {
        return [
            'id' => $item->id,
            'type' => $item->type,
            'slug' => $item->slug,
            'title' => $item->title,
            'description' => $item->description,
            'short_description' => $item->shortDescription,
            'image_url' => $item->imageUrl,
            'price' => [
                'amount' => $item->price,
                'formatted' => $item->priceLabel,
                'original_amount' => $item->originalPrice,
                'original_formatted' => $item->originalPriceLabel,
                'offer_label' => $item->offerLabel,
            ],
            'age_range' => $item->ageRange,
            'age_values' => $item->ageValues,
            'category' => [
                'name' => $item->category,
                'slug' => $item->categorySlug,
                'source' => $item->categorySource,
            ],
            'tags' => $item->tags,
            'section' => $item->section,
            'personalization' => [
                'type' => $item->personalizationType,
                'label' => $item->personalizationLabel,
            ],
            'featured' => $item->isFeatured,
            'cta_label' => $item->ctaLabel,
            'badge_label' => $item->badgeLabel,
        ];
    }
}
