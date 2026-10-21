<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioCoverGeneration extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['reference_path', 'output_path'];

    protected $casts = ['output' => 'array'];
}
