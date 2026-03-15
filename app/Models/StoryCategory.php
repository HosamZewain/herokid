<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoryCategory extends Model
{
    protected $guarded = [];

    public function stories()
    {
        return $this->belongsToMany(Story::class, 'story_story_category');
    }
}
