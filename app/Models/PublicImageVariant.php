<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicImageVariant extends Model
{
    protected $guarded = [];

    protected $casts = ['variants' => 'array'];
}
