<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class PricingPackage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'features' => 'array',
        'is_featured' => 'boolean',
        'active' => 'boolean',
        'price' => 'decimal:2',
        'regular_price' => 'decimal:2',
        'story_count' => 'integer',
        'applies_to_all_stories' => 'boolean',
        'show_in_store' => 'boolean',
        'show_on_homepage' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    public function scopePurchasable(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder->where('story_count', '>', 0)->orWhereHas('items');
        });
    }

    public function items()
    {
        return $this->hasMany(PricingPackageProduct::class)->orderBy('sort_order')->orderBy('id');
    }

    public function eligibleStories()
    {
        return $this->belongsToMany(Story::class, 'pricing_package_story')->withTimestamps();
    }

    public function views(): HasMany
    {
        return $this->hasMany(CustomerPackageView::class);
    }

    public function availableStoriesQuery(): Builder
    {
        return Story::query()
            ->where('active', true)
            ->when(
                ! $this->applies_to_all_stories,
                fn (Builder $query): Builder => $query->whereIn(
                    'stories.id',
                    $this->eligibleStories()->select('stories.id'),
                ),
            );
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        if (str_starts_with($this->image_path, 'http://') || str_starts_with($this->image_path, 'https://')) {
            return $this->image_path;
        }

        if (str_starts_with($this->image_path, 'images/')) {
            return asset($this->image_path);
        }

        return Storage::disk('public')->url($this->image_path);
    }

    public function discountPercentage(): ?int
    {
        $regular = (float) $this->regular_price;
        $price = (float) $this->price;

        if ($regular <= 0 || $price >= $regular) {
            return null;
        }

        return (int) round((($regular - $price) / $regular) * 100);
    }

    public function componentSummary(): string
    {
        $parts = [];

        if ($this->story_count > 0) {
            $parts[] = $this->story_count === 1 ? 'قصة واحدة' : $this->story_count.' قصص';
        }

        foreach ($this->items as $item) {
            $parts[] = ($item->quantity > 1 ? $item->quantity.' × ' : '').($item->product?->name_ar ?? 'منتج');
        }

        return implode(' + ', $parts);
    }

    public function availableForPurchase(): bool
    {
        $this->loadMissing(['items.product', 'items.variant', 'eligibleStories']);

        return $this->active
            && ($this->story_count > 0 || $this->items->isNotEmpty())
            && ($this->story_count === 0
                || $this->applies_to_all_stories
                || $this->eligibleStories->contains(fn (Story $story): bool => $story->active))
            && $this->items->every(fn (PricingPackageProduct $item): bool => (bool) $item->product?->is_active
                && (! $item->product_variant_id || (bool) $item->variant?->is_active)
                && $item->product->hasStock($item->quantity, $item->variant));
    }
}
