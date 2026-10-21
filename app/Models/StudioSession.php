<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioSession extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password_fingerprint'];

    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'created_at' => 'datetime'];
}
