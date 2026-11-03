<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAgentRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['snapshot' => 'encrypted:array', 'provider_metadata' => 'array', 'context_version' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function decision()
    {
        return $this->hasOne(AiOrderDecision::class, 'agent_run_id');
    }
}
