<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProcessMessageAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = ['receipt'];

    protected $casts = ['receipt' => 'encrypted:array', 'started_at' => 'immutable_datetime', 'completed_at' => 'datetime'];

    public function step()
    {
        return $this->belongsTo(OrderProcessStep::class, 'step_id');
    }
}
