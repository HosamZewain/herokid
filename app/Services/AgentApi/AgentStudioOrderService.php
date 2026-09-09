<?php

namespace App\Services\AgentApi;

use App\Exceptions\AgentApiException;
use App\Models\Order;
use App\Models\OrderCheckoutReference;
use Illuminate\Support\Collection;

class AgentStudioOrderService
{
    /**
     * @return array{orders: Collection<int, Order>, matched_order: Order, checkout_reference: ?string}
     */
    public function find(string $orderNumber): array
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
                'gift_note', 'status', 'created_at', 'updated_at',
            ])
            ->where('checkout_group_key', $matchedOrder->checkout_group_key)
            ->with([
                'checkoutReference:id,checkout_group_key,short_reference',
                'story:id,title,slug,language,gender,updated_at',
                'story.sceneTemplates:id,story_id,scene_number,title,text_template,alternate_text_template,updated_at',
                'sceneTextSnapshots:id,order_id,source_story_scene_template_id,scene_number,title_snapshot,rendered_text,selected_text_variant,render_context_snapshot,updated_at',
                'productionProject:id,order_id,updated_at',
                'productionProject.scenes:id,production_project_id,scene_number,title,story_text,updated_at',
            ])
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            throw new AgentApiException('ORDER_NOT_FOUND', 'Order not found.', 404);
        }

        return [
            'orders' => $orders,
            'matched_order' => $orders->firstWhere('id', $matchedOrder->id) ?? $orders->first(),
            'checkout_reference' => $checkoutReference?->short_reference
                ?? $orders->first()?->checkoutReference?->short_reference,
        ];
    }
}
