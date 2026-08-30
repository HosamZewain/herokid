<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;

class ProductVariantSnapshot
{
    public static function make(Product $product, ?ProductVariant $variant): ?array
    {
        if (! $variant) {
            return null;
        }

        return [
            'id' => $variant->id,
            'name_ar' => $variant->name_ar,
            'name_en' => $variant->name_en,
            'sku' => $variant->sku,
            'image' => $variant->image,
            'image_url' => $variant->image_url,
            'gallery_images' => $variant->gallery_images ?? [],
            'gallery_image_urls' => $variant->gallery_image_urls,
            'attributes' => $variant->attributes ?? [],
            'price_adjustment_cents' => (int) $variant->price_adjustment_cents,
            'price_override_cents' => $variant->price_override_cents !== null
                ? (int) $variant->price_override_cents
                : null,
            'effective_price_cents' => $product->effectivePriceCents($variant),
        ];
    }

    public static function title(Product $product, ?ProductVariant $variant): string
    {
        return $variant
            ? $product->name_ar.' — '.$variant->name_ar
            : $product->name_ar;
    }

    public static function imagePath(Product $product, ?ProductVariant $variant): ?string
    {
        return $variant?->image
            ?: ($variant?->gallery_images[0] ?? null)
            ?: $product->featured_image;
    }

    public static function imageUrl(Product $product, ?ProductVariant $variant): ?string
    {
        return $variant?->all_image_urls[0] ?? $product->featured_image_url;
    }
}
