<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoboDeskCredential extends Model
{
    protected $table = 'robodesk_credentials';

    protected $guarded = [];

    protected $hidden = ['encrypted_value'];

    protected $casts = [
        'encrypted_value' => 'encrypted',
        'configured_at' => 'datetime',
    ];

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }
}
