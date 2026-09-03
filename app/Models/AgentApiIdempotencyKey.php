<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentApiIdempotencyKey extends Model
{
    protected $guarded = [];

    protected $casts = [
        'response_body' => 'array',
        'response_status' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
