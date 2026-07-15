<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationCredential extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'encrypted_value',
    ];

    protected $casts = [
        'encrypted_value' => 'encrypted',
        'configured_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }
}
