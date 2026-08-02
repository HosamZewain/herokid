<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PricingPackage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'features' => 'array',
        'is_featured' => 'boolean',
        'active' => 'boolean',
        'price' => 'decimal:2',
        'story_count' => 'integer',
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

    public function getRouteKeyName(): string
    {
        return 'slug';
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
        $this->loadMissing(['items.product', 'items.variant']);

        return $this->active
            && ($this->story_count > 0 || $this->items->isNotEmpty())
            && $this->items->every(fn (PricingPackageProduct $item): bool => (bool) $item->product?->is_active
                && (! $item->product_variant_id || (bool) $item->variant?->is_active)
                && $item->product->hasStock($item->quantity, $item->variant));
    }
}
