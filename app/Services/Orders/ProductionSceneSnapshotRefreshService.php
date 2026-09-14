<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use App\Services\Stories\ProductionSceneVariantResolver;
use App\Services\Stories\StorySceneTemplateRenderer;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionSceneSnapshotRefreshService
{
    public function refresh(int $orderId, int $expectedStoryId, User $actor, string $reason, bool $apply = false, bool $allowCompleted = false): array
    {
        abort_unless($actor->role === 'admin' && ($actor->hasPermission('orders.update') || $actor->hasPermission('stories.update')), 403);
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'A reason of 1–500 characters is required.']);
        }

        return DB::transaction(function () use ($orderId, $expectedStoryId, $actor, $reason, $apply, $allowCompleted): array {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $fail = static function (string $message): never {
                throw ValidationException::withMessages(['scenes' => $message]);
            };
            if ((int) $order->story_id !== $expectedStoryId) {
                $fail('Story identity mismatch. No changes applied.');
            }
            $protected = in_array($order->status, ['completed', 'produced', 'approved', 'shipped', 'delivered', 'cancelled'], true)
                || in_array(OrderStatusRegistry::behavior('order', $order->status), ['cancelled', 'shipped', 'delivered'], true)
                || in_array(OrderStatusRegistry::behavior('printing', $order->printing_status), ['in_progress', 'completed'], true)
                || in_array(OrderStatusRegistry::behavior('shipping', $order->shipping_status), ['shipped', 'delivered', 'returned', 'cancelled'], true);
            if ($protected && ! $allowCompleted) {
                $fail('Completed/printing/shipped order requires explicit allow-completed approval.');
            }
            $story = Story::query()->lockForUpdate()->findOrFail($expectedStoryId);
            $templates = $story->sceneTemplates()->orderBy('scene_number')->lockForUpdate()->get();
            $snapshots = $order->sceneTextSnapshots()->orderBy('scene_number')->lockForUpdate()->get();
            if ($templates->pluck('scene_number')->map(fn ($n) => (int) $n)->all() !== range(1, 13)
                || $snapshots->pluck('scene_number')->map(fn ($n) => (int) $n)->all() !== range(1, 13)) {
                $fail('Expected exactly 13 ordered templates and existing snapshots. No changes applied.');
            }
            $project = $order->productionProject()->lockForUpdate()->first();
            $productionScenes = $project?->scenes()->orderBy('scene_number')->lockForUpdate()->get() ?? collect();
            if ($productionScenes->isNotEmpty() && $productionScenes->pluck('scene_number')->map(fn ($n) => (int) $n)->all() !== range(1, 13)) {
                $fail('Production scene count/order mismatch. No changes applied.');
            }
            $resolver = app(ProductionSceneVariantResolver::class);
            $renderer = app(StorySceneTemplateRenderer::class);
            $context = $renderer->contextForOrder($order, $story);
            $plans = [];
            foreach ($snapshots as $index => $snapshot) {
                $template = $templates[$index];
                if ($snapshot->source_story_scene_template_id && (int) $snapshot->source_story_scene_template_id !== $template->id) {
                    $fail('Snapshot/template identity mismatch. No changes applied.');
                }
                $selection = $resolver->resolve($template, $order, $story);
                $rendered = $renderer->render($selection['text'], $context);
                if ($selection['uses_fallback'] || ! filled($rendered) || $renderer->unknownVariables($selection['text']) !== []) {
                    $fail('Selected gender variant is missing, blank, or invalid. No changes applied.');
                }
                $production = $productionScenes->firstWhere('scene_number', $snapshot->scene_number);
                if ($production && filled($production->story_text)
                    && trim($production->story_text) !== trim((string) $snapshot->rendered_text)
                    && trim($production->story_text) !== $rendered) {
                    $fail('Independently edited Production Studio text requires review. No changes applied.');
                }
                $plans[] = [$snapshot, [
                    'source_story_scene_template_id' => $template->id,
                    'template_text_snapshot' => $selection['text'],
                    'rendered_text' => $rendered,
                    'selected_text_variant' => $selection['variant'],
                    'render_context_snapshot' => [...($snapshot->render_context_snapshot ?? []), ...$context,
                        'selected_text_variant' => $selection['variant'], 'resolved_text_variant' => $selection['resolved_variant']],
                ]];
            }
            $changed = [];
            foreach ($plans as [$snapshot, $values]) {
                $previous = $snapshot->toArray();
                $production = $productionScenes->firstWhere('scene_number', $snapshot->scene_number);
                $updateProduction = $production && filled($production->story_text) && $production->story_text !== $values['rendered_text'];
                if ($production) {
                    $previous['production_scene'] = ['id' => $production->id, 'story_text' => $production->story_text];
                }
                $snapshot->fill($values);
                if ($snapshot->isDirty() || $updateProduction) {
                    $changed[] = $snapshot->id;
                    if ($apply) {
                        DB::table('order_scene_text_snapshot_revisions')->insert([
                            'snapshot_id' => $snapshot->id, 'admin_user_id' => $actor->id,
                            'previous_snapshot' => json_encode($previous, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'reason' => $reason, 'created_at' => now(),
                        ]);
                        $snapshot->save();
                        if ($updateProduction) {
                            $production->forceFill(['story_text' => $values['rendered_text']])->save();
                        }
                    }
                }
            }
            $result = ['production_unit_id' => 'story:'.$order->id, 'story_id' => $story->id,
                'snapshot_ids' => $snapshots->modelKeys(), 'scene_numbers' => range(1, 13),
                'changed_snapshot_ids' => $changed, 'text_variants' => collect($plans)->map(fn ($plan) => $plan[1]['render_context_snapshot']['resolved_text_variant'])->unique()->values()->all(),
                'applied' => $apply, 'completed_override' => $protected && $allowCompleted];
            if ($apply && $changed !== []) {
                AdminActivityLogger::log('order.production_scene_snapshots_refreshed', 'Explicit production text refresh', $order,
                    [...$result, 'reason' => $reason], $actor);
            }

            return $result;
        });
    }
}
