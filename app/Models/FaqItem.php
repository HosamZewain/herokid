<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaqItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
    ];
}
