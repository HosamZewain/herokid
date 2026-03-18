<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        // Already a full external URL (e.g. seeder placeholder images)
        if (str_starts_with($this->cover_image, 'http')) {
            return $this->cover_image;
        }
        // Use Storage::url() so the URL respects the disk driver (local OR S3/cloud)
        // and is always consistent with where the file was actually stored.
        return Storage::disk('public')->url($this->cover_image);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function attachments()
    {
        return $this->hasMany(StoryAttachment::class)->latest();
    }

    public function categories()
    {
        return $this->belongsToMany(StoryCategory::class, 'story_story_category');
    }
}
