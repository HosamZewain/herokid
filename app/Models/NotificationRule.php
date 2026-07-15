<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'recipients_json' => 'array',
        'thresholds_json' => 'array',
    ];
}
