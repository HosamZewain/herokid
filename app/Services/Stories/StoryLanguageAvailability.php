<?php

namespace App\Services\Stories;

use App\Models\Story;

class StoryLanguageAvailability
{
    public function english(Story $story): bool
    {
        $scenes = $story->sceneTemplates()->orderBy('scene_number')->get();

        return $scenes->pluck('scene_number')->map(fn ($number) => (int) $number)->all() === range(1, 13)
            && $scenes->every(fn ($scene) => filled($scene->english_male_text_template) && filled($scene->english_female_text_template));
    }
}
