<?php

namespace App\Models;

use App\Support\ProductionAutomation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionAutomationStep extends Model
{
    protected $guarded = [];

    protected $casts = [
        'weight' => 'decimal:4',
        'metadata_json' => 'array',
        'validation_summary_json' => 'array',
        'heartbeat_at' => 'datetime',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ProductionAutomationRun::class, 'automation_run_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(ProductionScene::class, 'production_scene_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ProductionAutomationAttempt::class, 'automation_step_id');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(ProductionAutomationCostEntry::class, 'automation_step_id');
    }

    public function isCompleteForProgress(): bool
    {
        return in_array($this->status, ProductionAutomation::progressCompleteStepStatuses(), true);
    }
}
