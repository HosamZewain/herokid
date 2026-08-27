<?php

namespace App\Models;

use App\Support\ProductPersonalizationSchema;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'item_snapshot' => 'array',
        'variant_snapshot' => 'array',
        'personalization_snapshot' => 'array',
        'stock_released_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function story()
    {
        return $this->belongsTo(Story::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function linkedOrderItem()
    {
        return $this->belongsTo(OrderItem::class, 'linked_order_item_id');
    }

    public function linkedAddOns()
    {
        return $this->hasMany(OrderItem::class, 'linked_order_item_id');
    }

    public function stockReleasedBy()
    {
        return $this->belongsTo(User::class, 'stock_released_by_user_id');
    }

    public function getTotalPriceAttribute(): float
    {
        return ((int) $this->total_price_cents) / 100;
    }

    /**
     * @return array<int, array{key: string, label: string, value: string}>
     */
    public function personalizationDisplayValues(): array
    {
        return ProductPersonalizationSchema::displayValues($this->personalization_snapshot ?? []);
    }
}
