<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Story;
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

        $story->loadMissing('sceneTemplates');
        $templates = $story->sceneTemplates->keyBy('scene_number');
        $context = $this->renderer->contextForOrder($order, $story);

        foreach (range(1, StorySceneParser::SCENE_COUNT) as $sceneNumber) {
            $template = $templates->get($sceneNumber);

            $order->sceneTextSnapshots()->create([
                'source_story_scene_template_id' => $template?->id,
                'scene_number' => $sceneNumber,
                'title_snapshot' => $template?->title,
                'template_text_snapshot' => $template?->text_template,
                'rendered_text' => $this->renderer->render($template?->text_template, $context),
                'render_context_snapshot' => $context,
            ]);
        }
    }

    /**
     * @return array{
     *   scenes: list<array{scene_number: int, title: string, text: string, source: string, source_label: string, complete: bool}>,
     *   ready_count: int,
     *   all_ready: bool,
     *   has_any: bool,
     *   is_legacy_fallback: bool,
     *   source_summary: string
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

            if ($production && filled($production->story_text)) {
                $title = trim((string) $production->title);
                $text = trim((string) $production->story_text);
                $source = 'production_scene';
                $sourceLabel = 'Production Studio';
            } elseif ($hasSnapshots) {
                $title = trim((string) $snapshot?->title_snapshot);
                $text = trim((string) $snapshot?->rendered_text);
                $source = filled($text) ? 'order_snapshot' : 'missing';
                $sourceLabel = filled($text) ? 'نسخة الطلب المحفوظة' : 'نص غير متوفر';
            } elseif ($template) {
                $title = trim((string) $template->title);
                $text = $this->renderer->render($template->text_template, $context);
                $source = filled($text) ? 'story_template_fallback' : 'missing';
                $sourceLabel = filled($text) ? 'قالب القصة الحالي (طلب قديم)' : 'نص غير متوفر';
            }

            $scenes[] = [
                'scene_number' => $sceneNumber,
                'title' => $title,
                'text' => $text,
                'source' => $source,
                'source_label' => $sourceLabel,
                'complete' => filled($text),
            ];
        }

        $readyCount = collect($scenes)->where('complete', true)->count();
        $sources = collect($scenes)
            ->where('complete', true)
            ->pluck('source_label')
            ->unique()
            ->values();

        return [
            'scenes' => $scenes,
            'ready_count' => $readyCount,
            'all_ready' => $readyCount === StorySceneParser::SCENE_COUNT,
            'has_any' => $readyCount > 0,
            'is_legacy_fallback' => ! $hasSnapshots,
            'source_summary' => $sources->isEmpty() ? 'لا يوجد مصدر نص' : $sources->implode(' + '),
        ];
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
