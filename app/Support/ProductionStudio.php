<?php

namespace App\Support;

use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\ProductionQaCheck;
use App\Models\User;
use App\Services\Ai\AiProviderAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductionStudio
{
    public static function enabled(): bool
    {
        return (bool) config('production_studio.enabled', true);
    }

    public static function aiAvailable(): bool
    {
        return self::enabled() && app(AiProviderAvailability::class)->anyProviderAvailable();
    }

    public static function createProjectFromOrder(Order $order, User $creator): ProductionProject
    {
        $order->loadMissing(['story', 'items.product', 'items.variant']);

        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
            'created_by_user_id' => $creator->id,
            'source_snapshot_json' => self::sourceSnapshot($order),
            'sent_to_studio_at' => now(),
        ]);

        $project->characterProfile()->create([
            'reference_photo_selection' => [],
            'approved_reference_photos' => [],
        ]);

        self::seedQaChecks($project);
        self::log($project, 'project.created', 'تم إنشاء مشروع الاستوديو من الطلب الأصلي.', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ], $creator);

        AdminActivityLogger::log(
            action: 'production_studio.project_created',
            description: 'إرسال الطلب إلى استوديو الإنتاج: '.$order->order_number,
            subject: $project,
            properties: [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'project_id' => $project->id,
            ],
            admin: $creator,
            request: request(),
        );

        return $project;
    }

    public static function sourceSnapshot(Order $order): array
    {
        $story = $order->story;

        return [
            'source' => 'order_snapshot_at_project_creation',
            'order_number' => $order->order_number,
            'customer_name' => $order->parent_name ?? $order->user?->name,
            'child_name' => $order->child_name,
            'child_gender' => $order->child_gender,
            'child_age' => $order->child_age,
            'selected_story_id' => $story?->id,
            'selected_story' => $story?->title,
            'selected_story_age_range' => $story?->age_range,
            'story_title' => $story?->title,
            'customer_notes' => $order->parent_notes,
            'interests' => $order->interests,
            'uploaded_child_photo_metadata' => collect($order->uploaded_photos ?? [])
                ->filter(fn ($path): bool => is_string($path))
                ->values()
                ->map(fn (string $path, int $index): array => [
                    'index' => $index,
                    'storage_path' => $path,
                ])
                ->all(),
            'selected_addons' => $order->items
                ->filter(fn ($item) => $item->item_type === 'product_add_on')
                ->map(fn ($item): array => [
                    'order_item_id' => $item->id,
                    'title' => $item->title,
                    'quantity' => $item->quantity,
                    'linked_order_item_id' => $item->linked_order_item_id,
                    'snapshot' => $item->item_snapshot,
                ])
                ->values()
                ->all(),
            'order_created_at' => $order->created_at?->toISOString(),
        ];
    }

    public static function seedQaChecks(ProductionProject $project): void
    {
        foreach (self::defaultQaItems() as $item) {
            ProductionQaCheck::firstOrCreate(
                [
                    'production_project_id' => $project->id,
                    'item_key' => $item['item_key'],
                ],
                $item
            );
        }
    }

    public static function defaultQaItems(): array
    {
        return [
            ['category' => 'story', 'item_key' => 'story_age_range', 'label' => 'Story is suitable for selected age range'],
            ['category' => 'story', 'item_key' => 'story_language_clear', 'label' => 'Story language is clear and engaging'],
            ['category' => 'story', 'item_key' => 'story_educational_value', 'label' => 'Educational value is present without direct preaching'],
            ['category' => 'story', 'item_key' => 'story_structure', 'label' => 'Story has a clear beginning, challenge, progression, and warm ending'],
            ['category' => 'story', 'item_key' => 'story_originality', 'label' => 'No copyrighted character copying'],
            ['category' => 'story', 'item_key' => 'story_safety', 'label' => 'No unsafe or inappropriate content'],
            ['category' => 'child_identity', 'item_key' => 'child_reference_photos', 'label' => 'Approved child reference photos selected'],
            ['category' => 'child_identity', 'item_key' => 'child_name_correct', 'label' => 'Child name is correct'],
            ['category' => 'child_identity', 'item_key' => 'child_gender_correct', 'label' => 'Child gender is correct'],
            ['category' => 'child_identity', 'item_key' => 'character_profile_complete', 'label' => 'Character profile is complete'],
            ['category' => 'child_identity', 'item_key' => 'identity_consistency', 'label' => 'Child identity consistency checked across scenes'],
            ['category' => 'scenes', 'item_key' => 'required_scenes_present', 'label' => 'All required scenes are present'],
            ['category' => 'scenes', 'item_key' => 'scene_text_complete', 'label' => 'Every scene has written text'],
            ['category' => 'scenes', 'item_key' => 'scene_visual_direction', 'label' => 'Every scene has visual direction'],
            ['category' => 'scenes', 'item_key' => 'scene_safe_text_area', 'label' => 'Safe text area is defined'],
            ['category' => 'scenes', 'item_key' => 'scene_sequence', 'label' => 'Story sequence is correct'],
            ['category' => 'print_layout', 'item_key' => 'cover_exists', 'label' => 'Cover exists'],
            ['category' => 'print_layout', 'item_key' => 'back_cover_exists', 'label' => 'Back cover exists'],
            ['category' => 'print_layout', 'item_key' => 'reader_order_asset_complete', 'label' => 'Reader-order asset is complete'],
            ['category' => 'print_layout', 'item_key' => 'print_ready_asset_complete', 'label' => 'Print-ready booklet asset is complete'],
            ['category' => 'print_layout', 'item_key' => 'print_proof_reviewed', 'label' => 'Print proof reviewed'],
            ['category' => 'print_layout', 'item_key' => 'safe_areas', 'label' => 'No text outside print safe areas'],
        ];
    }

    public static function log(ProductionProject $project, string $action, string $description, array $properties = [], ?User $actor = null): void
    {
        $project->activityLogs()->create([
            'actor_user_id' => $actor?->id ?? auth()->id(),
            'action' => $action,
            'description' => $description,
            'properties' => AdminActivityLogger::sanitize($properties),
        ]);
    }

    public static function sceneSeedText(?string $content, int $sceneNumber): ?string
    {
        $text = trim(html_entity_decode(strip_tags((string) $content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = collect(preg_split('/\R{2,}|\R|\.\s+/u', $text) ?: [])
            ->map(fn (string $part): string => Str::squish($part))
            ->filter()
            ->values();

        return Arr::get($parts, $sceneNumber - 1);
    }
}
