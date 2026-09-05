<?php

namespace App\Services\AgentApi;

use App\Exceptions\AgentApiException;
use App\Models\AgentApiIdempotencyKey;
use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\OrderCheckoutReference;
use App\Models\OrderGroupAssignment;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderAssignmentService;
use App\Services\Orders\OrderStatusService;
use App\Support\AdminActivityLogger;
use App\Support\AppDateTime;
use App\Support\OrderStatusRegistry;
use App\Support\OrderWorkflowStatus;
use App\Support\ProductProductionPrompt;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AgentCheckoutProductionService
{
    public const COMPLETED_STATUS = 'ready_preview';

    public function __construct(
        private readonly OrderAssignmentService $assignments,
        private readonly OrderStatusService $statuses,
    ) {}

    /** @return array<string, mixed>|null */
    public function acquireNext(User $agent, Request $request): ?array
    {
        $this->assertStatusAvailable('new');
        $this->assertStatusAvailable('generating');

        $candidates = Order::query()
            ->selectRaw('checkout_group_key, MIN(created_at) as first_created_at, MIN(id) as first_order_id')
            ->where('status', 'new')
            ->whereNotNull('checkout_group_key')
            ->groupBy('checkout_group_key')
            ->orderBy('first_created_at')
            ->orderBy('first_order_id')
            ->limit(50)
            ->pluck('checkout_group_key');

        foreach ($candidates as $groupKey) {
            try {
                $result = DB::transaction(function () use ($groupKey, $agent, $request): ?array {
                    $orders = $this->lockedOrdersForKey((string) $groupKey);
                    if ($orders->isEmpty()) {
                        return null;
                    }

                    $existing = OrderGroupAssignment::query()
                        ->where('checkout_group_key', $groupKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return null;
                    }

                    $units = $this->units($orders);
                    if ($units->isEmpty()) {
                        return null;
                    }

                    if (! AgentCatalogScope::allowsEveryUnit($agent, $units)) {
                        return null;
                    }

                    $targets = $this->targetOrders($orders, $units);
                    if ($targets->contains(fn (Order $order): bool => $order->status !== 'new')) {
                        return null;
                    }

                    $representative = $orders->first();
                    $this->assignments->acquire($representative, $agent, $request);
                    $this->statuses->updateGroup($targets, 'generating', 'تم الاستحواذ على عملية الشراء بواسطة Agent API.', $request);

                    AdminActivityLogger::log(
                        action: 'agent.checkout_acquired',
                        description: 'استحوذ Agent API على عملية الشراء كاملة.',
                        subject: $representative,
                        properties: [
                            'checkout_group_key' => $groupKey,
                            'agent_user_id' => $agent->id,
                            'target_order_ids' => $targets->pluck('id')->all(),
                            'previous_status' => 'new',
                            'new_status' => 'generating',
                            'request_identifier' => $this->requestIdentifier($request),
                        ],
                        admin: $agent,
                        request: $request,
                    );

                    return $this->summary($orders->map(fn (Order $order): Order => $order->fresh()));
                }, 3);

                if ($result !== null) {
                    return $result;
                }
            } catch (ValidationException) {
                // A concurrent agent won this group. Continue to the next candidate.
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function acquireNextRevision(User $agent, Request $request): ?array
    {
        $this->assertStatusAvailable('revision_requested');

        $candidates = Order::query()
            ->selectRaw('checkout_group_key, MIN(updated_at) as revision_requested_at, MIN(id) as first_order_id')
            ->where('status', 'revision_requested')
            ->whereNotNull('checkout_group_key')
            ->groupBy('checkout_group_key')
            ->orderBy('revision_requested_at')
            ->orderBy('first_order_id')
            ->limit(50)
            ->pluck('checkout_group_key');

        foreach ($candidates as $groupKey) {
            try {
                $result = DB::transaction(function () use ($groupKey, $agent, $request): ?array {
                    $orders = $this->lockedOrdersForKey((string) $groupKey);
                    if ($orders->isEmpty()) {
                        return null;
                    }

                    $units = $this->units($orders);
                    if ($units->isEmpty() || ! AgentCatalogScope::allowsEveryUnit($agent, $units)) {
                        return null;
                    }

                    $targets = $this->targetOrders($orders, $units);
                    if ($targets->contains(fn (Order $order): bool => $order->status !== 'revision_requested')) {
                        return null;
                    }

                    $assignment = OrderGroupAssignment::query()
                        ->where('checkout_group_key', $groupKey)
                        ->lockForUpdate()
                        ->first();
                    if ($assignment && (int) $assignment->assigned_to_user_id !== (int) $agent->id) {
                        return null;
                    }

                    $alreadyAcquired = $assignment !== null;
                    if (! $assignment) {
                        try {
                            $this->assignments->acquire($orders->first(), $agent, $request);
                        } catch (ValidationException) {
                            $winner = OrderGroupAssignment::query()
                                ->where('checkout_group_key', $groupKey)
                                ->first();

                            if (! $winner || (int) $winner->assigned_to_user_id !== (int) $agent->id) {
                                return null;
                            }

                            $alreadyAcquired = true;
                        }
                    }

                    AdminActivityLogger::log(
                        action: 'agent.revision_checkout_acquired',
                        description: 'استحوذ Agent API على عملية شراء من قائمة طلبات التعديل.',
                        subject: $orders->first(),
                        properties: [
                            'checkout_group_key' => $groupKey,
                            'agent_user_id' => $agent->id,
                            'already_acquired' => $alreadyAcquired,
                            'target_order_ids' => $targets->pluck('id')->all(),
                            'status' => 'revision_requested',
                            'request_identifier' => $this->requestIdentifier($request),
                        ],
                        admin: $agent,
                        request: $request,
                    );

                    return [
                        ...$this->summary($orders->map(fn (Order $order): Order => $order->fresh())),
                        'already_acquired' => $alreadyAcquired,
                    ];
                }, 3);

                if ($result !== null) {
                    return $result;
                }
            } catch (ValidationException) {
                // A concurrent agent won this checkout. Continue to the next candidate.
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function acquireSpecific(string $reference, User $agent, Request $request): array
    {
        return DB::transaction(function () use ($reference, $agent, $request): array {
            $orders = $this->ordersForReference($reference, true);
            $units = $this->units($orders);
            $this->assertUnitsAllowed($agent, $units);

            if ($units->isEmpty()) {
                throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This checkout has no production units.', 422);
            }

            $this->assertReworkable($orders);

            $groupKey = $orders->first()->checkoutGroupKey();
            $assignment = OrderGroupAssignment::query()
                ->where('checkout_group_key', $groupKey)
                ->lockForUpdate()
                ->first();

            if ($assignment && (int) $assignment->assigned_to_user_id !== (int) $agent->id) {
                throw new AgentApiException(
                    'ORDER_ALREADY_ACQUIRED',
                    'This checkout is already acquired by another user.',
                    409,
                );
            }

            $alreadyAcquired = $assignment !== null;
            if (! $assignment) {
                try {
                    $this->assignments->acquire($orders->first(), $agent, $request);
                } catch (ValidationException) {
                    $winner = OrderGroupAssignment::query()
                        ->where('checkout_group_key', $groupKey)
                        ->first();

                    if (! $winner || (int) $winner->assigned_to_user_id !== (int) $agent->id) {
                        throw new AgentApiException(
                            'ORDER_ALREADY_ACQUIRED',
                            'This checkout was acquired by another user at the same time.',
                            409,
                        );
                    }

                    $alreadyAcquired = true;
                }
            }

            AdminActivityLogger::log(
                action: 'agent.checkout_acquired_for_rework',
                description: 'استحوذ Agent API على عملية شراء محددة لإعادة العمل.',
                subject: $orders->first(),
                properties: [
                    'checkout_group_key' => $groupKey,
                    'checkout_reference' => $reference,
                    'agent_user_id' => $agent->id,
                    'already_acquired' => $alreadyAcquired,
                    'order_ids' => $orders->pluck('id')->all(),
                    'request_identifier' => $this->requestIdentifier($request),
                ],
                admin: $agent,
                request: $request,
            );

            return [
                'success' => true,
                'checkout' => $this->summary($orders->map(fn (Order $order): Order => $order->fresh())),
                'already_acquired' => $alreadyAcquired,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function context(string $reference, User $agent): array
    {
        $orders = $this->authorizedOrders($reference, $agent);
        $units = $this->units($orders);

        $this->assertUnitsAllowed($agent, $units);

        if ($units->isEmpty()) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This checkout has no production units.', 422);
        }

        $missing = $units->filter(function (array $unit): bool {
            if (blank($unit['production_prompt'])) {
                return true;
            }

            return $unit['type'] === 'story' && $unit['reference_files'] === [];
        })->pluck('unit_key')->values()->all();

        if ($missing !== []) {
            throw new AgentApiException(
                'PRODUCTION_CONTEXT_INCOMPLETE',
                'Production context is missing a prompt or required child reference files.',
                422,
                ['production_units' => $missing],
            );
        }

        return [
            'success' => true,
            'checkout' => $this->summary($orders),
            'team_notes' => $this->teamNotes($orders->first()->checkoutGroupKey()),
            'production_units' => $units->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function complete(string $reference, User $agent, Request $request): array
    {
        $this->assertStatusAvailable('generating');
        $this->assertStatusAvailable(self::COMPLETED_STATUS);

        return DB::transaction(function () use ($reference, $agent, $request): array {
            $orders = $this->authorizedOrders($reference, $agent, true);
            $units = $this->units($orders);
            $this->assertUnitsAllowed($agent, $units);
            $targets = $this->targetOrders($orders, $units);

            if ($units->isEmpty()) {
                throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This checkout has no production units.', 422);
            }

            if ($targets->every(fn (Order $order): bool => $order->status === self::COMPLETED_STATUS)) {
                return [
                    'success' => true,
                    'checkout_reference' => $reference,
                    'status' => self::COMPLETED_STATUS,
                    'already_completed' => true,
                ];
            }

            if ($targets->contains(fn (Order $order): bool => $order->status !== 'generating')) {
                throw new AgentApiException('INVALID_ORDER_STATUS', 'Every production order must be in generating status.', 409);
            }

            $reworkCutoffs = $this->latestReworkAttachmentCutoffs($reference, $orders->first()->checkoutGroupKey());
            $missing = $units->filter(fn (array $unit): bool => ! $this->hasProductionAttachment(
                $unit,
                $units,
                (int) ($reworkCutoffs[$unit['unit_key']] ?? 0),
            ))
                ->pluck('unit_key')->values()->all();
            if ($missing !== []) {
                throw new AgentApiException(
                    'PRODUCTION_FILES_MISSING',
                    'Required production files have not been uploaded for every production unit.',
                    422,
                    ['production_units' => $missing],
                );
            }

            $before = $targets->mapWithKeys(fn (Order $order): array => [$order->id => $order->status])->all();
            $this->statuses->updateGroup($targets, self::COMPLETED_STATUS, 'اكتمل الإنتاج بواسطة Agent API وأصبح جاهزًا لإرسال المعاينة.', $request);

            AdminActivityLogger::log(
                action: 'agent.checkout_production_completed',
                description: 'أكمل Agent API إنتاج عملية الشراء.',
                subject: $orders->first(),
                properties: [
                    'checkout_group_key' => $orders->first()->checkoutGroupKey(),
                    'agent_user_id' => $agent->id,
                    'previous_statuses' => $before,
                    'new_status' => self::COMPLETED_STATUS,
                    'production_unit_keys' => $units->pluck('unit_key')->all(),
                    'request_identifier' => $this->requestIdentifier($request),
                ],
                admin: $agent,
                request: $request,
            );

            return [
                'success' => true,
                'checkout_reference' => $reference,
                'checkout_group_key' => $orders->first()->checkoutGroupKey(),
                'status' => self::COMPLETED_STATUS,
                'already_completed' => false,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function startRework(string $reference, User $agent, Request $request): array
    {
        $this->assertStatusAvailable('generating');

        return DB::transaction(function () use ($reference, $agent, $request): array {
            $orders = $this->authorizedOrders($reference, $agent, true);
            $units = $this->units($orders);
            $this->assertUnitsAllowed($agent, $units);

            if ($units->isEmpty()) {
                throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This checkout has no production units.', 422);
            }

            $this->assertReworkable($orders);
            $targets = $this->targetOrders($orders, $units);
            $before = $targets->mapWithKeys(fn (Order $order): array => [$order->id => $order->status])->all();
            $alreadyStarted = $targets->every(fn (Order $order): bool => $order->status === 'generating');
            $attachmentCutoffs = $units->mapWithKeys(fn (array $unit): array => [
                $unit['unit_key'] => (int) (collect($unit['attachments'])->max('id') ?? 0),
            ])->all();

            if (! $alreadyStarted) {
                $this->statuses->updateGroup(
                    $targets,
                    'generating',
                    'بدأت إعادة إنتاج الطلب بواسطة Agent API مع الاحتفاظ بالإصدارات السابقة.',
                    $request,
                );
            }

            AdminActivityLogger::log(
                action: 'agent.checkout_rework_started',
                description: 'بدأ Agent API إعادة إنتاج عملية الشراء.',
                subject: $orders->first(),
                properties: [
                    'checkout_group_key' => $orders->first()->checkoutGroupKey(),
                    'checkout_reference' => $reference,
                    'agent_user_id' => $agent->id,
                    'previous_statuses' => $before,
                    'new_status' => 'generating',
                    'production_unit_keys' => $units->pluck('unit_key')->all(),
                    'attachment_cutoffs' => $attachmentCutoffs,
                    'previous_attachments_and_previews_preserved' => true,
                    'request_identifier' => $this->requestIdentifier($request),
                ],
                admin: $agent,
                request: $request,
            );

            return [
                'success' => true,
                'checkout_reference' => $reference,
                'checkout_group_key' => $orders->first()->checkoutGroupKey(),
                'status' => 'generating',
                'already_started' => $alreadyStarted,
                'rework_run' => [
                    'attachment_cutoffs' => $attachmentCutoffs,
                ],
            ];
        }, 3);
    }

    public function authorizedOrder(Order $order, User $agent): Order
    {
        if ($order->trashed()) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Order not found.', 404);
        }

        $assignment = OrderGroupAssignment::query()
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->first();
        if (! $assignment || $assignment->assigned_to_user_id !== $agent->id) {
            throw new AgentApiException('ORDER_NOT_ACQUIRED_BY_AGENT', 'The checkout is not acquired by this Agent.', 403);
        }

        $orders = Order::query()
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->with($this->relations())
            ->get();
        $this->assertUnitsAllowed($agent, $this->units($orders));

        return $order;
    }

    public function validateUnitForOrder(Order $order, ?string $unitKey): string
    {
        $orders = Order::query()->where('checkout_group_key', $order->checkoutGroupKey())->with($this->relations())->get();
        $units = $this->units($orders)->where('order_id', $order->id)->values();
        if ($units->isEmpty()) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This order has no production unit.', 422);
        }

        if ($unitKey === null && $units->count() === 1) {
            return $units->first()['unit_key'];
        }

        if ($unitKey === null || ! $units->contains('unit_key', $unitKey)) {
            throw new AgentApiException('INVALID_ATTACHMENT', 'A valid production_unit_key is required for this order.', 422);
        }

        return $unitKey;
    }

    public function assertPreviewTypeForOrder(Order $order, string $type): void
    {
        $orders = Order::query()->where('checkout_group_key', $order->checkoutGroupKey())->with($this->relations())->get();
        $units = $this->units($orders)->where('order_id', $order->id);

        if ($type === 'booklet' && ! $units->contains('type', 'story')) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This order has no story production unit.', 422);
        }

        if ($type === 'product_images' && ! $units->contains('type', 'product')) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This order has no product production unit.', 422);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    public function units(Collection $orders): Collection
    {
        $units = collect();

        foreach ($orders as $order) {
            $order->loadMissing($this->relations());

            if ($order->story_id && $order->story) {
                $units->push($this->storyUnit($order));
            }

            foreach (ProductProductionPrompt::forOrder($order) as $prompt) {
                $units->push($this->productUnit($order, $prompt['item'], $prompt));
            }
        }

        return $units;
    }

    /** @return Collection<int, Order> */
    private function authorizedOrders(string $reference, User $agent, bool $lock = false): Collection
    {
        $orders = $this->ordersForReference($reference, $lock);
        $checkoutGroupKey = $orders->first()->checkoutGroupKey();

        $assignment = OrderGroupAssignment::query()
            ->where('checkout_group_key', $checkoutGroupKey)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
        if (! $assignment || $assignment->assigned_to_user_id !== $agent->id) {
            throw new AgentApiException('ORDER_NOT_ACQUIRED_BY_AGENT', 'The checkout is not acquired by this Agent.', 403);
        }

        return $orders;
    }

    /** @return Collection<int, Order> */
    private function ordersForReference(string $reference, bool $lock = false): Collection
    {
        $checkout = OrderCheckoutReference::query()->where('short_reference', $reference)->first();
        if (! $checkout) {
            throw new AgentApiException('CHECKOUT_NOT_FOUND', 'Checkout not found.', 404);
        }

        $query = Order::query()->where('checkout_group_key', $checkout->checkout_group_key)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        $orders = $query->with($this->relations())->get();
        if ($orders->isEmpty()) {
            throw new AgentApiException('CHECKOUT_NOT_FOUND', 'Checkout not found.', 404);
        }

        return $orders;
    }

    private function lockedOrdersForKey(string $groupKey): Collection
    {
        return Order::query()
            ->where('checkout_group_key', $groupKey)
            ->with($this->relations())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function targetOrders(Collection $orders, Collection $units): Collection
    {
        $ids = $units->pluck('order_id')->unique()->all();

        return $orders->whereIn('id', $ids)->values();
    }

    private function storyUnit(Order $order): array
    {
        return [
            'unit_key' => 'story:'.$order->id,
            'type' => 'story',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'title' => $order->story->title,
            'language' => $order->language ?: $order->story->language,
            'production_prompt' => $this->agentSafePrompt(StoryProductionPrompt::forOrder($order), $order),
            'child' => [
                'name' => $order->child_name,
                'age' => $order->child_age,
                'gender' => $order->child_gender,
                'interests' => $order->interests,
            ],
            'notes' => array_filter([
                'parent' => $order->parent_notes,
                'order' => $order->notes,
                'dedication' => $order->gift_note,
            ]),
            'reference_files' => $this->references($order),
            'attachments' => $this->attachments($order),
            'preview' => $this->preview($order, 'booklet'),
        ];
    }

    private function productUnit(Order $order, OrderItem $item, array $prompt): array
    {
        return [
            'unit_key' => 'product:'.$item->id,
            'type' => 'product',
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'title' => $item->title,
            'sku' => $item->sku,
            'quantity' => (int) $item->quantity,
            'language' => $order->language,
            'production_prompt' => $this->agentSafePrompt($prompt['prompt'], $order),
            'prompt_source' => $prompt['uses_live_template'] ? 'live_product_template' : 'historical_snapshot',
            'personalization' => $item->personalizationDisplayValues(),
            'notes' => array_filter(['parent' => $order->parent_notes, 'order' => $order->notes]),
            'reference_files' => $this->references($order),
            'attachments' => $this->attachments($order),
            'preview' => $this->preview($order, 'product_images'),
        ];
    }

    private function references(Order $order): array
    {
        $photos = collect($order->uploaded_photos ?? [])->filter(fn ($path): bool => is_string($path))->values()
            ->map(fn (string $path, int $index): array => [
                'type' => 'child_photo',
                'name' => 'child-photo-'.($index + 1),
                'url' => route('agent.orders.references.child-photo', ['order' => $order, 'index' => $index]),
            ])->all();

        $attempt = $order->childIdentityApprovedAttempt;
        if ($attempt && $attempt->status === 'succeeded' && filled($attempt->output_storage_path)) {
            $photos[] = [
                'type' => 'approved_child_identity',
                'name' => 'approved-child-identity',
                'url' => route('agent.orders.references.approved-identity', $order),
            ];
        }

        return $photos;
    }

    private function agentSafePrompt(string $prompt, Order $order): string
    {
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));

        foreach (array_keys($photos) as $index) {
            $prompt = str_replace(
                URL::signedRoute('orders.production-photo', ['order' => $order, 'index' => $index]),
                route('agent.orders.references.child-photo', ['order' => $order, 'index' => $index]),
                $prompt,
            );
        }

        return $prompt;
    }

    private function attachments(Order $order): array
    {
        return $order->attachments
            ->reject->isExpired()
            ->filter(fn ($attachment): bool => Storage::disk($attachment->disk ?: 'local')->exists($attachment->path))
            ->map(fn ($attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => $attachment->size,
                'production_unit_key' => $attachment->production_unit_key,
                'created_at' => $attachment->created_at?->toIso8601String(),
                'expires_at' => $attachment->expires_at?->toIso8601String(),
                'url' => route('agent.orders.attachments.download', ['order' => $order, 'attachment' => $attachment]),
            ])->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function teamNotes(string $groupKey): array
    {
        return OrderAdminNote::query()
            ->where('checkout_group_key', $groupKey)
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->map(fn (OrderAdminNote $note): array => [
                'id' => $note->id,
                'body' => $note->body,
                'author' => $note->author_name,
                'created_at' => AppDateTime::display($note->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function preview(Order $order, string $type): array
    {
        if ($type === 'booklet') {
            $preview = $order->bookletPreview;

            return [
                'type' => 'booklet',
                'available' => (bool) ($preview?->current_version_id),
                'version' => $preview?->currentVersion?->version_number,
            ];
        }

        $gallery = $order->productPreviewGallery;

        return [
            'type' => 'product_images',
            'available' => (bool) ($gallery?->previews?->isNotEmpty()),
            'images_count' => $gallery?->previews?->count() ?? 0,
        ];
    }

    private function hasProductionAttachment(array $unit, Collection $allUnits, int $afterAttachmentId = 0): bool
    {
        $orderUnitCount = $allUnits->where('order_id', $unit['order_id'])->count();

        return collect($unit['attachments'])->contains(function (array $attachment) use ($unit, $orderUnitCount, $afterAttachmentId): bool {
            if ((int) $attachment['id'] <= $afterAttachmentId) {
                return false;
            }

            if ($attachment['production_unit_key'] === $unit['unit_key']) {
                return true;
            }

            return $orderUnitCount === 1 && blank($attachment['production_unit_key']);
        });
    }

    /** @return array<string, int> */
    private function latestReworkAttachmentCutoffs(string $reference, string $groupKey): array
    {
        $record = AgentApiIdempotencyKey::query()
            ->where('checkout_group_key', $groupKey)
            ->where('action', 'checkouts.start-rework:'.$reference)
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        return collect(data_get($record?->response_body, 'rework_run.attachment_cutoffs', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    private function summary(Collection $orders): array
    {
        $representative = $orders->first();
        $reference = $representative->checkoutReference?->short_reference
            ?: OrderCheckoutReference::query()->where('checkout_group_key', $representative->checkoutGroupKey())->value('short_reference');

        return [
            'reference' => $reference,
            'checkout_group' => $representative->checkoutGroupKey(),
            'assigned_to_agent_id' => $representative->groupAssignment?->assigned_to_user_id
                ?: OrderGroupAssignment::query()->where('checkout_group_key', $representative->checkoutGroupKey())->value('assigned_to_user_id'),
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
            ])->values()->all(),
        ];
    }

    private function assertStatusAvailable(string $status): void
    {
        if (! OrderStatusRegistry::isValid(OrderStatusRegistry::TYPE_ORDER, $status, true)) {
            throw new AgentApiException('INVALID_ORDER_STATUS', "Required order status [{$status}] is disabled or missing.", 503);
        }
    }

    /** @param Collection<int, Order> $orders */
    private function assertReworkable(Collection $orders): void
    {
        $blockedOrder = $orders->first(function (Order $order): bool {
            $orderBehavior = OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $order->status);
            $shippingBehavior = OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_SHIPPING, $order->shipping_status);

            return in_array($orderBehavior, ['cancelled', 'shipped', 'delivered'], true)
                || in_array($shippingBehavior, [
                    OrderWorkflowStatus::SHIPPING_SHIPMENT_CREATED,
                    OrderWorkflowStatus::SHIPPING_SHIPPED,
                    OrderWorkflowStatus::SHIPPING_DELIVERED,
                    OrderWorkflowStatus::SHIPPING_RETURNED,
                ], true);
        });

        if ($blockedOrder) {
            throw new AgentApiException(
                'CHECKOUT_NOT_REWORKABLE',
                'Cancelled, shipped, delivered, returned, or shipment-created checkouts cannot be reworked by an Agent.',
                409,
                ['order_id' => $blockedOrder->id],
            );
        }
    }

    /** @param Collection<int, array<string, mixed>> $units */
    private function assertUnitsAllowed(User $agent, Collection $units): void
    {
        if (! AgentCatalogScope::allowsEveryUnit($agent, $units)) {
            throw new AgentApiException(
                'FORBIDDEN',
                'This checkout contains production units outside the Agent token catalog scope.',
                403,
            );
        }
    }

    private function requestIdentifier(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        return $key === '' ? null : hash('sha256', $key);
    }

    private function relations(): array
    {
        return [
            'checkoutReference',
            'groupAssignment',
            'story',
            'items.product',
            'attachments',
            'bookletPreview.currentVersion',
            'productPreviewGallery.previews',
            'productionPromptOverride',
            'childIdentityApprovedAttempt',
            'childIdentityRequest',
        ];
    }
}
