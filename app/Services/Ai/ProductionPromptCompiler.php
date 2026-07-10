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
        $isCharacterReference = $jobType === 'character_sheet';
        $templateHero = trim((string) ($scene?->template_hero_name ?: $project->template_hero_name));
        $childHero = trim((string) ($scene?->personalized_hero_name ?: $project->personalized_hero_name ?: $order->child_name));
        $personalizationApplied = $scene?->isPersonalizedForImageGeneration() ?? false;
        $oldHeroRemaining = $scene ? $scene->oldHeroConflicts($templateHero) !== [] : false;
        $storyTitle = $this->personalizedContext((string) ($order->story?->title ?: 'Not available'), $templateHero, $childHero);
        $storySummary = $this->personalizedContext((string) ($order->story?->short_desc ?: $order->story?->full_desc ?: 'Not available'), $templateHero, $childHero);
        $orientation = match ($jobType) {
            'character_sheet' => 'portrait or half-body identity reference on a simple clean background',
            'cover_image' => 'A4 portrait cover artwork composition',
            default => 'A3 landscape two-page story spread preview composition',
        };

        $lines = [
            $isCharacterReference
                ? 'Production context: create a clean child identity reference illustration for internal production only. This is not a story scene, book cover, poster, product mockup, or page layout.'
                : 'Production context: create an original premium children\'s book illustration. Do not render brand text or logos inside the image.',
            'Visual style: '.$style,
            'Output framing: '.$orientation.'. Generate a practical preview image, not final print-ready layout.',
        ];

        if ($isCharacterReference) {
            $lines = array_merge($lines, [
                'Do not use story context, book themes, reading scenes, props, decorative stars, fantasy backgrounds, or production layout elements.',
                'The only goal is to create a neutral visual identity reference that future scene and cover generations can follow.',
            ]);
        } else {
            $lines = array_merge($lines, [
                'Selected story title for context only, not visual text: '.$storyTitle,
                'Selected story summary: '.$storySummary,
                'Educational value: '.($order->lesson ?: $order->story?->lesson_value ?: 'Not available'),
            ]);
        }

        if ($scene && ! $isCharacterReference) {
            $lines = array_merge($lines, [
                '',
                'CRITICAL OUTPUT TYPE — WIDE STORY SCENE, NOT A CHARACTER PORTRAIT:',
                'Create one wide landscape cinematic story illustration that fills the entire canvas with the scene environment described below.',
                'This image represents one connected A3 landscape reader spread across two facing A4 portrait pages.',
                'The child is a character acting inside the scene, not a centered portrait subject, profile card, studio photo, or character reference sheet.',
                'Show a wide or medium-wide environmental composition. The environment, action, mood, and key story objects must be immediately recognizable.',
                'Do not preserve the input photo composition, portrait crop, flat background, classroom wall, or source clothing. Preserve only the child identity.',
                'Use scene-driven wardrobe and pose. Fill both halves of the landscape canvas with continuous artwork and reserve the requested calm text-safe area.',
            ]);
        }

        $lines = array_merge($lines, [
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
            '- Wardrobe direction: '.($profile?->wardrobe_direction ?: ($isCharacterReference ? 'Plain child-friendly clothing without logos, badges, writing, emblems, or school identifiers.' : 'Premium child-friendly outfit suitable for the scene.')),
            '- Approved visual style notes: '.($profile?->approved_visual_style ?: 'Consistent premium storybook art direction.'),
            '',
            'Identity fidelity is the highest priority. This is not a generic child.',
            'Keep the child\'s real photo-derived face, hairstyle, skin tone, apparent age, and body proportions consistent in every illustration.',
            'Do not transform the child into a different-looking character. Use the real photo-derived face as the identity anchor even when changing outfit, pose, lighting, or environment.',
            'Preserve the exact face shape, eye shape, eye spacing, eye color, nose shape, mouth and smile shape, cheeks, jawline, hairline, hairstyle, hair texture, hair volume, skin tone, apparent age, and natural child body proportions.',
            'Preserve real-photo individuality and asymmetry: forehead height, eyebrow thickness and angle, eye size and spacing, ear shape, cheek shape, chin shape, tooth spacing, smile asymmetry, and natural expression.',
            'Preserve the original hairstyle arrangement from the primary face reference. If the photo shows pulled-back hair, side ponytail, tied hair, or hair swept to one side, keep that structure. Do not convert it into loose center-parted hair unless the reference clearly shows that.',
            'Do not beautify, glamorize, age up, redesign, replace, or convert the child into a different-looking character.',
            'Use the primary face reference for facial identity. Use any body reference only for body proportions. Do not mix clothing from references unless explicitly requested.',
        ]);

        if ($isCharacterReference) {
            $lines = array_merge($lines, [
                '',
                'Approved Child Reference Illustration requirements:',
                'Create a clean child reference illustration based on the provided primary face reference photo.',
                'Use soft semi-realistic facial fidelity with very low stylization. Keep the face closer to a photo-derived portrait than a cartoon avatar.',
                'Output one child only, portrait or half-body, neutral friendly pose, front-facing or 3/4 pose, clean simple solid or soft-gradient background.',
                'No props of any kind: no book, no open pages, no pen, no certificate, no frame, no toy, no school item, no viewer hands, no waving hand reaching toward camera.',
                'Use plain clothing with no visible logos, no school badge, no badge text, no emblem, no writing, and no readable symbols. If the source photo has a school badge or text, remove it and replace that area with plain fabric.',
                'Do not create a perfect studio portrait, symmetrical beauty portrait, fashion render, or idealized version of the child. Keep natural child proportions and recognizable real-photo facial details.',
                'No decorative stars, icons, sparkles, fantasy objects, scene environment, title area, margins, or layout composition.',
                'This must not be a profile card, character sheet layout, poster, statistics page, measurement chart, or labeled design.',
            ]);
        } elseif ($jobType === 'cover_image') {
            $lines = array_merge($lines, [
                '',
                'Cover artwork requirements:',
                'Use the approved child reference illustration and primary face photo as identity references.',
                'The cover child must use the same real photo-derived face and apparent age from the original references.',
                'Create a premium children\'s book cover illustration environment, but do not render final cover text.',
                'Leave clean empty areas for title and logo to be added later by layout code.',
                'The child must look like the same child.',
            ]);
        } elseif ($scene) {
            $lines = array_merge($lines, [
                '',
                'Scene personalization debug:',
                'personalization_applied: '.($personalizationApplied ? 'true' : 'false'),
                'template_hero_name: '.($templateHero ? '[replaced before image generation]' : 'Not available'),
                'child_hero_name: '.($childHero ?: 'Not available'),
                'old_hero_name_remaining: '.($oldHeroRemaining ? 'true' : 'false'),
                'personalized_scene_context_included: '.($personalizationApplied && ! $oldHeroRemaining ? 'true' : 'false'),
                'The child '.$childHero.' is the main '.($this->isGirl((string) $order->child_gender) ? 'heroine' : 'hero').' of this story scene.',
                'Approved story role for consistent use: '.($project->child_story_role ?: $childHero),
                'Use that child name and story role consistently across the scene. Do not introduce a separate main princess or hero name.',
                'The reusable template hero has already been replaced for this order. Do not invent or introduce another main hero name.',
                '',
                'Scene generation requirements:',
                'Use the approved child reference illustration and primary face photo as identity references.',
                'The child must remain recognizable as the same real child.',
                'The scene child must use the same real photo-derived face, hairstyle, skin tone, apparent age, and body proportions from the original references.',
                'Priority order: 1. preserve child identity, 2. preserve approved reference illustration, 3. apply the scene environment, 4. keep a clean safe area for later text overlay.',
                'Use reference images for identity only, not for composition. Do not copy the reference portrait framing, background, flowers, classroom wall, clothing badge, or plain portrait layout.',
                'The final output must follow the scene story, visual direction, environment, key objects, mood, and child action below. If the reference image conflicts with the scene, keep only the child identity and replace the background/composition with the described scene.',
                'Create a new wide landscape scene composition from scratch. The input face image is only an identity reference, not a base layout, not a poster template, and not a background to preserve.',
                'Place the child naturally inside the exact described environment and include the scene-specific key objects listed below. Do not crop the child as a portrait.',
                'The child should appear naturally inside the described scene, not as a pasted portrait in front of a decorative background.',
                'Generate pure story illustration only. Do not create a poster, title card, social graphic, thumbnail, book cover, profile card, or educational flashcard.',
                'Do not render any visible text, letters, captions, headings, labels, speech bubbles, signs, or symbols in any language. This includes Arabic, English, Korean, Chinese, Japanese, Latin letters, numbers, and pseudo-text.',
                'If text-safe-area notes are provided, leave that area visually calm and blank for later layout text. Never fill it with generated writing.',
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
            'No text, no letters, no words, no Arabic text, no English text, no logo, no watermark, no fake HeroKid title, no profile card, no annotations, no measurement chart, no school badge text, no random symbols.'.($isCharacterReference ? ' No extra children.' : ' Include only supporting characters explicitly required by the scene; never duplicate the main child.'),
            'No Korean text, no Chinese text, no Japanese text, no Latin text, no numbers, no pseudo-text, no captions, no headings, no speech bubbles, no signs, no title-card layout, no poster layout, no social graphic layout, no thumbnail layout.',
            $isCharacterReference
                ? 'No book, no open book, no pages, no readable book content, no props, no school badge, no clothing logo, no decorative stars, no sparkles, no fantasy background, no viewer hands, no waving hand toward camera.'
                : 'No studio portrait, no centered character lineup, no flat or solid-color portrait background, no copied reference-photo background, no school badge, no clothing logo, and no unrequested duplicate of the child.',
            'No adult-looking child, no changed face, no changed hairstyle, no loose center-parted hair when the reference shows tied or side-swept hair, no makeup, no glamorous beauty redesign, no idealized studio portrait, no symmetrical beauty portrait, no exaggerated cartoon face, no anime face, no chibi face, no caricature, no doll-like face, no distorted hands, no extra fingers, no copyrighted characters, no franchise costumes.',
            'Never draw HeroKid as visible text. Never draw a title. Never draw labels. Never draw fake writing.',
        ]);

        $negativeItems = [
            'text',
            'letters',
            'words',
            'Arabic text',
            'English text',
            'Korean text',
            'Chinese text',
            'Japanese text',
            'Latin text',
            'numbers',
            'pseudo-text',
            'captions',
            'headings',
            'speech bubbles',
            'signs',
            'title-card layout',
            'poster layout',
            'social graphic layout',
            'thumbnail layout',
            'logo',
            'watermark',
            'fake HeroKid title',
            'profile card',
            'annotations',
            'measurement chart',
            'school badge text',
            'school badge',
            'badge',
            'emblem',
            'clothing logo',
            'readable clothing text',
            'random symbols',
            'adult-looking child',
            'changed face',
            'changed hairstyle',
            'loose center-parted hair if reference hair is tied or side-swept',
            'makeup',
            'glamorous beauty redesign',
            'idealized studio portrait',
            'symmetrical beauty portrait',
            'exaggerated cartoon face',
            'anime face',
            'chibi face',
            'caricature',
            'doll-like face',
            'distorted hands',
            'extra fingers',
            'copyrighted characters',
            'famous franchise costumes',
            'unsafe content',
            'scary content',
            $profile?->negative_instructions,
        ];

        $negativeItems = array_merge($negativeItems, $isCharacterReference
            ? [
                'book',
                'open book',
                'pages',
                'readable book content',
                'props',
                'decorative stars',
                'sparkles',
                'fantasy background',
                'viewer hands',
                'waving hand toward camera',
                'extra children',
            ]
            : [
                'studio portrait',
                'centered portrait crop',
                'flat portrait background',
                'solid-color portrait background',
                'copied reference-photo composition',
                'unrequested duplicate child',
            ]);

        $negative = implode(', ', array_filter($negativeItems));

        return [
            'prompt' => implode("\n", $lines),
            'negative_prompt' => $negative,
            'personalization_debug' => [
                'personalization_applied' => $personalizationApplied,
                'template_hero_name' => $templateHero ?: null,
                'child_hero_name' => $childHero ?: null,
                'old_hero_name_remaining' => $oldHeroRemaining,
                'personalized_scene_context_included' => $personalizationApplied && ! $oldHeroRemaining,
            ],
        ];
    }

    private function personalizedContext(string $value, string $templateHero, string $childHero): string
    {
        if ($templateHero === '' || $childHero === '' || $templateHero === $childHero) {
            return $value;
        }

        return preg_replace(
            '/(?<![\p{L}\p{N}_])'.preg_quote($templateHero, '/').'(?![\p{L}\p{N}_])/u',
            $childHero,
            $value
        ) ?? $value;
    }

    private function isGirl(string $gender): bool
    {
        return in_array(mb_strtolower(trim($gender)), ['girl', 'female', 'f', 'بنت', 'أنثى', 'انثى'], true);
    }
}
