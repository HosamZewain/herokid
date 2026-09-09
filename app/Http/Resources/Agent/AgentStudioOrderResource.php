<?php

namespace App\Http\Resources\Agent;

use App\Models\Order;
use App\Services\Orders\OrderSceneTextService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class AgentStudioOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = $this->resource['orders'];
        /** @var Order $matched */
        $matched = $this->resource['matched_order'];
        $storyOrders = $orders->filter(fn (Order $order): bool => $order->story_id !== null && $order->story !== null)->values();

        return [
            'success' => true,
            'order' => [
                'id' => $matched->checkoutGroupKey(),
                'order_number' => $matched->order_number,
                'checkout_reference' => $this->resource['checkout_reference'],
                'status' => $matched->status,
                'created_at' => $orders->min('created_at')?->toIso8601String(),
                'source_revision' => $this->revision($storyOrders),
            ],
            'production_stories' => $storyOrders
                ->map(fn (Order $order): array => $this->story($order))
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function story(Order $order): array
    {
        $presentation = app(OrderSceneTextService::class)->present($order);
        $snapshots = $order->sceneTextSnapshots->keyBy('scene_number');
        $templates = $order->story->sceneTemplates->keyBy('scene_number');
        $productionScenes = $order->productionProject?->scenes
            ?->sortByDesc('updated_at')->unique('scene_number')->keyBy('scene_number') ?? collect();

        return [
            'production_unit_id' => 'story:'.$order->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'child' => [
                'name' => (string) $order->child_name,
                'age' => $order->child_age,
                'gender' => $order->child_gender,
                'production_data' => array_filter([
                    'lesson' => $order->lesson,
                    'interests' => $order->interests,
                ], fn (mixed $value): bool => filled($value)),
            ],
            'story' => [
                'id' => $order->story->id,
                'template_id' => 'story:'.$order->story->id,
                'title' => $order->story->title,
                'language' => $this->language($order),
            ],
            'dedication' => $order->gift_note,
            'scenes' => collect($presentation['scenes'])
                ->filter(fn (array $scene): bool => $scene['complete'])
                ->sortBy('scene_number')
                ->map(function (array $scene) use ($snapshots, $templates, $productionScenes): array {
                    $number = (int) $scene['scene_number'];
                    $source = match ($scene['source']) {
                        'production_scene' => $productionScenes->get($number),
                        'order_snapshot' => $snapshots->get($number),
                        default => $templates->get($number),
                    };
                    $sourcePrefix = match ($scene['source']) {
                        'production_scene' => 'production_scene',
                        'order_snapshot' => 'order_scene_snapshot',
                        default => 'story_scene_template',
                    };

                    return [
                        'id' => $sourcePrefix.':'.$source->id,
                        'number' => $number,
                        'text' => $scene['text'],
                        'title' => filled($scene['title']) ? $scene['title'] : null,
                        'metadata' => array_filter([
                            'source' => $scene['source'],
                            'text_variant' => $scene['text_variant'],
                        ], fn (mixed $value): bool => filled($value)),
                    ];
                })
                ->values()
                ->all(),
            'metadata' => [
                'scene_text_source' => (string) $presentation['source_summary'],
                'updated_at' => $order->updated_at->toIso8601String(),
            ],
        ];
    }

    /** @param Collection<int, Order> $storyOrders */
    private function revision(Collection $storyOrders): string
    {
        $parts = $storyOrders->flatMap(function (Order $order): array {
            return [
                'order:'.$order->id.':'.$order->updated_at?->format('U.u'),
                'story:'.$order->story_id.':'.$order->story?->updated_at?->format('U.u'),
                ...($order->story?->sceneTemplates ?? collect())->map(fn ($scene): string => 'template:'.$scene->id.':'.$scene->updated_at?->format('U.u'))->all(),
                ...$order->sceneTextSnapshots->map(fn ($scene): string => 'snapshot:'.$scene->id.':'.$scene->updated_at?->format('U.u'))->all(),
                ...($order->productionProject?->scenes ?? collect())->map(fn ($scene): string => 'production:'.$scene->id.':'.$scene->updated_at?->format('U.u'))->all(),
            ];
        })->sort()->values()->all();

        return 'sha256:'.hash('sha256', implode('|', $parts));
    }

    private function language(Order $order): string
    {
        return in_array($order->language ?: $order->story->language, ['ar', 'en'], true)
            ? ($order->language ?: $order->story->language)
            : 'ar';
    }
}
