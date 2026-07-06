<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionStoryVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'educational_values_json' => 'array',
        'approved_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(ProductionProject::class, 'production_project_id');
    }

    public function scenes()
    {
        return $this->hasMany(ProductionScene::class)->orderBy('scene_number');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
