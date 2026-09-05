<?php

namespace App\Services\AgentApi;

use App\Exceptions\AgentApiException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderDetailsUpdateService;
use App\Support\AdminActivityLogger;
use App\Support\ProductPersonalizationSchema;
use App\Support\StoryAgeOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AgentOrderPersonalizationService
{
    private const STORY_FIELDS = [
        'child_name',
        'child_age',
        'child_gender',
        'language',
        'interests',
        'gift_note',
        'parent_notes',
    ];

    private const PRODUCT_FIELDS = [
        'child_name',
        'school_name',
        'class_name',
        'child_age',
        'child_gender',
        'interests',
        'parent_notes',
        'language',
    ];

    public function __construct(
        private readonly AgentCheckoutProductionService $production,
        private readonly OrderDetailsUpdateService $details,
    ) {}

    /** @return array<string, mixed> */
    public function update(
        Order $order,
        string $unitKey,
        array $personalization,
        string $reason,
        User $agent,
        Request $request,
    ): array {
        $this->production->authorizedOrder($order, $agent);
        $this->production->validateUnitForOrder($order, $unitKey);

        $unit = $this->productionUnitsForOrder($order)
            ->firstWhere('unit_key', $unitKey);
        if (! $unit) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'The requested production unit does not exist.', 404);
        }

        return $unit['type'] === 'story'
            ? $this->updateStory($order, $unitKey, $personalization, $reason, $agent, $request)
            : $this->updateProduct($order, $unitKey, $personalization, $reason, $agent, $request);
    }

    /** @return array<string, mixed> */
    private function updateStory(
        Order $order,
        string $unitKey,
        array $personalization,
        string $reason,
        User $agent,
        Request $request,
    ): array {
        $this->assertAllowedKeys($personalization, self::STORY_FIELDS);
        $validated = $this->validatePersonalization($personalization, true);
        $current = $order->fresh();
        $values = [
            'parent_name' => $current->parent_name,
            'phone' => (string) data_get($current->delivery_details, 'phone', ''),
            'child_name' => $current->child_name,
            'child_age' => $current->child_age,
            'child_gender' => $current->child_gender,
            'language' => $current->language,
            'lesson' => $current->lesson,
            'interests' => $current->interests,
            'gift_note' => $current->gift_note,
            'parent_notes' => $current->parent_notes,
            'change_reason' => $reason,
            ...$validated,
        ];

        $result = $this->details->update($current, $values, $agent, $request);

        return $this->response($order, $unitKey, $result['changes'], $reason, $agent, $request);
    }

    /** @return array<string, mixed> */
    private function updateProduct(
        Order $order,
        string $unitKey,
        array $personalization,
        string $reason,
        User $agent,
        Request $request,
    ): array {
        $this->assertAllowedKeys($personalization, self::PRODUCT_FIELDS);
        $validated = $this->validatePersonalization($personalization, false);
        $itemId = (int) str($unitKey)->after('product:')->toString();

        $changes = DB::transaction(function () use ($order, $itemId, $validated, $reason, $agent, $request): array {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
            $item = OrderItem::query()
                ->with('product')
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->find($itemId);
            if (! $item) {
                throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'The requested product unit does not exist.', 404);
            }

            $beforeSnapshot = $item->personalization_snapshot ?? [];
            $snapshot = $beforeSnapshot;
            $schema = is_array($snapshot['schema'] ?? null)
                ? ProductPersonalizationSchema::normalize($snapshot['schema'])
                : ($item->product ? ProductPersonalizationSchema::forProduct($item->product) : ProductPersonalizationSchema::empty());
            $enabledFields = ProductPersonalizationSchema::enabledFields($schema);

            foreach (Arr::except($validated, ['language']) as $key => $value) {
                $snapshot[$key] = $value;

                if (is_array(data_get($snapshot, 'fields.'.$key))) {
                    data_set($snapshot, 'fields.'.$key.'.value', $value);
                } elseif (isset($enabledFields[$key])) {
                    data_set($snapshot, 'fields.'.$key, [
                        'label' => $enabledFields[$key]['label'],
                        'type' => $enabledFields[$key]['type'],
                        'required' => $enabledFields[$key]['required'],
                        'value' => $value,
                    ]);
                }
            }

            $item->forceFill(['personalization_snapshot' => $snapshot])->save();

            $orderMirrorFields = Arr::only($validated, [
                'child_name',
                'child_age',
                'child_gender',
                'language',
                'interests',
                'parent_notes',
            ]);
            if ($orderMirrorFields !== []) {
                $this->details->update($lockedOrder, [
                    'parent_name' => $lockedOrder->parent_name,
                    'phone' => (string) data_get($lockedOrder->delivery_details, 'phone', ''),
                    'child_name' => $lockedOrder->child_name,
                    'child_age' => $lockedOrder->child_age,
                    'child_gender' => $lockedOrder->child_gender,
                    'language' => $lockedOrder->language,
                    'lesson' => $lockedOrder->lesson,
                    'interests' => $lockedOrder->interests,
                    'gift_note' => $lockedOrder->gift_note,
                    'parent_notes' => $lockedOrder->parent_notes,
                    'change_reason' => $reason,
                    ...$orderMirrorFields,
                ], $agent, $request);
            }

            return AdminActivityLogger::changedValues(
                ['personalization_snapshot' => $beforeSnapshot, 'language' => $lockedOrder->getOriginal('language')],
                ['personalization_snapshot' => $snapshot, 'language' => $lockedOrder->fresh()->language],
            );
        });

        return $this->response($order, $unitKey, $changes, $reason, $agent, $request);
    }

    /** @return array<string, mixed> */
    private function response(
        Order $order,
        string $unitKey,
        array $changes,
        string $reason,
        User $agent,
        Request $request,
    ): array {
        $updatedUnit = $this->productionUnitsForOrder($order->fresh())->firstWhere('unit_key', $unitKey);

        AdminActivityLogger::log(
            action: 'agent.order_personalization_updated',
            description: 'عدّل Agent API بيانات تخصيص وحدة إنتاج.',
            subject: $order,
            properties: [
                'agent_user_id' => $agent->id,
                'checkout_group_key' => $order->checkoutGroupKey(),
                'production_unit_key' => $unitKey,
                'reason' => $reason,
                'changes' => $changes,
                'previous_attachments_and_previews_preserved' => true,
                'request_identifier' => hash('sha256', (string) $request->header('Idempotency-Key')),
            ],
            admin: $agent,
            request: $request,
        );

        return [
            'success' => true,
            'order_id' => $order->id,
            'production_unit_key' => $unitKey,
            'changes' => $changes,
            'production_unit' => $updatedUnit,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function productionUnitsForOrder(Order $order): Collection
    {
        $orders = Order::query()
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->get();

        return $this->production->units($orders)->where('order_id', $order->id)->values();
    }

    private function assertAllowedKeys(array $values, array $allowed): void
    {
        if ($values === []) {
            throw new AgentApiException('INVALID_PERSONALIZATION', 'At least one personalization field is required.', 422);
        }

        $unsupported = array_values(array_diff(array_keys($values), $allowed));
        if ($unsupported !== []) {
            throw new AgentApiException(
                'INVALID_PERSONALIZATION',
                'The request contains unsupported personalization fields.',
                422,
                ['unsupported_fields' => $unsupported, 'allowed_fields' => $allowed],
            );
        }
    }

    /** @return array<string, mixed> */
    private function validatePersonalization(array $values, bool $story): array
    {
        $rules = [
            'child_name' => ['sometimes', $story ? 'required' : 'nullable', 'string', 'max:100'],
            'school_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'class_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'child_age' => ['sometimes', $story ? 'required' : 'nullable', 'integer', Rule::in(StoryAgeOptions::forPersonalization())],
            'child_gender' => ['sometimes', $story ? 'required' : 'nullable', Rule::in(['boy', 'girl'])],
            'language' => ['sometimes', 'nullable', Rule::in(['ar', 'en'])],
            'interests' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'gift_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'parent_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
        $validator = Validator::make($values, $rules);
        if ($validator->fails()) {
            throw new AgentApiException(
                'INVALID_PERSONALIZATION',
                'The personalization data is invalid.',
                422,
                ['validation' => $validator->errors()->toArray()],
            );
        }

        return $validator->validated();
    }
}
