<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobileOtpChallenge extends Model
{
    protected $guarded = [];

    protected $hidden = ['phone_encrypted', 'phone_hash', 'code_hash', 'request_ip_hash'];

    protected $casts = [
        'phone_encrypted' => 'encrypted',
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileOtpChallenge $challenge): void {
            $challenge->uuid ??= (string) Str::uuid();
        });
    }
}
