<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProcessIdentity extends Model
{
    protected $guarded = [];

    protected $hidden = ['context'];

    protected $casts = ['context' => 'encrypted:array', 'lease_until' => 'datetime'];

    public function run()
    {
        return $this->belongsTo(OrderProcessRun::class, 'run_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function attempt()
    {
        return $this->belongsTo(ChildIdentityGenerationAttempt::class, 'attempt_id');
    }
}
