<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderAutomation extends Model
{
    protected $guarded = [];

    protected $casts = ['draft' => 'array', 'enabled' => 'boolean', 'accepts_new_runs' => 'boolean'];

    public function revision()
    {
        return $this->belongsTo(OrderAutomationRevision::class, 'revision_id');
    }
}
