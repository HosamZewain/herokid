<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PrivacyRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reason' => 'encrypted',
        'scope' => 'array',
        'requested_at' => 'datetime',
        'due_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PrivacyRequest $request): void {
            $request->uuid ??= (string) Str::uuid();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
