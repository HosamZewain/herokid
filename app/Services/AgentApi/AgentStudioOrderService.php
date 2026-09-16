<?php

namespace App\Services\AgentApi;

use App\Exceptions\AgentApiException;
use App\Models\Order;
use App\Models\OrderCheckoutReference;
use App\Models\User;
use Illuminate\Support\Collection;

class AgentStudioOrderService
{
    public function __construct(private readonly AgentCheckoutProductionService $production) {}

    /**
     * @return array{orders: Collection<int, Order>, matched_order: Order, checkout_reference: ?string}
     */
    public function find(string $orderNumber, ?User $agent = null): array
    {
        $orderNumber = strtoupper(trim($orderNumber));

        if ($orderNumber === '') {
            throw new AgentApiException('INVALID_ORDER_NUMBER', 'A valid order number is required.', 422);
        }

        $matchedOrder = Order::query()
            ->select(['id', 'order_number', 'checkout_group_key'])
            ->where('order_number', $orderNumber)
            ->first();
        $checkoutReference = null;

        if (! $matchedOrder) {
            $checkoutReference = OrderCheckoutReference::query()
                ->select(['checkout_group_key', 'short_reference'])
                ->where('short_reference', $orderNumber)
                ->first();

            if ($checkoutReference) {
                $matchedOrder = Order::query()
                    ->select(['id', 'order_number', 'checkout_group_key'])
                    ->where('checkout_group_key', $checkoutReference->checkout_group_key)
                    ->oldest('id')
                    ->first();
            }
        }

        if (! $matchedOrder) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Order not found.', 404);
        }

        $orders = Order::query()
            ->select([
                'id', 'order_number', 'checkout_group_key', 'story_id', 'child_identity_request_id',
                'child_name', 'child_age', 'child_gender', 'language', 'lesson', 'interests',
                'gift_note', 'parent_notes', 'notes', 'uploaded_photos', 'status', 'created_at', 'updated_at',
            ])
            ->where('checkout_group_key', $matchedOrder->checkout_group_key)
            ->with([
                'checkoutReference:id,checkout_group_key,short_reference',
                'story:id,title,slug,language,gender,updated_at',
                'story.sceneTemplates:id,story_id,scene_number,title,text_template,alternate_text_template,english_male_text_template,english_female_text_template,updated_at',
                'sceneTextSnapshots:id,order_id,source_story_scene_template_id,scene_number,title_snapshot,rendered_text,selected_text_variant,render_context_snapshot,updated_at',
                'productionProject:id,order_id,updated_at',
                'productionProject.scenes:id,production_project_id,scene_number,title,story_text,updated_at',
            ])
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Order not found.', 404);
        }

        $inventory = $agent ? $this->production->readOnlyInventory($orders, $agent) : null;
        $productionUnits = $inventory['units'] ?? collect();
        if ($agent && ! $inventory['has_any_units']) {
            throw new AgentApiException('PRODUCTION_CONTEXT_INCOMPLETE', 'This checkout has no production units.', 422);
        }
        if ($agent && $productionUnits->isEmpty()) {
            throw new AgentApiException('FORBIDDEN', 'This checkout has no production units allowed by the Agent token catalog scope.', 403);
        }

        $matched = $orders->firstWhere('id', $matchedOrder->id) ?? $orders->first();
        if ($agent && ! $productionUnits->contains('order_id', $matched->id)) {
            $matched = $orders->firstWhere('id', $productionUnits->first()['order_id']);
        }

        return [
            'orders' => $orders,
            'matched_order' => $matched,
            'checkout_reference' => $checkoutReference?->short_reference
                ?? $orders->first()?->checkoutReference?->short_reference,
            'production_units' => $productionUnits,
            'inventory_visibility' => $inventory['visibility'] ?? null,
        ];
    }
}
