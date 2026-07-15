<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationEventLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'context_json' => 'array',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
