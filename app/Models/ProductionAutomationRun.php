<?php

namespace App\Models;

use App\Support\ProductionAutomation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductionAutomationRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options_snapshot_json' => 'array',
        'pricing_snapshot_json' => 'array',
        'blockers_json' => 'array',
        'base_estimated_cost' => 'decimal:4',
        'retry_exposure_estimate' => 'decimal:4',
        'hard_budget' => 'decimal:4',
        'started_at' => 'datetime',
        'paused_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'files_ready_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_transition_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function activeProject(): BelongsTo
    {
        return $this->belongsTo(ProductionProject::class, 'active_project_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProductionAutomationStep::class, 'automation_run_id')->orderBy('sequence');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ProductionAutomationAttempt::class, 'automation_run_id');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(ProductionAutomationCostEntry::class, 'automation_run_id');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(ProductionAutomationProof::class, 'automation_run_id');
    }

    public function currentProof(): HasOne
    {
        return $this->hasOne(ProductionAutomationProof::class, 'current_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ProductionAutomation::terminalStatuses(), true);
    }

    public function isPaused(): bool
    {
        return in_array($this->status, ProductionAutomation::pausedStatuses(), true);
    }

    public function isActive(): bool
    {
        return $this->active_project_id !== null && ! $this->isTerminal();
    }
}
