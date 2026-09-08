<?php

namespace App\Services\RoboDesk;

use App\Models\Order;
use App\Models\RoboDeskIntegrationEvent;

/**
 * Turns a domain trigger into a queued RoboDesk call.
 *
 * Triggers stay dumb: they detect a change and call one method here. Whether
 * the integration is on, what its payload looks like and how it is deduplicated
 * are decided here; delivery itself stays in SendRoboDeskEventJob.
 */
class RoboDeskDispatcher
{
    public function __construct(
        private readonly RoboDeskIntegrationRegistry $integrations,
        private readonly RoboDeskOutbox $outbox,
        private readonly RoboDeskVariableBuilder $variables,
    ) {}

    public function confirmOrder(Order $order): ?RoboDeskIntegrationEvent
    {
        $integration = $this->integrations->orderConfirmation();

        if (! $integration->enabled()) {
            return null;
        }

        $key = $order->checkoutGroupKey();

        // Deduplicated per checkout, not per order row: one cart becomes several
        // orders and the customer should be messaged once.
        return $this->outbox->queue(
            $integration->key,
            $integration->key.':'.$key,
            $integration->buildPayload($this->variables->forCheckout($key)),
            $key,
            $order->id,
        );
    }
}
