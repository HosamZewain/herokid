<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DeviceInstallation extends Model
{
    protected $guarded = [];

    protected $hidden = ['push_token', 'push_token_hash'];

    protected $casts = [
        'push_token' => 'encrypted',
        'marketing_notifications' => 'boolean',
        'operational_notifications' => 'boolean',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (DeviceInstallation $device): void {
            $device->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
