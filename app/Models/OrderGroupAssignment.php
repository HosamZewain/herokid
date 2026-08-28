<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderGroupAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime'];
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
