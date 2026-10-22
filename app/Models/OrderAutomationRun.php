<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderAutomationRun extends Model
{
    protected $guarded = [];

    protected $casts = ['context' => 'encrypted:array', 'steps' => 'array', 'dry_run' => 'boolean', 'trial' => 'boolean', 'lease_until' => 'datetime', 'next_at' => 'datetime', 'reserved_at' => 'datetime'];

    public function revision()
    {
        return $this->belongsTo(OrderAutomationRevision::class, 'revision_id');
    }

    public function automation()
    {
        return $this->belongsTo(OrderAutomation::class, 'automation_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }
}
