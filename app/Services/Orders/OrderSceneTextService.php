<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Story;
use App\Models\StorySceneTemplate;
use App\Services\Stories\StorySceneParser;
use App\Services\Stories\StorySceneTemplateRenderer;
use Illuminate\Support\Collection;

class OrderSceneTextService
{
    public function __construct(
        private readonly StorySceneTemplateRenderer $renderer,
    ) {}

    public function snapshotForOrder(Order $order, Story $story): void
    {
        if ($order->sceneTextSnapshots()->exists()) {
            return;
        }

        $this->refreshForOrder($order, $story);
    }

    /**
     * Re-render the order-owned scene snapshot after an authorized admin changes
     * personalization details. Story templates remain untouched.
     */
    public function refreshForOrder(Order $order, Story $story): void
    {
        $story->loadMissing('sceneTemplates');
        $templates = $story->sceneTemplates->keyBy('scene_number');
        $context = $this->renderer->contextForOrder($order, $story);

        foreach (range(1, StorySceneParser::SCENE_COUNT) as $sceneNumber) {
            $template = $templates->get($sceneNumber);
            $selection = $this->selectTemplate($template, $order, $story);
            $snapshotContext = [
                ...$context,
                'selected_text_variant' => $selection['variant'],
            ];

            $order->sceneTextSnapshots()->updateOrCreate(
                ['scene_number' => $sceneNumber],
                [
                    'source_story_scene_template_id' => $template?->id,
                    'title_snapshot' => $template?->title,
                    'template_text_snapshot' => $selection['text'],
                    'rendered_text' => $this->renderer->render($selection['text'], $context),
                    'selected_text_variant' => $selection['variant'],
                    'render_context_snapshot' => $snapshotContext,
                ],
            );
        }

        $order->unsetRelation('sceneTextSnapshots');
    }

    /**
     * @return array{
     *   scenes: list<array{scene_number: int, title: string, text: string, source: string, source_label: string, text_variant: ?string, variant_label: string, uses_gender_fallback: bool, complete: bool}>,
     *   ready_count: int,
     *   all_ready: bool,
     *   has_any: bool,
     *   is_legacy_fallback: bool,
     *   source_summary: string,
     *   has_gender_fallback: bool,
     *   gender_fallback_scene_numbers: list<int>
     * }
     */
    public function present(Order $order, bool $includeProductionScenes = true): array
    {
        $order->loadMissing([
            'story.sceneTemplates',
            'sceneTextSnapshots',
            'productionProject.scenes',
        ]);

        $snapshots = $order->sceneTextSnapshots->keyBy('scene_number');
        $hasSnapshots = $snapshots->isNotEmpty();
        $templates = $order->story?->sceneTemplates?->keyBy('scene_number') ?? collect();
        $productionScenes = $includeProductionScenes
            ? $this->productionScenes($order)
            : collect();
        $context = $this->renderer->contextForOrder($order);
        $scenes = [];

        foreach (range(1, StorySceneParser::SCENE_COUNT) as $sceneNumber) {
            $production = $productionScenes->get($sceneNumber);
            $snapshot = $snapshots->get($sceneNumber);
            $template = $templates->get($sceneNumber);
            $title = '';
            $text = '';
            $source = 'missing';
            $sourceLabel = 'نص غير متوفر';
            $textVariant = null;
            $variantLabel = '';
            $usesGenderFallback = false;

            if ($production && filled($production->story_text)) {
                $title = trim((string) $production->title);
                $text = trim((string) $production->story_text);
                $source = 'production_scene';
                $sourceLabel = 'Production Studio';
                $variantLabel = 'نص Production Studio';
            } elseif ($hasSnapshots) {
                $title = trim((string) $snapshot?->title_snapshot);
                $text = trim((string) $snapshot?->rendered_text);
                $source = filled($text) ? 'order_snapshot' : 'missing';
                $textVariant = $snapshot?->selected_text_variant;
                $variantLabel = $this->variantLabel(
                    $textVariant,
                    $snapshot?->render_context_snapshot['child_gender'] ?? null,
                    historical: $textVariant === null,
                );
                $usesGenderFallback = $textVariant === 'original_fallback';
                $sourceLabel = filled($text) ? 'نسخة الطلب المحفوظة' : 'نص غير متوفر';
            } elseif ($template) {
                $selection = $this->selectTemplate($template, $order, $order->story);
                $title = trim((string) $template->title);
                $text = $this->renderer->render($selection['text'], $context);
                $textVariant = $selection['variant'];
                $variantLabel = $this->variantLabel($textVariant, $order->child_gender);
                $usesGenderFallback = $selection['uses_fallback'];
                $source = filled($text) ? 'story_template_fallback' : 'missing';
                $sourceLabel = filled($text) ? 'قالب القصة الحالي (طلب قديم)' : 'نص غير متوفر';
            }

            $scenes[] = [
                'scene_number' => $sceneNumber,
                'title' => $title,
                'text' => $text,
                'source' => $source,
                'source_label' => $sourceLabel,
                'text_variant' => $textVariant,
                'variant_label' => $variantLabel,
                'uses_gender_fallback' => $usesGenderFallback,
                'complete' => filled($text),
            ];
        }

        $readyCount = collect($scenes)->where('complete', true)->count();
        $sources = collect($scenes)
            ->where('complete', true)
            ->pluck('source_label')
            ->unique()
            ->values();
        $genderFallbackSceneNumbers = collect($scenes)
            ->where('uses_gender_fallback', true)
            ->pluck('scene_number')
            ->values()
            ->all();

        return [
            'scenes' => $scenes,
            'ready_count' => $readyCount,
            'all_ready' => $readyCount === StorySceneParser::SCENE_COUNT,
            'has_any' => $readyCount > 0,
            'is_legacy_fallback' => ! $hasSnapshots,
            'source_summary' => $sources->isEmpty() ? 'لا يوجد مصدر نص' : $sources->implode(' + '),
            'has_gender_fallback' => $genderFallbackSceneNumbers !== [],
            'gender_fallback_scene_numbers' => $genderFallbackSceneNumbers,
        ];
    }

