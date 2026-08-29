<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderGroupMergeAlias extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'removed_delivery_fee_cents' => 'integer',
            'metadata' => 'array',
            'merged_at' => 'datetime',
        ];
    }
}
