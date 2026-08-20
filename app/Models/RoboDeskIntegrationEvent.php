<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoboDeskIntegrationEvent extends Model
{
    protected $table = 'robodesk_integration_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'response_payload' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
