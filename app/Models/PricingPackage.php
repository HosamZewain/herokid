<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingPackage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'features'    => 'array',
        'is_featured' => 'boolean',
        'active'      => 'boolean',
        'price'       => 'decimal:2',
    ];

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
