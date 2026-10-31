<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProcessRun extends Model
{
    protected $guarded = [];

    protected $hidden = ['context'];

    protected $casts = ['context' => 'encrypted:array', 'version' => 'integer', 'next_at' => 'datetime', 'wait_until' => 'datetime'];

    public function automation()
    {
        return $this->belongsTo(OrderAutomation::class, 'automation_id');
    }

    public function revision()
    {
        return $this->belongsTo(OrderAutomationRevision::class, 'revision_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function steps()
    {
        return $this->hasMany(OrderProcessStep::class, 'run_id');
    }
}
