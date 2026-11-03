<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiMemoryVersion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['snapshot' => 'encrypted:array', 'change_reason' => 'encrypted', 'revision' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('memory_history_is_immutable'));
        static::deleting(fn () => throw new \LogicException('memory_history_is_immutable'));
    }
}
