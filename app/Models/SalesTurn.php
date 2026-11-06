<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTurn extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['request_text', 'result', 'capability_nonce'];

    protected $casts = ['request_text' => 'encrypted', 'result' => 'encrypted:array', 'allowed_items' => 'array',
        'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
}
