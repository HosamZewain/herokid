<?php

namespace App\Services\Stories;

use App\Models\Story;

class StorySceneTemplateService
{
    public function __construct(
        private readonly StorySceneTemplateRenderer $renderer,
    ) {}

    /**
     * @param  array<int|string, mixed>  $scenes
     * @return array<string, string>
     */
    public function validationErrors(array $scenes): array
    {
        $errors = [];

        foreach ($scenes as $key => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $sceneNumber = (int) ($scene['scene_number'] ?? $key);
            foreach (['text_template', 'alternate_text_template'] as $variant) {
                $unknown = $this->renderer->unknownVariables($scene[$variant] ?? null);

                if ($unknown !== []) {
                    $errors['scenes.'.$sceneNumber.'.'.$variant] = 'المشهد '.$sceneNumber.' يحتوي على متغيرات غير مدعومة: '.implode('، ', $unknown);
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<int|string, mixed>  $scenes
     * @return array{
     *   changed_title_scene_numbers: list<int>,
     *   changed_original_scene_numbers: list<int>,
     *   changed_alternate_scene_numbers: list<int>,
     *   original_completed_count: int,
     *   alternate_completed_count: int
     * }
     */
    public function sync(Story $story, array $scenes): array
    {
        $existing = $story->sceneTemplates()->get()->keyBy('scene_number');
        $submitted = collect($scenes)
            ->filter(fn (mixed $scene): bool => is_array($scene))
            ->keyBy(fn (array $scene, int|string $key): int => (int) ($scene['scene_number'] ?? $key));
        $changedTitles = [];
        $changedOriginal = [];
        $changedAlternate = [];
        $originalCompleted = 0;
        $alternateCompleted = 0;

        foreach (range(1, StorySceneParser::SCENE_COUNT) as $sceneNumber) {
            $scene = $submitted->get($sceneNumber, []);
            $title = trim((string) (is_array($scene) ? ($scene['title'] ?? '') : ''));
            $text = trim((string) (is_array($scene) ? ($scene['text_template'] ?? '') : ''));
            $alternateText = trim((string) (is_array($scene) ? ($scene['alternate_text_template'] ?? '') : ''));
            $current = $existing->get($sceneNumber);

            if (! $current || (string) $current->title !== $title) {
                $changedTitles[] = $sceneNumber;
            }

            if (! $current || (string) $current->text_template !== $text) {
                $changedOriginal[] = $sceneNumber;
            }

            if (! $current || (string) $current->alternate_text_template !== $alternateText) {
                $changedAlternate[] = $sceneNumber;
            }

            $story->sceneTemplates()->updateOrCreate(
                ['scene_number' => $sceneNumber],
                [
                    'title' => $title !== '' ? $title : null,
                    'text_template' => $text !== '' ? $text : null,
                    'alternate_text_template' => $alternateText !== '' ? $alternateText : null,
                ],
            );

            if ($text !== '') {
                $originalCompleted++;
            }

            if ($alternateText !== '') {
                $alternateCompleted++;
            }
        }

        $story->unsetRelation('sceneTemplates');

        return [
            'changed_title_scene_numbers' => $changedTitles,
            'changed_original_scene_numbers' => $changedOriginal,
            'changed_alternate_scene_numbers' => $changedAlternate,
            'original_completed_count' => $originalCompleted,
            'alternate_completed_count' => $alternateCompleted,
        ];
    }
}
