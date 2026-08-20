<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCustomerReview extends Model
{
    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
