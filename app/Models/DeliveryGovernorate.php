<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryGovernorate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'delivery_fee' => 'float',
        'active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(DeliveryCountry::class, 'delivery_country_id');
    }

    public function effectiveDeliveryFee(): float
    {
        return (float) ($this->delivery_fee ?? $this->country?->delivery_fee ?? 0);
    }
}
