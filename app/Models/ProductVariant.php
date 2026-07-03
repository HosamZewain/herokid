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
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
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
}
