<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSceneTextSnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'render_context_snapshot' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function sourceTemplate()
    {
        return $this->belongsTo(StorySceneTemplate::class, 'source_story_scene_template_id');
    }
}
