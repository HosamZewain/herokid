<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Admission receipt, never an assertion that production has finished. */
class AiProductionExecution extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Production admission receipts are immutable.'));
        static::deleting(fn () => throw new LogicException('Retain production admission audit.'));
    }
}
