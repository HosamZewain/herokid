<?php

namespace App\Services\RoboDesk;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
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

    /**
     * A generated identity is sent for the parent to approve. When the identity
     * already belongs to an order, that order is parked at
     * `identity_pending_confirmation` so production does not run ahead of the
     * decision; a funnel-stage identity has no order to park.
     */
    public function confirmIdentity(
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
    ): ?RoboDeskIntegrationEvent {
        $integration = $this->integrations->identityConfirmation();

        if (! $integration->enabled()) {
            return null;
        }

        $order = $identity->convertedOrder;

        if ($order && $order->status !== 'identity_pending_confirmation') {
            $order->forceFill(['status' => 'identity_pending_confirmation'])->save();
            $order->statusLogs()->create([
                'status_type' => 'order',
                'status' => 'identity_pending_confirmation',
                'notes' => 'أُرسلت هوية الطفل للعميل عبر RoboDesk بانتظار الاعتماد.',
            ]);
        }

        return $this->outbox->queue(
            $integration->key,
            $integration->key.':'.$identity->uuid.':'.$attempt->id,
            $integration->buildPayload($this->variables->forIdentity($identity, $attempt)),
            $order?->checkout_group_key,
            $order?->id,
        );
    }

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
