<?php

namespace App\Models;

use App\Support\Seo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductVariant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'attributes' => 'array',
        'gallery_images' => 'array',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class, 'product_variant_id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        if (str_starts_with($this->image, 'http')) {
            return Seo::imageUrl($this->image);
        }

        return Seo::imageUrl(Storage::disk('public')->url($this->image));
    }

    public function getGalleryImageUrlsAttribute(): array
    {
        return collect($this->gallery_images ?? [])
            ->map(function (string $image): string {
                if (str_starts_with($image, 'http')) {
                    return Seo::imageUrl($image);
                }

                return Seo::imageUrl(Storage::disk('public')->url($image));
            })
            ->values()
            ->all();
    }

    public function getAllImageUrlsAttribute(): array
    {
        return collect([$this->image_url, ...$this->gallery_image_urls])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
