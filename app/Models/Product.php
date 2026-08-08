<?php

namespace App\Models;

use App\Support\Seo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gallery_images' => 'array',
        'age_groups' => 'array',
        'features' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function views()
    {
        return $this->hasMany(CustomerProductView::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeVariants()
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('category', fn (Builder $category) => $category
                ->where('is_active', true)
                ->where('show_in_store', true));
    }

    public function scopeForAgeGroup(Builder $query, ?string $ageGroup): Builder
    {
        if (! $ageGroup) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($ageGroup) {
            $builder->whereNull('age_groups')
                ->orWhereJsonLength('age_groups', 0)
                ->orWhereJsonContains('age_groups', $ageGroup);
        });
    }

    public function effectivePriceCents(?ProductVariant $variant = null): int
    {
        $base = (int) ($this->sale_price_cents ?? $this->price_cents ?? 0);

        if (! $variant) {
            return max(0, $base);
        }

        if ($variant->price_override_cents !== null) {
            return max(0, (int) $variant->price_override_cents);
        }

        return max(0, $base + (int) $variant->price_adjustment_cents);
    }

    public function effectivePrice(): float
    {
        return $this->effectivePriceCents() / 100;
    }

    public function isPersonalizedAddon(): bool
    {
        return $this->personalization_mode === 'inherit_from_linked_story'
            || $this->purchase_mode === 'add_on_only';
    }

    public function hasStock(int $quantity = 1, ?ProductVariant $variant = null): bool
    {
        if ($this->inventory_mode !== 'track_stock') {
            return true;
        }

        $available = $variant?->stock_quantity ?? $this->stock_quantity;

        return $available === null || $available >= $quantity;
    }

    public function ageLabel(): string
    {
        $groups = $this->age_groups ?? [];

        return $groups === [] ? 'كل الأعمار' : implode('، ', array_map('format_age_range', $groups));
    }

    public function getFeaturedImageUrlAttribute(): ?string
    {
        if (! $this->featured_image) {
            return null;
        }

        if (str_starts_with($this->featured_image, 'http')) {
            return Seo::imageUrl($this->featured_image);
        }

        return Seo::imageUrl(Storage::disk('public')->url($this->featured_image));
    }
}
