<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobileCheckoutAttempt extends Model
{
    protected $guarded = [];

    protected $hidden = ['safe_error_message'];

    protected $casts = [
        'response_payload' => 'encrypted:array',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileCheckoutAttempt $attempt): void {
            $attempt->uuid ??= (string) Str::uuid();
        });
    }
}
