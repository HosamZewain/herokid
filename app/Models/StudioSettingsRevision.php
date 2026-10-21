<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioSettingsRevision extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['settings' => 'array', 'assets' => 'array', 'created_at' => 'datetime'];
}
