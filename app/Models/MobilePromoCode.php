<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobilePromoCode extends Model
{
    protected $guarded = [];

    protected $casts = [
        'discount_value' => 'integer',
        'minimum_subtotal_cents' => 'integer',
        'maximum_discount_cents' => 'integer',
        'usage_limit' => 'integer',
        'per_user_limit' => 'integer',
        'used_count' => 'integer',
        'is_active' => 'boolean',
        'website_enabled' => 'boolean',
        'mobile_enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function websiteRedemptions()
    {
        return $this->hasMany(WebsitePromoCodeRedemption::class);
    }

    public function isAvailableFor(string $channel): bool
    {
        $channelEnabled = match ($channel) {
            'website' => $this->website_enabled,
            'mobile' => $this->mobile_enabled,
            default => false,
        };

        return $this->is_active
            && $channelEnabled
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture())
            && ($this->usage_limit === null || $this->used_count < $this->usage_limit);
    }

    public function discountFor(int $subtotalCents): int
    {
        $discount = $this->discount_type === 'percent'
            ? (int) floor($subtotalCents * min(10000, $this->discount_value) / 10000)
            : $this->discount_value;

        if ($this->maximum_discount_cents !== null) {
            $discount = min($discount, $this->maximum_discount_cents);
        }

        return min($subtotalCents, max(0, $discount));
    }
}
