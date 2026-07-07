<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SceneGenerationJob extends Model
{
    protected $guarded = [];

    protected $casts = [
        'input_assets_json' => 'array',
        'provider_request_json' => 'array',
        'output_metadata_json' => 'array',
        'provider_response_json' => 'array',
        'estimated_cost' => 'decimal:4',
        'actual_cost' => 'decimal:4',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function scene()
    {
        return $this->belongsTo(ProductionScene::class, 'production_scene_id');
    }

    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function initiator()
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function assets()
    {
        return $this->hasMany(ProductionProjectAsset::class);
    }
}
