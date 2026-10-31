<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProcessStep extends Model
{
    protected $guarded = [];

    protected $hidden = ['receipt'];

    protected $casts = ['receipt' => 'encrypted:array', 'started_at' => 'immutable_datetime', 'lease_until' => 'datetime', 'completed_at' => 'datetime'];

    public function messageAttempts()
    {
        return $this->hasMany(OrderProcessMessageAttempt::class, 'step_id');
    }

    public function run()
    {
        return $this->belongsTo(OrderProcessRun::class, 'run_id');
    }
}
