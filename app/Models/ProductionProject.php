<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductionProject extends Model
{
    protected $guarded = [];

    protected $casts = [
        'source_snapshot_json' => 'array',
        'sent_to_studio_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function storyVersions(): HasMany
    {
        return $this->hasMany(ProductionStoryVersion::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(ProductionScene::class)->orderBy('scene_number');
    }

    public function characterProfile(): HasOne
    {
        return $this->hasOne(ProductionCharacterProfile::class);
    }

    public function qaChecks(): HasMany
    {
        return $this->hasMany(ProductionQaCheck::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ProductionProjectActivityLog::class)->latest();
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ProductionProjectAsset::class);
    }

    public function generationJobs(): HasMany
    {
        return $this->hasMany(SceneGenerationJob::class);
    }

    public function approvedCharacterSheet(): HasOne
    {
        return $this->hasOne(ProductionProjectAsset::class)
            ->where('asset_type', 'character_sheet')
            ->where('is_primary', true);
    }

    public function statusLabel(): string
    {
        return config('production_studio.statuses.'.$this->status, $this->status);
    }

    public function stageLabel(): string
    {
        return $this->current_stage
            ? config('production_studio.stages.'.$this->current_stage, $this->current_stage)
            : 'غير محدد';
    }

    public function qaProgress(): int
    {
        $checks = $this->relationLoaded('qaChecks') ? $this->qaChecks : $this->qaChecks()->get();

        if ($checks->isEmpty()) {
            return 0;
        }

        $complete = $checks->filter(fn (ProductionQaCheck $check): bool => in_array($check->result, ['pass', 'not_applicable'], true))->count();

        return (int) round(($complete / $checks->count()) * 100);
    }

    public function hasBlockingQaFailures(): bool
    {
        return $this->qaChecks()
            ->where('is_mandatory', true)
            ->where(function ($query) {
                $query->whereIn('result', ['not_reviewed', 'fail'])
                    ->where('override_allowed', false);
            })
            ->exists();
    }

    public function aiCostSummary(): array
    {
        $jobs = $this->relationLoaded('generationJobs') ? $this->generationJobs : $this->generationJobs()->get();
        $assets = $this->relationLoaded('assets') ? $this->assets : $this->assets()->get();

        return [
            'estimated' => number_format((float) $jobs->sum('estimated_cost'), 4, '.', ''),
            'actual' => number_format((float) $jobs->sum(fn (SceneGenerationJob $job): float => (float) ($job->actual_cost ?? 0)), 4, '.', ''),
            'attempts' => $jobs->count(),
            'approved' => $assets->where('status', 'approved')->count(),
            'rejected' => $assets->where('status', 'rejected')->count(),
            'failed' => $jobs->where('status', 'failed')->count(),
        ];
    }
}
