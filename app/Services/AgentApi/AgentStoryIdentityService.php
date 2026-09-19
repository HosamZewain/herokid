<?php

namespace App\Services\AgentApi;

use App\Exceptions\AgentApiException;
use App\Models\Order;
use App\Models\OrderCheckoutReference;
use App\Models\User;
use App\Services\Orders\OrderChildIdentityPromptService;
use App\Services\Orders\OrderStatusService;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class AgentStoryIdentityService
{
    public const COMPLETED_STATUS = 'waiting_customer';

    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly OrderChildIdentityPromptService $identityPrompts,
    ) {}

    /** @return array<string, mixed>|null */
    public function acquireNext(User $agent, Request $request): ?array
    {
        $this->assertStoryScope($agent);
        $this->assertStatusAvailable('under_review');

        $candidates = Order::query()
            ->selectRaw('checkout_group_key, MIN(created_at) as first_created_at, MIN(id) as first_order_id')
            ->whereNotNull('story_id')
            ->where('status', 'new')
            ->whereNotNull('checkout_group_key')
            ->groupBy('checkout_group_key')
            ->orderBy('first_created_at')
            ->orderBy('first_order_id')
            ->lazy(50);

        foreach ($candidates as $candidate) {
            $result = DB::transaction(function () use ($candidate, $agent, $request): ?array {
                $orders = $this->ordersForKey((string) $candidate->checkout_group_key, true);
                $stories = $this->storyOrders($orders);
                if ($stories->isEmpty() || $orders->contains(fn (Order $order): bool => $order->status !== 'new')) {
                    return null;
                }

                $missingIdentities = $stories->reject(fn (Order $order): bool => $this->hasIdentity($order));
                if ($missingIdentities->isEmpty() || $missingIdentities->contains(fn (Order $order): bool => $this->photoPaths($order) === [])) {
                    return null;
                }

                $this->statuses->updateGroup(
                    $orders,
                    'under_review',
                    'بدأ Agent API تجهيز هويات القصص دون تغيير مسؤول الموظف.',
                    $request,
                );
                AdminActivityLogger::log(
                    action: 'agent.story_identity_work_started',
                    description: 'بدأ Agent API تنفيذ هويات القصص دون استحواذ.',
                    subject: $orders->first(),
                    properties: [
                        'checkout_group_key' => $orders->first()->checkoutGroupKey(),
                        'agent_user_id' => $agent->id,
                        'story_order_ids' => $stories->pluck('id')->all(),
                        'identity_order_ids' => $missingIdentities->pluck('id')->all(),
                        'deferred_order_ids' => $orders->whereNull('story_id')->pluck('id')->all(),
                        'previous_status' => 'new',
                        'new_status' => 'under_review',
                        'assignment_changed' => false,
                        'request_identifier' => $this->requestIdentifier($request),
                    ],
                    admin: $agent,
                    request: $request,
                );

                return $this->summary(
                    $orders->map(fn (Order $order): Order => $order->fresh()),
                    $missingIdentities,
                );
            }, 3);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function context(string $reference, User $agent): array
    {
        $orders = $this->authorizedOrders($reference, $agent);
        $stories = $this->storyOrders($orders);
        if ($stories->isEmpty()) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This checkout has no story identity units.', 422);
        }

        $missingReferences = $stories
            ->reject(fn (Order $order): bool => $this->hasIdentity($order))
            ->filter(fn (Order $order): bool => $this->photoPaths($order) === [])
            ->pluck('id')->all();
        if ($missingReferences !== []) {
            throw new AgentApiException(
                'PRODUCTION_CONTEXT_INCOMPLETE',
                'Original child photos are missing for one or more story identities.',
                422,
                ['order_ids' => $missingReferences],
            );
        }

        return [
            'success' => true,
            'workflow' => 'story_identity_only',
            'checkout' => $this->summary($orders, $stories->reject(fn (Order $order): bool => $this->hasIdentity($order))),
            'identity_units' => $stories->map(fn (Order $order): array => $this->identityUnit($order))->values()->all(),
            'deferred_units' => $this->deferredUnits($orders),
            'next_action' => 'Upload one identity image for every identity unit that has identity.available=false, then complete identity.',
        ];
    }

    public function authorizedStoryOrder(Order $order, User $agent, bool $forMutation = false): Order
    {
        $this->assertStoryScope($agent);
        if ($order->trashed() || ! $order->story_id) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Story order not found.', 404);
        }

        if ($forMutation && ! in_array($order->status, ['new', 'under_review'], true)) {
            throw new AgentApiException('INVALID_ORDER_STATUS', 'Story identity files can only be changed while identity work is active.', 409);
        }

        return $order;
    }

    /** @return array<string, mixed> */
    public function complete(string $reference, User $agent, Request $request): array
    {
        $this->assertStatusAvailable(self::COMPLETED_STATUS);

        return DB::transaction(function () use ($reference, $agent, $request): array {
            $orders = $this->authorizedOrders($reference, $agent, true);
            $stories = $this->storyOrders($orders);
            if ($stories->isEmpty()) {
                throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This checkout has no story identity units.', 422);
            }

            if ($orders->every(fn (Order $order): bool => $order->status === self::COMPLETED_STATUS)) {
                return $this->completionResponse($reference, $orders, true);
            }

            if ($orders->contains(fn (Order $order): bool => ! in_array($order->status, ['new', 'under_review'], true))) {
                throw new AgentApiException('INVALID_ORDER_STATUS', 'Every checkout order must be in new or identity-review status.', 409);
            }

            $missing = $stories->reject(fn (Order $order): bool => $this->hasIdentity($order))->pluck('id')->all();
            if ($missing !== []) {
                throw new AgentApiException(
                    'IDENTITY_FILES_MISSING',
                    'An identity image must be uploaded for every story in the checkout.',
                    422,
                    ['order_ids' => $missing],
                );
            }

            $before = $orders->mapWithKeys(fn (Order $order): array => [$order->id => $order->status])->all();
            $this->statuses->updateGroup(
                $orders,
                self::COMPLETED_STATUS,
                'اكتملت هويات القصص بواسطة Agent API والطلب بانتظار العميل.',
                $request,
            );

            AdminActivityLogger::log(
                action: 'agent.story_identity_checkout_completed',
                description: 'أكمل Agent API هويات كل القصص ونقل عملية الشراء لانتظار العميل.',
                subject: $orders->first(),
                properties: [
                    'checkout_group_key' => $orders->first()->checkoutGroupKey(),
                    'agent_user_id' => $agent->id,
                    'story_order_ids' => $stories->pluck('id')->all(),
                    'previous_statuses' => $before,
                    'new_status' => self::COMPLETED_STATUS,
                    'deferred_units' => $this->deferredUnits($orders),
                    'request_identifier' => $this->requestIdentifier($request),
                ],
                admin: $agent,
                request: $request,
            );

            return $this->completionResponse($reference, $orders, false);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function identityUnit(Order $order): array
    {
        $identity = $order->childIdentityApprovedAttempt;

        return [
            'unit_key' => 'identity:'.$order->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'story' => ['id' => $order->story_id, 'title' => $order->story?->title],
            'child' => [
                'name' => $order->child_name,
                'age' => $order->child_age,
                'gender' => $order->child_gender,
                'interests' => $order->interests,
            ],
            'identity_prompt' => $this->agentSafePrompt($this->identityPrompts->forOrder($order), $order),
            'reference_files' => collect($this->photoPaths($order))->keys()->map(fn (int $index): array => [
                'type' => 'child_photo',
                'name' => 'child-photo-'.($index + 1),
                'url' => route('agent.orders.identity-references.child-photo', ['order' => $order, 'index' => $index]),
            ])->all(),
            'identity' => [
                'available' => $this->hasIdentity($order),
                'attempt_id' => $identity?->id,
                'url' => $this->hasIdentity($order) ? route('agent.orders.identity-references.image', $order) : null,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function deferredUnits(Collection $orders): array
    {
        return $orders->flatMap(fn (Order $order) => $order->items
            ->whereIn('item_type', ['product', 'product_add_on'])
            ->map(fn ($item): array => [
                'type' => 'product',
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'title' => $item->title,
                'quantity' => (int) $item->quantity,
                'instruction' => 'DEFERRED_DO_NOT_PROCESS_IN_IDENTITY_WORKFLOW',
            ]))->values()->all();
    }

    private function storyOrders(Collection $orders): Collection
    {
        return $orders->filter(fn (Order $order): bool => $order->story_id !== null && $order->story !== null)->values();
    }

    private function hasIdentity(Order $order): bool
    {
        $attempt = $order->childIdentityApprovedAttempt;

        return $attempt !== null
            && $attempt->status === 'succeeded'
            && filled($attempt->output_storage_path)
            && Storage::disk($attempt->output_disk ?: 'local')->exists($attempt->output_storage_path);
    }

    /** @return array<int, string> */
    private function photoPaths(Order $order): array
    {
        return array_values(array_filter($order->uploaded_photos ?? [], fn ($path): bool => is_string($path) && ! str_contains($path, '..')));
    }

    private function agentSafePrompt(string $prompt, Order $order): string
    {
        foreach (array_keys($this->photoPaths($order)) as $index) {
            $prompt = str_replace(
                URL::signedRoute('orders.production-photo', ['order' => $order, 'index' => $index]),
                route('agent.orders.identity-references.child-photo', ['order' => $order, 'index' => $index]),
                $prompt,
            );
        }

        return $prompt;
    }

    private function authorizedOrders(string $reference, User $agent, bool $lock = false): Collection
    {
        $this->assertStoryScope($agent);
        $checkout = OrderCheckoutReference::query()->where('short_reference', $reference)->first();
        if (! $checkout) {
            throw new AgentApiException('CHECKOUT_NOT_FOUND', 'Checkout not found.', 404);
        }

        return $this->ordersForKey($checkout->checkout_group_key, $lock);
    }

    private function ordersForKey(string $groupKey, bool $lock = false): Collection
    {
        $query = Order::query()->where('checkout_group_key', $groupKey)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->with([
            'checkoutReference', 'groupAssignment', 'story.sceneTemplates', 'sceneTextSnapshots',
            'childIdentityApprovedAttempt', 'items.product',
        ])->get();
    }

    /** @return array<string, mixed> */
    private function summary(Collection $orders, Collection $missingIdentities): array
    {
        $first = $orders->first();

        return [
            'reference' => $first->checkoutReference?->short_reference,
            'checkout_group' => $first->checkoutGroupKey(),
            'assigned_to_agent_id' => null,
            'assignment_changed' => false,
            'story_orders_count' => $this->storyOrders($orders)->count(),
            'identities_required' => $missingIdentities->count(),
            'deferred_products_count' => count($this->deferredUnits($orders)),
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'contains_story' => $order->story_id !== null,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function completionResponse(string $reference, Collection $orders, bool $alreadyCompleted): array
    {
        return [
            'success' => true,
            'workflow' => 'story_identity_only',
            'checkout_reference' => $reference,
            'checkout_group_key' => $orders->first()->checkoutGroupKey(),
            'status' => self::COMPLETED_STATUS,
            'already_completed' => $alreadyCompleted,
            'story_identities_count' => $this->storyOrders($orders)->count(),
            'deferred_products_count' => count($this->deferredUnits($orders)),
        ];
    }

    private function assertStoryScope(User $agent): void
    {
        if (! AgentCatalogScope::allows($agent, 'story')) {
            throw new AgentApiException('FORBIDDEN', 'This Agent token cannot process story identities.', 403);
        }
    }

    private function assertStatusAvailable(string $status): void
    {
        if (! OrderStatusRegistry::isValid(OrderStatusRegistry::TYPE_ORDER, $status, true)) {
            throw new AgentApiException('INVALID_ORDER_STATUS', "Required order status [{$status}] is disabled or missing.", 503);
        }
    }

    private function requestIdentifier(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        return $key === '' ? null : hash('sha256', $key);
    }
}
