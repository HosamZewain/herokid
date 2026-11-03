<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderShippingMergeRequest extends Model
{
    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime'];

    public function sourceOrder()
    {
        return $this->belongsTo(Order::class, 'source_order_id')->withTrashed();
    }

    public function targetOrder()
    {
        return $this->belongsTo(Order::class, 'target_order_id')->withTrashed();
    }
}
