<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MobileCartItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_price_cents' => 'integer',
        'quantity' => 'integer',
        'total_price_cents' => 'integer',
        'personalization' => 'encrypted:array',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileCartItem $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(MobileCart::class, 'mobile_cart_id');
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

    public function childProfile(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class);
    }

    public function childIdentityRequest(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityRequest::class);
    }

    public function linkedItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_mobile_cart_item_id');
    }
}
