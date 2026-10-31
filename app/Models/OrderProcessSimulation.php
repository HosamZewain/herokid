<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProcessSimulation extends Model
{
    protected $guarded = [];

    protected $casts = ['version' => 'integer', 'fixture' => 'array', 'context' => 'array', 'trace' => 'array', 'wait_until' => 'datetime'];

    public function automation()
    {
        return $this->belongsTo(OrderAutomation::class, 'automation_id');
    }

    public function revision()
    {
        return $this->belongsTo(OrderAutomationRevision::class, 'revision_id');
    }
}
