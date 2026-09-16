<?php

namespace App\Services\AgentApi;

use App\Exceptions\AgentApiException;
use App\Models\AgentApiIdempotencyKey;
use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\OrderAttachment;
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
            ->lazy(50);

        foreach ($candidates as $candidate) {
            $groupKey = (string) $candidate->checkout_group_key;
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

    /** @return array<string, mixed> */
    public function queueDiagnostics(User $agent): array
    {
        $groupKeys = Order::query()
            ->where('status', 'new')
            ->whereNotNull('checkout_group_key')
            ->distinct()
            ->pluck('checkout_group_key')
            ->map(fn ($key): string => (string) $key)
            ->values();

        $summary = [
            'token_catalog_scope' => AgentCatalogScope::forUser($agent),
            'token_product_ids' => AgentProductScope::forUser($agent),
            'new_checkout_groups' => $groupKeys->count(),
            'eligible_now' => 0,
            'already_acquired' => 0,
            'without_production_units' => 0,
            'outside_token_scope' => 0,
            'mixed_production_status' => 0,
        ];

        foreach ($groupKeys->chunk(100) as $chunk) {
            $assigned = OrderGroupAssignment::query()
                ->whereIn('checkout_group_key', $chunk)
                ->pluck('checkout_group_key')
                ->mapWithKeys(fn ($key): array => [(string) $key => true]);
            $ordersByGroup = Order::query()
                ->whereIn('checkout_group_key', $chunk)
                ->with($this->relations())
                ->orderBy('id')
                ->get()
                ->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

            foreach ($chunk as $groupKey) {
                if ($assigned->has($groupKey)) {
                    $summary['already_acquired']++;

                    continue;
                }

                $orders = $ordersByGroup->get($groupKey, collect());
                $units = $this->units($orders);
                if ($units->isEmpty()) {
                    $summary['without_production_units']++;

                    continue;
                }

                if (! AgentCatalogScope::allowsEveryUnit($agent, $units)) {
                    $summary['outside_token_scope']++;

                    continue;
                }

                $targets = $this->targetOrders($orders, $units);
                if ($targets->contains(fn (Order $order): bool => $order->status !== 'new')) {
                    $summary['mixed_production_status']++;

                    continue;
                }

                $summary['eligible_now']++;
            }
        }

        $summary['partial_product_work'] = $this->partialProductWork($agent);

        return $summary;
    }

    /** Discover permitted products in mixed checkouts without acquiring them. */
    public function partialProductWork(User $agent): array
    {
        $groupKeys = Order::query()
            ->selectRaw('checkout_group_key, MIN(created_at) as first_created_at, MIN(id) as first_order_id')
            ->whereIn('status', ['new', 'generating'])
            ->whereNotNull('checkout_group_key')
            ->groupBy('checkout_group_key')
            ->orderBy('first_created_at')
            ->orderBy('first_order_id')
            ->pluck('checkout_group_key');
        $candidates = [];
        $count = 0;

        foreach ($groupKeys->chunk(100) as $chunk) {
            $assignedTo = OrderGroupAssignment::query()
                ->whereIn('checkout_group_key', $chunk)
                ->pluck('assigned_to_user_id', 'checkout_group_key');
            $ordersByGroup = Order::query()
                ->whereIn('checkout_group_key', $chunk)
                ->with($this->relations())
                ->orderBy('id')
                ->get()
                ->groupBy(fn (Order $order): string => $order->checkoutGroupKey());

            foreach ($chunk as $groupKey) {
                if ($assignedTo->has($groupKey) && (int) $assignedTo->get($groupKey) !== $agent->id) {
                    continue;
                }

                $allUnits = $this->units($ordersByGroup->get($groupKey, collect()));
                $authorized = AgentCatalogScope::filterUnits($agent, $allUnits);
                if ($authorized->count() === $allUnits->count()) {
                    continue;
                }

                $products = $authorized
                    ->where('type', 'product')
                    ->filter(fn (array $unit): bool => in_array($unit['status'], ['new', 'generating'], true)
                        && filled($unit['production_prompt']))
                    ->values();
                if ($products->isEmpty()) {
                    continue;
                }

                $count++;
                if (count($candidates) < 25) {
                    $candidates[] = [
                        'order_number' => $products->first()['order_number'],
                        'production_unit_keys' => $products->pluck('unit_key')->all(),
                    ];
                }
            }
        }

        return [
            'checkout_count' => $count,
            'checkouts' => $candidates,
            'has_more' => $count > count($candidates),
        ];
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

    public function authorizeAttachmentUpload(Order $order, User $agent, ?string $unitKey): string
    {
        $selection = $this->allowedUnitForOrder($order, $agent, $unitKey);
        if ($selection['partial_product']) {
            if (! in_array($order->status, ['new', 'generating'], true)) {
                throw new AgentApiException('FORBIDDEN', 'Production attachments are not accepted for this order status.', 403);
            }
            $assignedTo = OrderGroupAssignment::query()
                ->where('checkout_group_key', $order->checkoutGroupKey())
                ->value('assigned_to_user_id');
            if ($assignedTo !== null && (int) $assignedTo !== $agent->id) {
                throw new AgentApiException('ORDER_NOT_ACQUIRED_BY_AGENT', 'The checkout is acquired by another Agent.', 403);
            }
        } else {
            $this->authorizedOrder($order, $agent);
        }

        return $selection['unit']['unit_key'];
    }

    /** Validate a unit key after the caller has enforced whole-checkout authorization. */
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

    public function authorizeReferenceRead(Order $order, User $agent, ?string $unitKey): void
    {
        if ($unitKey !== null && $this->allowedUnitForOrder($order, $agent, $unitKey)['partial_product']) {
            return;
        }

        $this->authorizedOrder($order, $agent);
    }

    public function authorizeAttachmentRead(Order $order, User $agent, OrderAttachment $attachment): void
    {
        if (filled($attachment->production_unit_key)
            && $this->allowedUnitForOrder($order, $agent, $attachment->production_unit_key)['partial_product']) {
            return;
        }

        $this->authorizedOrder($order, $agent);
    }

    public function authorizePreviewUpload(Order $order, User $agent, string $type, ?string $unitKey = null): string
    {
        $unitType = $type === 'booklet' ? 'story' : 'product';

        return $this->allowedUnitForOrder($order, $agent, $unitKey, $unitType)['unit']['unit_key'];
    }

    /** @return array{unit: array<string, mixed>, partial_product: bool} */
    private function allowedUnitForOrder(Order $order, User $agent, ?string $unitKey, ?string $type = null): array
    {
        if ($order->trashed()) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Order not found.', 404);
        }

        $orders = Order::query()->where('checkout_group_key', $order->checkoutGroupKey())->with($this->relations())->get();
        $allUnits = $this->units($orders);
        $orderUnits = $allUnits->where('order_id', $order->id)
            ->when($type !== null, fn (Collection $units): Collection => $units->where('type', $type))
            ->values();

        if ($orderUnits->isEmpty()) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This order has no production unit of the requested type.', 422);
        }

        $allowedUnits = AgentCatalogScope::filterUnits($agent, $orderUnits);
        if ($unitKey === null && $orderUnits->count() > 1 && $allowedUnits->count() !== 1) {
            throw new AgentApiException('INVALID_ATTACHMENT', 'A valid production_unit_key is required for this order.', 422);
        }

        $unit = $unitKey === null
            ? ($orderUnits->count() === 1 ? $orderUnits->first() : $allowedUnits->first())
            : $orderUnits->firstWhere('unit_key', $unitKey);

        if (! $unit) {
            throw new AgentApiException('INVALID_ATTACHMENT', 'A valid production_unit_key is required for this order.', 422);
        }

        if (! $allowedUnits->contains('unit_key', $unit['unit_key'])) {
            throw new AgentApiException('FORBIDDEN', 'This production unit is outside the Agent token catalog scope.', 403);
        }

        return [
            'unit' => $unit,
            'partial_product' => $unit['type'] === 'product'
                && AgentCatalogScope::filterUnits($agent, $allUnits)->count() !== $allUnits->count(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function units(Collection $orders): Collection
    {
        $units = collect();

        foreach ($orders as $order) {
            $order->loadMissing($this->relations());
            $productPrompts = ProductProductionPrompt::forOrder($order);
            $hasStoryUnit = (bool) ($order->story_id && $order->story);
            $orderUnitCount = $productPrompts->count() + (int) $hasStoryUnit;

            if ($hasStoryUnit) {
                $units->push($this->storyUnit($order, $orderUnitCount));
            }

            foreach ($productPrompts as $prompt) {
                $units->push($this->productUnit($order, $prompt['item'], $prompt, $orderUnitCount));
            }
        }

        return $units;
    }

    /** Build and filter the canonical inventory without assignment checks. */
    public function readOnlyInventory(Collection $orders, User $agent): array
    {
        $orders->loadMissing($this->relations());
        $allUnits = $this->units($orders);
        $authorized = AgentCatalogScope::filterUnits($agent, $allUnits);

        return [
            'units' => $authorized,
            'visibility' => [
                'catalog_scope' => AgentCatalogScope::forUser($agent),
                'product_restricted' => AgentProductScope::forUser($agent) !== [],
                'filtered' => $authorized->count() !== $allUnits->count(),
                'returned_unit_count' => $authorized->count(),
            ],
            'has_any_units' => $allUnits->isNotEmpty(),
        ];
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

    private function storyUnit(Order $order, int $orderUnitCount): array
    {
        $unitKey = 'story:'.$order->id;

        return [
            'unit_key' => $unitKey,
            'type' => 'story',
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'title' => $order->story->title,
            'language' => $order->language ?: $order->story->language,
            'production_prompt' => $this->agentSafePrompt(StoryProductionPrompt::forOrder($order), $order, $unitKey),
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
            'reference_files' => $this->references($order, $unitKey),
            'attachments' => $this->attachments($order, $unitKey, $orderUnitCount),
            'preview' => $this->preview($order, 'booklet'),
        ];
    }

    private function productUnit(Order $order, OrderItem $item, array $prompt, int $orderUnitCount): array
    {
        return [
            'unit_key' => $prompt['unit_key'],
            'type' => 'product',
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'title' => $prompt['component_count'] > 1
                ? $item->title.' — '.$prompt['component_name']
                : $item->title,
            'sku' => $item->sku,
            'quantity' => $prompt['quantity'],
            'product_quantity' => (int) $item->quantity,
            'production_component' => [
                'key' => $prompt['component_key'],
                'name' => $prompt['component_name'],
                'quantity_per_item' => $prompt['quantity_per_item'],
            ],
            'language' => $order->language,
            'production_prompt' => $this->agentSafePrompt($prompt['prompt'], $order, $prompt['unit_key']),
            'prompt_source' => $prompt['prompt_source'],
            'personalization' => $item->personalizationDisplayValues(),
            'notes' => array_filter(['parent' => $order->parent_notes, 'order' => $order->notes]),
            'reference_files' => $this->references($order, $prompt['unit_key']),
            'attachments' => $this->attachments($order, $prompt['unit_key'], $orderUnitCount),
            'preview' => $this->preview($order, 'product_images', $prompt['unit_key'], $orderUnitCount),
        ];
    }

    private function references(Order $order, string $unitKey): array
    {
        $photos = collect($order->uploaded_photos ?? [])->filter(fn ($path): bool => is_string($path))->values()
            ->map(fn (string $path, int $index): array => [
                'type' => 'child_photo',
                'name' => 'child-photo-'.($index + 1),
                'url' => route('agent.orders.references.child-photo', ['order' => $order, 'index' => $index, 'production_unit_key' => $unitKey]),
            ])->all();

        $attempt = $order->childIdentityApprovedAttempt;
        if ($attempt && $attempt->status === 'succeeded' && filled($attempt->output_storage_path)) {
            $photos[] = [
                'type' => 'approved_child_identity',
                'name' => 'approved-child-identity',
                'url' => route('agent.orders.references.approved-identity', ['order' => $order, 'production_unit_key' => $unitKey]),
            ];
        }

        return $photos;
    }

    private function agentSafePrompt(string $prompt, Order $order, string $unitKey): string
    {
        $photos = array_values(array_filter($order->uploaded_photos ?? [], 'is_string'));

        foreach (array_keys($photos) as $index) {
            $prompt = str_replace(
                URL::signedRoute('orders.production-photo', ['order' => $order, 'index' => $index]),
                route('agent.orders.references.child-photo', ['order' => $order, 'index' => $index, 'production_unit_key' => $unitKey]),
                $prompt,
            );
        }

        return $prompt;
    }

    private function attachments(Order $order, string $unitKey, int $orderUnitCount): array
    {
        return $order->attachments
            ->reject->isExpired()
            ->filter(fn ($attachment): bool => $attachment->production_unit_key === $unitKey
                || ($orderUnitCount === 1 && blank($attachment->production_unit_key)))
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

    private function preview(Order $order, string $type, ?string $unitKey = null, int $orderUnitCount = 1): array
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
        $previews = $gallery?->previews?->filter(fn ($preview): bool => (int) $preview->order_id === (int) $order->id
            && ($preview->production_unit_key === $unitKey
                || ($orderUnitCount === 1 && blank($preview->production_unit_key))));

        return [
            'type' => 'product_images',
            'available' => (bool) ($previews?->isNotEmpty()),
            'images_count' => $previews?->count() ?? 0,
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
            'items.product.productionComponents',
            'items.productionComponents',
            'attachments',
            'bookletPreview.currentVersion',
            'productPreviewGallery.previews',
            'productionPromptOverride',
            'childIdentityApprovedAttempt',
            'childIdentityRequest',
        ];
    }
}
