<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductProductionComponent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_per_item' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'studio_enabled' => 'boolean',
        'studio_recipe_version' => 'integer',
        'studio_recipe' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItemSnapshots()
    {
        return $this->hasMany(OrderItemProductionComponent::class);
    }
}
