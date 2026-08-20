<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileAnalyticsEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['properties' => 'array', 'occurred_at' => 'datetime', 'received_at' => 'datetime'];
}
