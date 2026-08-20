<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CustomerAddress extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = ['is_default' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(function (CustomerAddress $address): void {
            $address->uuid ??= (string) Str::uuid();
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
}
