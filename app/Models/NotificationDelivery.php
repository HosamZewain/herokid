<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NotificationDelivery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload_json' => 'array',
        'response_json' => 'array',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
