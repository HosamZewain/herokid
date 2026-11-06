<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesConversation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $attributes = ['upsell_declined' => false];

    protected $hidden = ['session_hash', 'visitor_hash'];

    protected $casts = ['upsell_declined' => 'boolean'];
}
