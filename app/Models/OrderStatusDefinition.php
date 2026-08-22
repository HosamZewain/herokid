<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderStatusDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];
}
