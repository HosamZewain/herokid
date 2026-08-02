<?php

namespace App\Models;

use App\Support\Seo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        if (! $this->cover_image) {
            return null;
        }
        // Already a full external URL (e.g. seeder placeholder images)
        if (str_starts_with($this->cover_image, 'http')) {
            return Seo::imageUrl($this->cover_image);
        }

        // Use Storage::url() so the URL respects the disk driver (local OR S3/cloud)
        // and is always consistent with where the file was actually stored.
        return Seo::imageUrl(Storage::disk('public')->url($this->cover_image));
    }

    public function getSeoDescriptionAttribute(): string
    {
        $title = $this->cleanSeoText((string) $this->title);
        $ageRange = $this->cleanSeoText((string) $this->age_range);
        $lesson = $this->cleanSeoText((string) $this->lesson_value);

        $description = 'في قصة '.$title.' يصبح طفلك بطل الحكاية باسمه ووجهه المخصص';

        if ($ageRange !== '') {
            $description .= '، للأعمار '.$ageRange;
        }

        if ($lesson !== '') {
            $description .= '، مع مغزى يعزز '.$lesson;
        } else {
            $description .= '، مع تجربة عاطفية وتربوية تمنحه الثقة والخيال';
        }

        $description .= ' في كتاب مطبوع من HeroKid.';

        if (mb_strlen($description) < 110) {
            $description .= ' هدية شخصية تصله كذكرى يحبها الأهل والطفل.';
        }

        if (mb_strlen($description) > 155) {
            $description = rtrim(mb_substr($description, 0, 152), " \t\n\r\0\x0B،.").'.';
        }

        return $description;
    }

    private function cleanSeoText(string $value): string
    {
        return Str::squish(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function views()
    {
        return $this->hasMany(CustomerStoryView::class);
    }

    public function attachments()
    {
        return $this->hasMany(StoryAttachment::class)->latest();
    }

    public function bookletPreviews()
    {
        return $this->hasMany(BookletPreview::class);
    }

    public function publicBookletPreview()
    {
        return $this->hasOne(BookletPreview::class)
            ->where('show_on_story', true)
            ->where('status', 'active')
            ->latestOfMany();
    }

    public function categories()
    {
        return $this->belongsToMany(StoryCategory::class, 'story_story_category');
    }

    public function sceneTemplates()
    {
        return $this->hasMany(StorySceneTemplate::class)->orderBy('scene_number');
    }
}
