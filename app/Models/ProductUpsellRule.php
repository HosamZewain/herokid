<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUpsellRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function targetProduct()
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function sourceStory()
    {
        return $this->belongsTo(Story::class, 'source_story_id');
    }

    public function sourceStoryCategory()
    {
        return $this->belongsTo(StoryCategory::class, 'source_story_category_id');
    }
}
