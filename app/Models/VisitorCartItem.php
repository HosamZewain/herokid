<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitorCartItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'item_snapshot' => 'array',
        'first_added_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(VisitorCart::class, 'visitor_cart_id');
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function getTotalAttribute(): float
    {
        return ((int) $this->total_price_cents) / 100;
    }
}
