<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MobileCart extends Model
{
    protected $guarded = [];

    protected $casts = [
        'subtotal_cents' => 'integer',
        'discount_cents' => 'integer',
        'delivery_cents' => 'integer',
        'total_cents' => 'integer',
        'last_activity_at' => 'datetime',
        'converted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileCart $cart): void {
            $cart->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MobileCartItem::class)->orderBy('id');
    }

    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(MobilePromoCode::class, 'mobile_promo_code_id');
    }
}
