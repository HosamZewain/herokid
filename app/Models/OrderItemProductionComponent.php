<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItemProductionComponent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity_per_item' => 'integer',
        'sort_order' => 'integer',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function sourceComponent()
    {
        return $this->belongsTo(ProductProductionComponent::class, 'product_production_component_id');
    }
}
