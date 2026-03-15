<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Story extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gallery_images' => 'array',
        'active' => 'boolean',
    ];

    /**
     * Returns the full displayable URL for the cover image.
     * Handles: external http(s) URLs, or local storage paths.
     */
    public function getCoverUrlAttribute(): ?string
    {
        if (!$this->cover_image) {
            return null;
        }
        if (str_starts_with($this->cover_image, 'http')) {
            return $this->cover_image;
        }
        return asset('storage/' . $this->cover_image);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function categories()
    {
        return $this->belongsToMany(StoryCategory::class, 'story_story_category');
    }
}
