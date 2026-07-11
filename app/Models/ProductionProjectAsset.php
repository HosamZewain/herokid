<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionProjectAsset extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
        'is_primary' => 'boolean',
        'is_final' => 'boolean',
        'reviewed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function scene()
    {
        return $this->belongsTo(ProductionScene::class, 'production_scene_id');
    }

    public function generationJob()
    {
        return $this->belongsTo(SceneGenerationJob::class, 'scene_generation_job_id');
    }

    public function automationRun()
    {
        return $this->belongsTo(ProductionAutomationRun::class, 'production_automation_run_id');
    }

    public function automationStep()
    {
        return $this->belongsTo(ProductionAutomationStep::class, 'production_automation_step_id');
    }

    public function automationAttempt()
    {
        return $this->belongsTo(ProductionAutomationAttempt::class, 'production_automation_attempt_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
