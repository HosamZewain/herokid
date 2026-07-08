<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiProviderCredential extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'encrypted_value',
    ];

    protected $casts = [
        'encrypted_value' => 'encrypted',
        'configured_at' => 'datetime',
        'last_tested_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }
}
