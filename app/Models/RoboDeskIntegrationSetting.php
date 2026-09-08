<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoboDeskIntegrationSetting extends Model
{
    protected $table = 'robodesk_integration_settings';

    protected $guarded = [];

    protected $hidden = ['encrypted_token'];

    protected $casts = [
        'is_enabled' => 'boolean',
        'encrypted_token' => 'encrypted',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
