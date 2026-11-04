<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AiOrderDecision extends Model
{
    protected $guarded = [];

    protected $casts = ['decision' => 'array', 'policy' => 'array', 'simulation' => 'array'];

    public function execution()
    {
        return $this->hasOne(AiProductionExecution::class, 'decision_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Retain decision audit.'));
    }
}
