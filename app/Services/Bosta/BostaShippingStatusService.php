<?php

namespace App\Services\Bosta;

use App\Models\Order;
use App\Support\OrderStatusRegistry;
use App\Support\OrderWorkflowStatus;
use Illuminate\Support\Facades\DB;

class BostaShippingStatusService
{
    public function updateCheckout(string $checkoutGroupKey, string $behavior, string $note): void
    {
        $status = $this->statusForBehavior($behavior);
        if (! $status) {
            return;
        }

        DB::transaction(function () use ($checkoutGroupKey, $status, $note): void {
            $orders = Order::query()->where('checkout_group_key', $checkoutGroupKey)->lockForUpdate()->get();
            foreach ($orders as $order) {
                if ($order->shipping_status === $status) {
                    continue;
                }
                $order->forceFill([
                    'shipping_status' => $status,
                    'workflow_status_updated_at' => now(),
                ])->save();
                $order->statusLogs()->create([
                    'status_type' => 'shipping',
                    'status' => $status,
                    'notes' => $note,
                ]);
            }
        });
    }

    private function statusForBehavior(string $behavior): ?string
    {
        $defined = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, $behavior, false)[0] ?? null;
        if ($defined && OrderStatusRegistry::isValid(OrderStatusRegistry::TYPE_SHIPPING, $defined, false)) {
            return $defined;
        }

        return match ($behavior) {
            'ready' => OrderWorkflowStatus::SHIPPING_READY,
            'shipped' => OrderWorkflowStatus::SHIPPING_SHIPPED,
            'delivered' => OrderWorkflowStatus::SHIPPING_DELIVERED,
            'returned' => OrderWorkflowStatus::SHIPPING_RETURNED,
            'cancelled' => OrderWorkflowStatus::SHIPPING_CANCELLED,
            default => null,
        };
    }
}
