<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductProductionSpec extends Model
{
    protected $guarded = [];

    protected $casts = ['definition' => 'array', 'version' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Publish a new specification version.'));
        static::deleting(fn () => throw new LogicException('Retain referenced specifications.'));
    }
}
