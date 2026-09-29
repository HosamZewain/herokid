<?php

namespace App\Models;

use App\Support\Seo;
use Carbon\CarbonInterface;
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
        'personalization_fields' => 'array',
        'sale_starts_at' => 'datetime',
        'sale_ends_at' => 'datetime',
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

    public function productionComponents()
    {
        return $this->hasMany(ProductProductionComponent::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function activeProductionComponents()
    {
        return $this->hasMany(ProductProductionComponent::class)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('category', fn (Builder $category) => $category
                ->where('is_active', true)
                ->where('show_in_store', true));
    }

    public function scopeWithProductionPrompt(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->whereNotNull('production_prompt_template')
                    ->where('production_prompt_template', '!=', '');
            })->orWhereHas('productionComponents', fn (Builder $components) => $components
                ->where('is_active', true)
                ->where('prompt_template', '!=', ''));
        });
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

    public function hasActiveSale(?CarbonInterface $at = null): bool
    {
        if ($this->sale_price_cents === null || (int) $this->sale_price_cents >= (int) $this->price_cents) {
            return false;
        }

        $at ??= now();

        return (! $this->sale_starts_at || $this->sale_starts_at->lte($at))
            && (! $this->sale_ends_at || $this->sale_ends_at->gt($at));
    }

    public function regularPriceCents(?ProductVariant $variant = null): int
    {
        if ($variant?->price_override_cents !== null) {
            return max(0, (int) $variant->price_override_cents);
        }

        return max(0, (int) ($this->price_cents ?? 0) + (int) ($variant?->price_adjustment_cents ?? 0));
    }

    public function hasActiveSaleForVariant(?ProductVariant $variant = null, ?CarbonInterface $at = null): bool
    {
        if ($variant?->price_override_cents !== null || ! $this->hasActiveSale($at)) {
            return false;
        }

        return $this->salePriceCents($variant) < $this->regularPriceCents($variant);
    }

    public function salePriceCents(?ProductVariant $variant = null): int
    {
        return max(0, (int) ($this->sale_price_cents ?? $this->price_cents ?? 0) + (int) ($variant?->price_adjustment_cents ?? 0));
    }

    public function effectivePriceCents(?ProductVariant $variant = null): int
    {
        if ($this->hasActiveSaleForVariant($variant)) {
            return $this->salePriceCents($variant);
        }

        return $this->regularPriceCents($variant);
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

        return Seo::imageUrl(Storage::disk((string) config('media.public_disk', 'public'))->url($this->featured_image));
    }
}
