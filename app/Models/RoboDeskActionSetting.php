<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoboDeskActionSetting extends Model
{
    protected $table = 'robodesk_action_settings';

    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'params' => 'array',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
