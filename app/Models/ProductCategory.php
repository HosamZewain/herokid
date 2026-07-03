<?php

namespace App\Models;

use App\Support\Seo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductCategory extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'show_in_store' => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function activeProducts()
    {
        return $this->hasMany(Product::class)->where('is_active', true);
    }

    public function getCoverUrlAttribute(): ?string
    {
        if (! $this->cover_image) {
            return null;
        }

        if (str_starts_with($this->cover_image, 'http')) {
            return Seo::imageUrl($this->cover_image);
        }

        return Seo::imageUrl(Storage::disk('public')->url($this->cover_image));
    }
}
