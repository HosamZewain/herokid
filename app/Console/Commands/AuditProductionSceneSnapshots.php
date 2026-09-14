<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Stories\ProductionSceneVariantResolver;
use App\Services\Stories\StorySceneTemplateRenderer;
use Illuminate\Console\Command;

class AuditProductionSceneSnapshots extends Command
{
    protected $signature = 'orders:audit-production-scenes {--story= : Story template ID; read-only} {--all : Audit all story templates, read-only}';

    protected $description = 'List stale scene snapshot unit IDs for one template without modifying orders';

    public function handle(ProductionSceneVariantResolver $resolver, StorySceneTemplateRenderer $renderer): int
    {
        if ((! $this->option('all') && ! ctype_digit((string) $this->option('story'))) || ($this->option('all') && $this->option('story') !== null)) {
            $this->error('Specify --story=ID. This command never applies changes.');

            return self::FAILURE;
        }
        Order::query()->whereNotNull('story_id')->when(! $this->option('all'), fn ($query) => $query->where('story_id', (int) $this->option('story')))
            ->with(['story.sceneTemplates', 'sceneTextSnapshots', 'productionProject.scenes'])->chunkById(100, function ($orders) use ($resolver, $renderer): void {
                foreach ($orders as $order) {
                    $templates = $order->story?->sceneTemplates?->keyBy('scene_number') ?? collect();
                    $snapshots = $order->sceneTextSnapshots->keyBy('scene_number');
                    $stale = [];
                    foreach (range(1, 13) as $number) {
                        $selection = $resolver->resolve($templates->get($number), $order, $order->story);
                        $snapshot = $snapshots->get($number);
                        if (! $snapshot || $snapshot->rendered_text !== $renderer->render($selection['text'], $renderer->contextForOrder($order))
                            || ($snapshot->render_context_snapshot['resolved_text_variant'] ?? null) !== $selection['resolved_variant']) {
                            $stale[] = $number;
                        }
                    }
                    if ($stale !== []) {
                        $this->line(json_encode(['production_unit_id' => 'story:'.$order->id, 'story_id' => $order->story_id,
                            'stale_scene_numbers' => $stale, 'snapshot_count' => $snapshots->count(), 'template_count' => $templates->count(),
                            'production_text_count' => $order->productionProject?->scenes->filter(fn ($scene) => filled($scene->story_text))->count() ?? 0,
                            'status' => $order->status, 'printing_status' => $order->printing_status], JSON_THROW_ON_ERROR));
                    }
                }
            });

        return self::SUCCESS;
    }
}
