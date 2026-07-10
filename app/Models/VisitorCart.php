<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitorCart extends Model
{
    protected $guarded = [];

    protected $casts = [
        'first_added_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'checkout_started_at' => 'datetime',
        'converted_at' => 'datetime',
        'abandoned_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function relatedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'related_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VisitorCartItem::class);
    }

    public function activeItems(): HasMany
    {
        return $this->items()->whereNull('removed_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(VisitorCartActivity::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->user?->name ?: 'زائر غير مسجل';
    }

    public function getTotalAttribute(): float
    {
        return ((int) $this->cart_total_cents) / 100;
    }
}
