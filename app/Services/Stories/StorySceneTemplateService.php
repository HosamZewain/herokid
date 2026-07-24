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
     * @return array<int, string>
     */
    public function validationErrors(array $scenes): array
    {
        $errors = [];

        foreach ($scenes as $key => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $sceneNumber = (int) ($scene['scene_number'] ?? $key);
            $unknown = $this->renderer->unknownVariables($scene['text_template'] ?? null);

            if ($unknown !== []) {
                $errors[$sceneNumber] = 'المشهد '.$sceneNumber.' يحتوي على متغيرات غير مدعومة: '.implode('، ', $unknown);
            }
        }

        return $errors;
    }

    /**
     * @param  array<int|string, mixed>  $scenes
     * @return array{changed_scene_numbers: list<int>, completed_count: int}
     */
    public function sync(Story $story, array $scenes): array
    {
        $existing = $story->sceneTemplates()->get()->keyBy('scene_number');
        $submitted = collect($scenes)
            ->filter(fn (mixed $scene): bool => is_array($scene))
            ->keyBy(fn (array $scene, int|string $key): int => (int) ($scene['scene_number'] ?? $key));
        $changed = [];
        $completed = 0;

        foreach (range(1, StorySceneParser::SCENE_COUNT) as $sceneNumber) {
            $scene = $submitted->get($sceneNumber, []);
            $title = trim((string) (is_array($scene) ? ($scene['title'] ?? '') : ''));
            $text = trim((string) (is_array($scene) ? ($scene['text_template'] ?? '') : ''));
            $current = $existing->get($sceneNumber);

            if (! $current || (string) $current->title !== $title || (string) $current->text_template !== $text) {
                $changed[] = $sceneNumber;
            }

            $story->sceneTemplates()->updateOrCreate(
                ['scene_number' => $sceneNumber],
                [
                    'title' => $title !== '' ? $title : null,
                    'text_template' => $text !== '' ? $text : null,
                ],
            );

            if ($text !== '') {
                $completed++;
            }
        }

        $story->unsetRelation('sceneTemplates');

        return [
            'changed_scene_numbers' => $changed,
            'completed_count' => $completed,
        ];
    }
}
