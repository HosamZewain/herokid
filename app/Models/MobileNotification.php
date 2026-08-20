<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobileNotification extends Model
{
    protected $guarded = [];

    protected $casts = ['data' => 'array', 'read_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (MobileNotification $notification): void {
            $notification->uuid ??= (string) Str::uuid();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deliveries()
    {
        return $this->hasMany(MobilePushDelivery::class);
    }
}
