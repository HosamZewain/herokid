<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCsatResponse extends Model
{
    protected $guarded = [];

    protected $casts = [
        'score' => 'integer',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
