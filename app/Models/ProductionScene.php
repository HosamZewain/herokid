<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionScene extends Model
{
    protected $guarded = [];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function storyVersion()
    {
        return $this->belongsTo(ProductionStoryVersion::class, 'production_story_version_id');
    }

    public function generationJobs()
    {
        return $this->hasMany(SceneGenerationJob::class);
    }

    public function assets()
    {
        return $this->hasMany(ProductionProjectAsset::class);
    }

    public function approvedFinalImage()
    {
        return $this->hasOne(ProductionProjectAsset::class)
            ->where('asset_type', 'scene_image')
            ->where('is_final', true);
    }
}
