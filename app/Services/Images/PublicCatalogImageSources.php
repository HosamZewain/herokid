<?php

namespace App\Services\Images;

use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Story;
use Illuminate\Database\Eloquent\Model;

class PublicCatalogImageSources
{
    public const FIELDS = [
        Story::class => ['cover_image'],
        Product::class => ['featured_image', 'gallery_images'],
        ProductVariant::class => ['image', 'gallery_images'],
        PricingPackage::class => ['image_path'],
        Setting::class => ['value'],
    ];

    public static function paths(Model $model): array
    {
        if ($model instanceof Setting && ! str_starts_with((string) $model->key, 'img_')) {
            return [];
        }
        $disk = (string) config('media.public_disk', 'public');
        $paths = [];
        foreach (self::FIELDS[$model::class] ?? [] as $field) {
            foreach ((array) $model->getAttribute($field) as $value) {
                if (! is_string($value)) {
                    continue;
                }
                $path = str_starts_with($value, 'http') || str_starts_with($value, '/')
                    ? (PublicImageSource::fromUrl($value)['path'] ?? '') : $value;
                if (PublicImageSource::allowed($disk, $path)) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }
}
