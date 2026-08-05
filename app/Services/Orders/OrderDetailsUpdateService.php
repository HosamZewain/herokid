<?php

namespace App\Services\Orders;

use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\User;
use App\Services\ChildIdentity\AgeRangeResolver;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Support\AdminActivityLogger;
use App\Support\ProductionStudio;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderDetailsUpdateService
{
    private const ORDER_FIELDS = [
        'child_name',
        'child_age',
        'child_gender',
        'language',
        'lesson',
        'interests',
        'gift_note',
        'parent_notes',
    ];

    public function __construct(
        private readonly OrderSceneTextService $sceneTexts,
        private readonly AgeRangeResolver $ageRanges,
        private readonly ChildIdentityEventLogger $childIdentityEvents,
    ) {}

    /**
     * @return array{
     *   changes: array<string, mixed>,
     *   checkout_orders_updated: int,
     *   linked_child_identity_updated: bool,
     *   production_project_synced: bool,
     *   production_requires_review: bool,
     *   manually_edited_scene_numbers: list<int>
     * }
     */
    public function update(Order $order, array $validated, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($order, $validated, $actor, $request): array {
            $order = Order::query()
                ->with([
                    'story.sceneTemplates',
                    'items',
                    'sceneTextSnapshots',
                    'productionPromptOverride',
                ])
                ->lockForUpdate()
                ->findOrFail($order->id);

            $previousStoryId = isset($validated['_previous_story_id'])
                ? (int) $validated['_previous_story_id']
                : (int) $order->story_id;
            $storyChanged = $previousStoryId !== (int) $order->story_id;
            $before = $this->editableValues($order);
            $oldSnapshots = $order->sceneTextSnapshots->keyBy('scene_number');
            $checkoutOrdersUpdated = $this->updateCheckoutContact($order, $validated);

            $order->forceFill(Arr::only($validated, self::ORDER_FIELDS))->save();
            $order->refresh()->loadMissing(['story.sceneTemplates', 'items', 'productionPromptOverride']);

            $this->updateStoryItemSnapshot($order);
            $promptOverrideUpdated = $this->syncPromptOverride($order, $actor);
            $linkedIdentityUpdated = $this->syncLinkedChildIdentity($order, $validated, $actor);

            if ($order->story) {
                $this->sceneTexts->refreshForOrder($order, $order->story);
            }

            $order->load('sceneTextSnapshots');
            $changes = AdminActivityLogger::changedValues($before, $this->editableValues($order));
            if ($storyChanged) {
                $changes['story_id'] = [
                    'old' => $previousStoryId ?: null,
                    'new' => $order->story_id ? (int) $order->story_id : null,
                ];
            }
            $productionSync = $this->syncProductionProject(
                $order,
                $oldSnapshots,
                array_keys($changes),
                (string) ($before['child_name'] ?? ''),
                $actor,
            );

            AdminActivityLogger::log(
                action: 'order.details_updated',
                description: 'تحديث بيانات الطلب والتخصيص: '.$order->order_number,
                subject: $order,
                properties: [
                    'order_number' => $order->order_number,
                    'reason' => $validated['change_reason'],
                    'changes' => $changes,
                    'checkout_orders_updated' => $checkoutOrdersUpdated,
                    'story_item_snapshot_updated' => $order->items->contains('item_type', 'story'),
                    'scene_snapshots_refreshed' => (bool) $order->story,
                    'production_prompt_override_updated' => $promptOverrideUpdated,
                    'linked_child_identity_updated' => $linkedIdentityUpdated,
                    'production' => $productionSync,
                    'linked_child_identity_request_id' => $order->child_identity_request_id,
                    'historical_prompt_snapshots_preserved' => true,
                ],
                admin: $actor,
                request: $request,
            );

            return [
                'changes' => $changes,
                'checkout_orders_updated' => $checkoutOrdersUpdated,
                'linked_child_identity_updated' => $linkedIdentityUpdated,
                ...$productionSync,
            ];
        });
    }

    private function editableValues(Order $order): array
    {
        return [
            'parent_name' => $order->parent_name,
            'phone' => data_get($order->delivery_details, 'phone'),
            ...Arr::only($order->getAttributes(), self::ORDER_FIELDS),
        ];
    }

    private function updateCheckoutContact(Order $order, array $validated): int
    {
        $siblings = Order::query()
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->lockForUpdate()
            ->get();

        foreach ($siblings as $sibling) {
            $delivery = $sibling->delivery_details ?? [];
            $delivery['phone'] = $validated['phone'];
            $sibling->forceFill([
                'parent_name' => $validated['parent_name'],
                'delivery_details' => $delivery,
            ])->save();
        }

        return $siblings->count();
    }

    private function updateStoryItemSnapshot(Order $order): void
    {
        $storyItem = $order->items
            ->first(fn ($item): bool => $item->item_type === 'story'
                && (! $order->story_id || (int) $item->story_id === (int) $order->story_id));

        if (! $storyItem) {
            return;
        }

        $snapshot = $storyItem->personalization_snapshot ?? [];
        $snapshot['child_name'] = $order->child_name;
        $snapshot['child_age'] = $order->child_age;
        $snapshot['child_gender'] = $order->child_gender;
        $snapshot['child_age_range'] = $this->resolveAgeRange(
            (int) $order->child_age,
            $snapshot['child_age_range'] ?? $order->story?->age_range,
        );

        $storyItem->forceFill(['personalization_snapshot' => $snapshot])->save();
    }

    private function resolveAgeRange(int $age, ?string $fallback): ?string
    {
        try {
            return $this->ageRanges->resolve($age);
        } catch (ValidationException) {
            return $fallback;
        }
    }

    private function syncPromptOverride(Order $order, User $actor): bool
    {
        $override = $order->productionPromptOverride;

        if (! $override) {
            return false;
        }

        $prompt = StoryProductionPrompt::withCurrentOrderDetails($override->prompt_text, $order);

        if (hash_equals($override->prompt_text, $prompt)) {
            return false;
        }

        $override->forceFill([
            'prompt_text' => $prompt,
            'updated_by' => $actor->id,
        ])->save();

        return true;
    }

    private function syncLinkedChildIdentity(Order $order, array $validated, User $actor): bool
    {
        $identity = $order->child_identity_request_id
            ? ChildIdentityRequest::withTrashed()->lockForUpdate()->find($order->child_identity_request_id)
            : null;

        if (! $identity || ! $order->story) {
            return false;
        }

        $before = Arr::only($identity->getAttributes(), [
            'parent_name',
            'parent_phone',
            'child_name',
            'child_age',
            'age_range',
            'gender',
            'selected_story_id',
        ]);
        $identity->forceFill([
            'parent_name' => $validated['parent_name'],
            'parent_phone' => $validated['phone'],
            'child_name' => $order->child_name,
            'child_age' => $order->child_age,
            'age_range' => $this->resolveAgeRange((int) $order->child_age, $identity->age_range),
            'gender' => $order->child_gender,
            'selected_story_id' => $order->story_id,
        ]);
        $changes = AdminActivityLogger::changedValues(
            $before,
            Arr::only($identity->getAttributes(), array_keys($before)),
        );

        if ($changes === []) {
            return false;
        }

        $identity->save();
        $this->childIdentityEvents->record(
            request: $identity,
            type: 'request.order_details_corrected',
            description: 'تمت مزامنة تصحيح بيانات الطفل وولي الأمر من الطلب المرتبط.',
            metadata: [
                'changes' => $changes,
                'generation_attempts_preserved' => true,
            ],
            order: $order,
            actor: $actor,
            actorType: 'admin',
            source: 'admin',
        );

        return true;
    }

    /**
     * @return array{
     *   production_project_synced: bool,
     *   production_requires_review: bool,
     *   manually_edited_scene_numbers: list<int>
     * }
     */
    private function syncProductionProject(
        Order $order,
        $oldSnapshots,
        array $changedFields,
        string $oldName,
        User $actor,
    ): array {
        $project = ProductionProject::query()
            ->with(['scenes', 'storyVersions', 'qaChecks'])
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if (! $project) {
            return [
                'production_project_synced' => false,
                'production_requires_review' => false,
                'manually_edited_scene_numbers' => [],
            ];
        }

        $identityFields = array_values(array_intersect($changedFields, [
            'story_id',
            'child_name',
            'child_age',
            'child_gender',
            'language',
            'lesson',
            'interests',
            'gift_note',
            'parent_notes',
        ]));
        $identityChanged = $identityFields !== [];
        $newSnapshots = $order->sceneTextSnapshots->keyBy('scene_number');
        $newName = (string) $order->child_name;
        $manuallyEditedScenes = [];

        foreach ($project->scenes as $scene) {
            $oldSnapshotText = trim((string) $oldSnapshots->get($scene->scene_number)?->rendered_text);
            $newSnapshotText = trim((string) $newSnapshots->get($scene->scene_number)?->rendered_text);
            $currentText = trim((string) $scene->story_text);
            $updates = ['personalized_hero_name' => $newName ?: null];
            $warnings = collect($scene->personalization_warnings ?? []);

            if ($currentText !== '' && $oldSnapshotText !== '' && hash_equals($oldSnapshotText, $currentText)) {
                $updates['story_text'] = $newSnapshotText;
            } elseif ($currentText !== '') {
                $updates['story_text'] = $this->replaceChildName($currentText, $oldName, $newName);

                if (array_intersect($changedFields, ['child_age', 'child_gender']) !== []) {
                    $manuallyEditedScenes[] = (int) $scene->scene_number;
                    $warnings->push('تم تغيير عمر/جنس الطفل بعد تعديل هذا المشهد يدويًا؛ راجع الصياغة والتوجيه البصري قبل الإنتاج.');
                }
            }

            foreach (['visual_direction', 'child_action_pose', 'environment', 'continuity_notes'] as $field) {
                if (filled($scene->{$field}) && $oldName !== $newName) {
                    $updates[$field] = $this->replaceChildName((string) $scene->{$field}, $oldName, $newName);
                }
            }

            if ($identityChanged) {
                $updates['personalization_status'] = 'needs_review';
                $warnings->push('تم تعديل بيانات الطلب بعد إنشاء مشروع الإنتاج؛ راجع هذا المشهد قبل أي توليد جديد.');
            }

            $updates['personalization_warnings'] = $warnings->filter()->unique()->values()->all();
            $scene->forceFill($updates)->save();
        }

        if ($oldName !== $newName) {
            foreach ($project->storyVersions as $version) {
                $version->forceFill([
                    'title' => $this->replaceChildName((string) $version->title, $oldName, $newName),
                    'full_story_content' => $this->replaceChildName((string) $version->full_story_content, $oldName, $newName),
                ])->save();
            }
        }

        $currentSnapshot = ProductionStudio::sourceSnapshot($order->loadMissing(['story', 'items.product', 'items.variant']));
        $projectWarnings = collect($project->personalization_warnings ?? []);

        if ($identityChanged) {
            $projectWarnings->push('تغيّرت بيانات الطلب ('.implode('، ', $identityFields).')؛ راجع النصوص والهوية والأصول المولدة قبل المتابعة.');
        }

        $project->forceFill([
            'source_snapshot_json' => array_replace($project->source_snapshot_json ?? [], $currentSnapshot),
            'personalized_hero_name' => $newName ?: null,
            'personalization_status' => $identityChanged ? 'needs_review' : $project->personalization_status,
            'personalization_warnings' => $projectWarnings->filter()->unique()->values()->all(),
        ])->save();

        if ($identityChanged) {
            $project->qaChecks()
                ->whereIn('item_key', [
                    'child_name_correct',
                    'child_gender_correct',
                    'identity_consistency',
                    'scene_text_complete',
                ])
                ->update([
                    'result' => 'not_reviewed',
                    'note' => null,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                ]);
        }

        ProductionStudio::log(
            $project,
            'order.details_synced',
            'تمت مزامنة بيانات الطلب المعدلة مع مشروع الإنتاج مع الحفاظ على الأصول والسجل.',
            [
                'changed_fields' => $identityFields,
                'manually_edited_scene_numbers' => array_values(array_unique($manuallyEditedScenes)),
            ],
            $actor,
        );

        return [
            'production_project_synced' => true,
            'production_requires_review' => $identityChanged,
            'manually_edited_scene_numbers' => array_values(array_unique($manuallyEditedScenes)),
        ];
    }

    private function replaceChildName(string $value, string $oldName, string $newName): string
    {
        if ($value === '' || $oldName === '' || $newName === '' || $oldName === $newName) {
            return $value;
        }

        $aliases = collect([$oldName, preg_split('/\s+/u', $oldName)[0] ?? null])
            ->filter(fn ($alias): bool => is_string($alias) && mb_strlen(trim($alias)) >= 2)
            ->map(fn (string $alias): string => trim($alias))
            ->unique()
            ->sortByDesc(fn (string $alias): int => mb_strlen($alias));

        foreach ($aliases as $alias) {
            $value = preg_replace_callback(
                '/(?<![\p{L}\p{N}_])([وفبكل]?)'.preg_quote($alias, '/').'(?![\p{L}\p{N}_])/u',
                static fn (array $matches): string => ($matches[1] ?? '').$newName,
                $value,
            ) ?? $value;
        }

        return $value;
    }
}
