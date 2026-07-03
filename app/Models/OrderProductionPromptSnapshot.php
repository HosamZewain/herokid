<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProductionPromptSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'template_updated_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
