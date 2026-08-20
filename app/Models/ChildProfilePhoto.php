<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChildProfilePhoto extends Model
{
    protected $guarded = [];

    protected $hidden = ['disk', 'path'];

    protected $casts = [
        'reuse_consent_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChildProfilePhoto $photo): void {
            $photo->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function childProfile(): BelongsTo
    {
        return $this->belongsTo(ChildProfile::class);
    }

    public function mobileUpload(): BelongsTo
    {
        return $this->belongsTo(MobileUpload::class);
    }
}
