<?php

namespace App\Services\Mobile;

use App\Models\Order;
use App\Support\OrderStatusRegistry;

class MobileOrderPresenter
{
    public function summary(Order $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->order_number,
            'checkout_group' => $order->checkoutGroupKey(),
            'customer_status' => $this->customerStatus($order),
            'payment_status' => $order->payment_status ?: 'unpaid',
            'paid_amount' => ((int) $order->paid_amount_cents) / 100,
            'child_name' => $order->child_name,
            'product_count' => $order->items->sum('quantity'),
            'total' => (float) data_get($order->delivery_details, 'total', $order->items->sum('total_price') + (float) data_get($order->delivery_details, 'delivery_fee', 0)),
            'currency' => 'EGP',
            'created_at' => $order->created_at?->toISOString(),
            'updated_at' => $order->updated_at?->toISOString(),
        ];
    }

    public function detail(Order $order): array
    {
        return array_merge($this->summary($order), [
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'type' => $item->item_type,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'unit_price' => ((int) $item->unit_price_cents) / 100,
                'total_price' => ((int) $item->total_price_cents) / 100,
                'personalization' => $this->safePersonalization($item->personalization_snapshot),
            ])->values(),
            'delivery' => [
                'country' => data_get($order->delivery_details, 'country'),
                'governorate' => data_get($order->delivery_details, 'governorate'),
                'city' => data_get($order->delivery_details, 'city'),
                'street' => data_get($order->delivery_details, 'street'),
                'address_details' => data_get($order->delivery_details, 'address_details'),
                'estimated_delivery_date' => data_get($order->delivery_details, 'estimated_delivery_date'),
            ],
            'timeline' => $order->statusLogs->map(fn ($log): array => [
                'status' => $this->mapStatus($log->status),
                'source_status' => $log->status,
                'message' => $log->notes,
                'at' => $log->created_at?->toISOString(),
            ])->values(),
            'production' => $order->productionProject ? [
                'stage' => $order->productionProject->current_stage,
                'status' => $order->productionProject->status,
            ] : null,
            'can_reorder' => in_array(
                OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $order->status),
                ['delivered', 'cancelled'],
                true,
            ),
            'support_context' => ['order_number' => $order->order_number],
        ]);
    }

    public function customerStatus(Order $order): string
    {
        if ($order->status === 'new' && $order->payment_status === 'unpaid' && data_get($order->delivery_details, 'payment_required', false)) {
            return 'awaiting_payment';
        }

        return $this->mapStatus($order->status);
    }

    private function mapStatus(string $status): string
    {
        $behavior = OrderStatusRegistry::behavior(OrderStatusRegistry::TYPE_ORDER, $status);
        if (in_array($behavior, ['shipped', 'delivered', 'cancelled'], true)) {
            return $behavior;
        }

        return match ($status) {
            'new', 'under_review' => 'under_review',
            'generating' => 'content_production',
            'preview_uploaded' => 'design_ready_for_approval',
            'revision_requested' => 'revision_requested',
            'approved_for_print' => 'approved_for_printing',
            'printing' => 'printing',
            'shipped' => 'shipped',
            'delivered' => 'delivered',
            'cancelled' => 'cancelled',
            default => 'under_review',
        };
    }

    private function safePersonalization(?array $snapshot): ?array
    {
        if (! $snapshot) {
            return null;
        }

        return collect($snapshot)->except(['uploaded_photos', 'paths', 'storage_path'])->all();
    }
}
