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
        $orientation = $jobType === 'cover_image' ? 'A4 portrait cover artwork composition' : 'A3 landscape two-page story spread preview composition';

        $lines = [
            'Production context: create an original premium children\'s book illustration. Do not render brand text or logos inside the image.',
            'Visual style: '.$style,
            'Output framing: '.$orientation.'. Generate a practical preview image, not final print-ready layout.',
            'Selected story title for context only, not visual text: '.($order->story?->title ?: 'Not available'),
            'Selected story summary: '.($order->story?->short_desc ?: $order->story?->full_desc ?: 'Not available'),
            'Educational value: '.($order->lesson ?: $order->story?->lesson_value ?: 'Not available'),
            '',
            'Child identity data:',
            '- Child name for internal context only, not visual text: '.($order->child_name ?: 'Not available'),
            '- Child age for visual appearance only: '.($order->child_age ?: 'Not available'),
            '- Child gender: '.($order->child_gender ?: 'Not available'),
            '- Appearance summary: '.($profile?->appearance_summary ?: 'Not available'),
            '- Hair details: '.($profile?->hair_details ?: 'Not available'),
            '- Skin tone: '.($profile?->skin_tone ?: 'Not available'),
            '- Eyes and visible traits: '.($profile?->eye_color_traits ?: 'Not available'),
            '- Usual expression: '.($profile?->typical_expression ?: 'Not available'),
            '- Identity rules: '.($profile?->identity_rules ?: 'Not available'),
            '- Wardrobe direction: '.($profile?->wardrobe_direction ?: 'Premium child-friendly outfit suitable for the scene.'),
            '- Approved visual style notes: '.($profile?->approved_visual_style ?: 'Consistent premium storybook art direction.'),
            '',
            'Identity fidelity is the highest priority. This is not a generic child.',
            'Preserve the exact face shape, eye shape, eye spacing, eye color, nose shape, mouth and smile shape, cheeks, jawline, hairline, hairstyle, hair texture, hair volume, skin tone, apparent age, and natural child body proportions.',
            'Do not beautify, glamorize, age up, redesign, replace, or convert the child into a different-looking character.',
            'Use the primary face reference for facial identity. Use any body reference only for body proportions. Do not mix clothing from references unless explicitly requested.',
        ];

        if ($jobType === 'character_sheet') {
            $lines = array_merge($lines, [
                '',
                'Approved Child Reference Illustration requirements:',
                'Create a clean child reference illustration based on the provided primary face reference photo.',
                'Output one child only, portrait or half-body, neutral friendly pose, front-facing or 3/4 pose, clean simple background.',
                'This must not be a profile card, character sheet layout, poster, statistics page, measurement chart, or labeled design.',
            ]);
        } elseif ($jobType === 'cover_image') {
            $lines = array_merge($lines, [
                '',
                'Cover artwork requirements:',
                'Use the approved child reference illustration and primary face photo as identity references.',
                'Create a premium children\'s book cover illustration environment, but do not render final cover text.',
                'Leave clean empty areas for title and logo to be added later by layout code.',
                'The child must look like the same child.',
            ]);
        } elseif ($scene) {
            $lines = array_merge($lines, [
                '',
                'Scene generation requirements:',
                'Use the approved child reference illustration and primary face photo as identity references.',
                'The child must remain recognizable as the same real child.',
                'Priority order: 1. preserve child identity, 2. preserve approved reference illustration, 3. apply the scene environment, 4. keep a clean safe area for later text overlay.',
                'Scene number: '.$scene->scene_number,
                'Scene title: '.($scene->title ?: 'Not available'),
                'Scene story text context: '.($scene->story_text ?: 'Not available'),
                'Scene visual direction: '.($scene->visual_direction ?: 'Not available'),
                'Child action / pose: '.($scene->child_action_pose ?: 'Not available'),
                'Environment: '.($scene->environment ?: 'Not available'),
                'Mood and lighting: '.($scene->mood_lighting ?: 'Not available'),
                'Supporting characters: '.($scene->supporting_characters ?: 'Not available'),
                'Key objects: '.($scene->key_objects ?: 'Not available'),
                'Continuity notes: '.($scene->continuity_notes ?: 'Not available'),
                'Educational value / behavior: '.($scene->educational_value ?: 'Not available'),
                'Safe text area requirement: '.($scene->text_safe_area_notes ?: 'Reserve a calm low-detail area for future Arabic text overlay. Do not render the text now.'),
            ]);
        }

        if ($characterSheet) {
            $lines[] = 'Approved child reference illustration is supplied as an input reference. Preserve it strongly while keeping the child recognizable from the original face reference.';
        }

        if (filled($manualNotes)) {
            $lines[] = 'Additional production notes from admin: '.Str::squish((string) $manualNotes);
        }

        $lines = array_merge($lines, [
            '',
            'Forbidden visual output:',
            'No text, no letters, no words, no Arabic text, no English text, no logo, no watermark, no fake HeroKid title, no profile card, no annotations, no measurement chart, no school badge text, no random symbols, no extra children.',
            'No adult-looking child, no changed face, no changed hairstyle, no makeup, no glamorous beauty redesign, no anime face, no distorted hands, no extra fingers, no copyrighted characters, no franchise costumes.',
            'Never draw HeroKid as visible text. Never draw a title. Never draw labels. Never draw fake writing.',
        ]);

        $negative = implode(', ', array_filter([
            'text',
            'letters',
            'words',
            'Arabic text',
            'English text',
            'logo',
            'watermark',
            'fake HeroKid title',
            'profile card',
            'annotations',
            'measurement chart',
            'school badge text',
            'random symbols',
            'extra children',
            'adult-looking child',
            'changed face',
            'changed hairstyle',
            'makeup',
            'glamorous beauty redesign',
            'anime face',
            'distorted hands',
            'extra fingers',
            'copyrighted characters',
            'famous franchise costumes',
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