    /**
     * @return array{text: ?string, variant: string, uses_fallback: bool}
     */
    private function selectTemplate(?StorySceneTemplate $template, Order $order, ?Story $story): array
    {
        $storyGender = $this->normalizedGender($story?->gender);
        $childGender = $this->normalizedGender($order->child_gender);

        if ($storyGender === 'both' || ! in_array($storyGender, ['boy', 'girl'], true) || ! in_array($childGender, ['boy', 'girl'], true)) {
            return [
                'text' => $template?->text_template,
                'variant' => 'original',
                'uses_fallback' => false,
            ];
        }

        if ($storyGender === $childGender) {
            return [
                'text' => $template?->text_template,
                'variant' => 'original',
                'uses_fallback' => false,
            ];
        }

        if (filled($template?->alternate_text_template)) {
            return [
                'text' => $template?->alternate_text_template,
                'variant' => 'alternate',
                'uses_fallback' => false,
            ];
        }

        return [
            'text' => $template?->text_template,
            'variant' => 'original_fallback',
            'uses_fallback' => true,
        ];
    }

    private function normalizedGender(?string $gender): string
    {
        return in_array($gender, ['boy', 'girl', 'both'], true) ? $gender : 'both';
    }

    private function variantLabel(?string $variant, ?string $childGender = null, bool $historical = false): string
    {
        if ($historical) {
            return 'نسخة تاريخية';
        }

        $genderLabel = match ($childGender) {
            'boy' => 'ولد',
            'girl' => 'بنت',
            default => null,
        };

        return match ($variant) {
            'alternate' => 'النص البديل'.($genderLabel ? ' — '.$genderLabel : ''),
            'original_fallback' => 'النص الأساسي بدل البديل',
            default => 'النص الأساسي',
        };
    }

    private function productionScenes(Order $order): Collection
    {
        if (! $order->productionProject) {
            return collect();
        }

        return $order->productionProject->scenes
            ->sortByDesc('updated_at')
            ->unique('scene_number')
            ->keyBy('scene_number');
    }
}
