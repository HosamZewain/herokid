<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MobileUpload extends Model
{
    protected $guarded = [];

    protected $hidden = ['disk', 'path'];

    protected $casts = [
        'chunks' => 'array',
        'expected_size' => 'integer',
        'received_size' => 'integer',
        'chunk_size' => 'integer',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MobileUpload $upload): void {
            $upload->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function childProfile(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class);
    }
}
