<?php

namespace App\Services\Ai;

use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionScene;
use Illuminate\Support\Str;

class ProductionPromptCompiler
{
    public function compile(
        ProductionProject $project,
        ?ProductionScene $scene,
        string $jobType,
        string $stylePreset,
        ?string $manualNotes = null,
        ?ProductionProjectAsset $characterSheet = null,
    ): array {
        $project->loadMissing(['order.story', 'characterProfile']);

        $order = $project->order;
        $profile = $project->characterProfile;
        $style = config('production_studio.ai.style_presets.'.$stylePreset, config('production_studio.ai.style_presets.premium_storybook'));
        $orientation = $jobType === 'cover_image' ? 'A4 portrait cover composition' : 'A3 landscape two-page story spread composition';

        $lines = [
            'Create an original Hero Kid children book illustration.',
            'Visual style: '.$style,
            'Output framing: '.$orientation.'. Generate a practical preview image, not final print-ready layout.',
            'Child name: '.($order->child_name ?: 'Not available'),
            'Child age for visual appearance: '.($order->child_age ?: 'Not available'),
            'Child gender: '.($order->child_gender ?: 'Not available'),
            'Selected story: '.($order->story?->title ?: 'Not available'),
            'Selected story age range: '.($order->story?->age_range ?: 'Not available'),
            'Child appearance summary: '.($profile?->appearance_summary ?: 'Not available'),
            'Hair details: '.($profile?->hair_details ?: 'Not available'),
            'Skin tone: '.($profile?->skin_tone ?: 'Not available'),
            'Visible facial traits: '.($profile?->eye_color_traits ?: 'Not available'),
            'Typical expression: '.($profile?->typical_expression ?: 'Not available'),
            'Identity rules: '.($profile?->identity_rules ?: 'Preserve the child identity from approved references as much as possible.'),
            'Wardrobe direction: '.($profile?->wardrobe_direction ?: 'Premium child-friendly outfit suitable for the scene.'),
            'Approved visual style notes: '.($profile?->approved_visual_style ?: 'Consistent premium storybook art direction.'),
        ];

        if ($characterSheet) {
            $lines[] = 'Use the approved character sheet as the primary visual identity reference.';
        }

        if ($scene) {
            $lines = array_merge($lines, [
                'Scene number: '.$scene->scene_number,
                'Scene title: '.($scene->title ?: 'Not available'),
                'Scene story text context: '.($scene->story_text ?: 'Not available'),
                'Scene visual direction: '.($scene->visual_direction ?: 'Not available'),
                'Child action / pose: '.($scene->child_action_pose ?: 'Not available'),
                'Educational value / behavior: '.($scene->educational_value ?: 'Not available'),
                'Safe text area requirement: '.($scene->text_safe_area_notes ?: 'Reserve a calm low-detail area for future Arabic text.'),
            ]);
        } elseif ($jobType === 'character_sheet') {
            $lines[] = 'Character sheet requirements: single child only, neutral friendly pose, front-facing or 3/4 pose, simple clean background, no text.';
        } elseif ($jobType === 'cover_image') {
            $lines[] = 'Cover requirements: premium front-cover composition, strong focal point, no readable text inside the image, leave clean title space.';
        }

        if (filled($manualNotes)) {
            $lines[] = 'Additional production notes from admin: '.Str::squish((string) $manualNotes);
        }

        $lines[] = 'Maintain visual consistency, warm emotional tone, clean lighting, print-friendly detail, and one clear focal point.';
        $lines[] = 'Do not include text, logos, watermarks, brand marks, copyrighted characters, franchise costumes, or confusingly similar worlds.';
        $lines[] = 'Do not add extra children unless explicitly requested. Do not distort the child face or change apparent age.';

        $negative = implode(', ', array_filter([
            'text',
            'Arabic text',
            'logos',
            'watermarks',
            'copyrighted characters',
            'famous franchise costumes',
            'extra children',
            'distorted face',
            'unsafe content',
            'scary content',
            $profile?->negative_instructions,
        ]));

        return [
            'prompt' => implode("\n", $lines),
            'negative_prompt' => $negative,
        ];
    }
}
