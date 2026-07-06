<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    protected $guarded = [];

    protected $casts = [
        'generation_capabilities_json' => 'array',
        'estimated_cost_per_output' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }
}
