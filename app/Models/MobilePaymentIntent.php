<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MobilePaymentIntent extends Model
{
    protected $guarded = [];

    protected $hidden = ['provider_payload', 'provider_reference_hash'];

    protected $casts = [
        'amount_cents' => 'integer',
        'provider_payload' => 'encrypted:array',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobilePaymentIntent $intent): void {
            $intent->uuid ??= (string) Str::uuid();
        });
    }
}
