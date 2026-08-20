<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MobileDraft extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['payload' => 'encrypted:array', 'last_activity_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (MobileDraft $draft): void {
            $draft->uuid ??= (string) Str::uuid();
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
