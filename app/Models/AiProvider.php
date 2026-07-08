<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiProvider extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'supports_text_to_image' => 'boolean',
        'supports_image_to_image' => 'boolean',
        'supports_editing' => 'boolean',
        'supports_upscaling' => 'boolean',
        'is_configured' => 'boolean',
        'is_available' => 'boolean',
        'capabilities_json' => 'array',
        'settings_json' => 'array',
        'last_health_check_at' => 'datetime',
    ];

    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(AiProviderCredential::class);
    }

    public function getPublicNameAttribute(): string
    {
        return $this->display_name ?: $this->name;
    }
}
