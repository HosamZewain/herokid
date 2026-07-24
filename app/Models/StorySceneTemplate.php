<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorySceneTemplate extends Model
{
    protected $guarded = [];

    public function story()
    {
        return $this->belongsTo(Story::class);
    }

    public function orderSnapshots()
    {
        return $this->hasMany(OrderSceneTextSnapshot::class, 'source_story_scene_template_id');
    }
}
