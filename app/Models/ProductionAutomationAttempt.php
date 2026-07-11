<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionAutomationAttempt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input_summary_json' => 'array',
        'validation_result_json' => 'array',
        'heartbeat_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationRun::class, 'automation_run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationStep::class, 'automation_step_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(ProductionAutomationCostEntry::class, 'attempt_id');
    }
}
