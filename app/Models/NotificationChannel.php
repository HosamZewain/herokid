<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NotificationChannel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'settings_json' => 'array',
    ];

    public function credentials(): HasMany
    {
        return $this->hasMany(NotificationCredential::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(NotificationCredential::class);
    }
}
