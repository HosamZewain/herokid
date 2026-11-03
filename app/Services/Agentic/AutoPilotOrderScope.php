<?php

namespace App\Services\Agentic;

use App\Models\Order;
use App\Support\OrderStatusRegistry;

final class AutoPilotOrderScope
{
    public static function activeOrdersQuery()
    {
        // Production approval uses order.status=delivered. Customer delivery is
        // separate; payment/printing completion must not hide open fulfilment.
        $cancelled = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_ORDER, 'cancelled');
        $delivered = OrderStatusRegistry::keysForBehavior(OrderStatusRegistry::TYPE_SHIPPING, 'delivered');

        return Order::query()->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', $cancelled))
            ->where(fn ($q) => $q->whereNull('shipping_status')->orWhereNotIn('shipping_status', $delivered));
    }

    public static function complete(array $context): bool
    {
        // Derive from recorded facts, not a cached earlier lifecycle projection.
        return ! empty($context['orders']) && collect($context['orders'])->every(self::terminal(...));
    }

    public static function terminal(array $order): bool
    {
        return ($order['deleted'] ?? false) || ($order['order_behavior'] ?? $order['status'] ?? null) === 'cancelled'
            || ($order['shipping_behavior'] ?? $order['shipping_status'] ?? null) === 'delivered';
    }
}
