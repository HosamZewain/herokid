<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryCountry extends Model
{
    protected $guarded = [];

    protected $casts = [
        'delivery_fee' => 'float',
        'active' => 'boolean',
    ];

    public function governorates(): HasMany
    {
        return $this->hasMany(DeliveryGovernorate::class);
    }

    public function activeGovernorates(): HasMany
    {
        return $this->hasMany(DeliveryGovernorate::class)->where('active', true)->orderBy('name');
    }
}
