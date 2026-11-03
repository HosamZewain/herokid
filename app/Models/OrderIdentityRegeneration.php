<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderIdentityRegeneration extends Model
{
    protected $guarded = [];

    protected $hidden = ['context'];

    protected $casts = ['context' => 'encrypted:array', 'lease_until' => 'datetime'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function attempt()
    {
        return $this->belongsTo(ChildIdentityGenerationAttempt::class, 'attempt_id');
    }
}
