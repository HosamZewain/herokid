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
}
