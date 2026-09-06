<?php

namespace App\Services\RoboDesk;

use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\OrderPaymentProof;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\RoboDesk\Actions\ConfirmIdentityAction;
use App\Services\RoboDesk\Actions\ConfirmItemAction;
use App\Services\RoboDesk\Actions\ConfirmOrderAction;
use App\Services\RoboDesk\Actions\ReceivePaymentAction;
use App\Services\RoboDesk\Actions\RequestCsatAction;

/**
 * Turns a domain trigger into a queued RoboDesk event.
 *
 * Triggers stay dumb: they detect a change and call one method here. This class
 * owns the "is the action enabled", "what variables does it expose" and "how is
 * it deduplicated" decisions. Delivery itself stays in SendRoboDeskEventJob.
 */
class RoboDeskDispatcher
{
    public function __construct(
        private readonly RoboDeskActionRegistry $actions,
        private readonly RoboDeskOutbox $outbox,
        private readonly RoboDeskVariableBuilder $variables,
        private readonly RoboDeskSettings $settings,
    ) {}

    public function confirmOrder(Order $order): ?RoboDeskIntegrationEvent
    {
        $action = $this->actions->get(ConfirmOrderAction::KEY);

        if (! $action->enabled()) {
            return null;
        }

        $key = $order->checkoutGroupKey();

        return $this->queue(
            $action->key(),
            'order.confirm:'.$key,
            $action->buildPayload(array_merge(
                $this->variables->forCheckout($key),
                ['terms' => (string) $action->param('terms_text', '')],
            )),
            $key,
            $order->id,
        );
    }

    public function confirmIdentity(
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
    ): ?RoboDeskIntegrationEvent {
        $action = $this->actions->get(ConfirmIdentityAction::KEY);

        if (! $action->enabled()) {
            return null;
        }

        return $this->queue(
            $action->key(),
            'identity.confirm:'.$identity->uuid.':'.$attempt->id,
            $action->buildPayload($this->variables->forIdentity(
                $identity,
                $attempt,
                (int) $action->param('media_link_ttl_hours', 168),
            )),
            $identity->convertedOrder?->checkoutGroupKey(),
            $identity->converted_order_id,
        );
    }

    public function confirmItem(Order $order, array $item): ?RoboDeskIntegrationEvent
    {
        $action = $this->actions->get(ConfirmItemAction::KEY);

        if (! $action->enabled()) {
            return null;
        }

        $key = $order->checkoutGroupKey();
        $reference = (string) ($item['preview_version'] ?? 'current');

        return $this->queue(
            $action->key(),
            'item.confirm:'.$order->id.':'.$reference,
            $action->buildPayload(array_merge($this->variables->forCheckout($key), $item, [
                'order_number' => $order->order_number,
            ])),
            $key,
            $order->id,
        );
    }

    public function requestPayment(Order $order, string $reference = 'current'): ?RoboDeskIntegrationEvent
    {
        $action = $this->actions->get(ConfirmItemAction::KEY);

        if (! $action->enabled() || $action->param('payment_request_mode') === 'never') {
            return null;
        }

        $key = $order->checkoutGroupKey();

        return $this->queue(
            'payment.requested',
            'payment.requested:'.$key.':'.$reference,
            $action->buildPayload(array_merge($this->variables->forCheckout($key), [
                'order_number' => $order->order_number,
                'template_name' => (string) $action->param('payment_template_name', $action->param('template_name', '')),
            ])),
            $key,
            $order->id,
        );
    }

    public function paymentProofReceived(OrderPaymentProof $proof): ?RoboDeskIntegrationEvent
    {
        $action = $this->actions->get(ReceivePaymentAction::KEY);

        if (! $action->enabled() || ! filter_var($action->param('acknowledge_receipt', '1'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return $this->queue(
            'payment.proof_received',
            'payment.proof_received:'.$proof->id,
            $action->buildPayload(array_merge($this->variables->forCheckout($proof->checkout_group_key), [
                'proof_id' => $proof->uuid,
                'proof_status' => $proof->status,
            ])),
            $proof->checkout_group_key,
        );
    }

    public function paymentProofRejected(OrderPaymentProof $proof, string $reason): ?RoboDeskIntegrationEvent
    {
        $action = $this->actions->get(ReceivePaymentAction::KEY);

        if (! $action->enabled()) {
            return null;
        }

        return $this->queue(
            'payment.proof_rejected',
            'payment.proof_rejected:'.$proof->id,
            $action->buildPayload(array_merge($this->variables->forCheckout($proof->checkout_group_key), [
                'proof_id' => $proof->uuid,
                'proof_status' => $proof->status,
                'rejection_reason' => $reason,
                'template_name' => (string) $action->param('rejected_template_name', $action->param('template_name', '')),
            ])),
            $proof->checkout_group_key,
        );
    }

    public function requestCsat(Order $order): ?RoboDeskIntegrationEvent
    {
        $action = $this->actions->get(RequestCsatAction::KEY);

        if (! $action->enabled()) {
            return null;
        }

        $key = $order->checkoutGroupKey();

        return $this->queue(
            $action->key(),
            'csat.request:'.$key,
            $action->buildPayload(array_merge($this->variables->forCheckout($key), [
                'order_number' => $order->order_number,
                'delivered_at' => now()->toIso8601String(),
            ])),
            $key,
            $order->id,
            max(0, (int) $action->param('delay_minutes', 0)),
        );
    }

    private function queue(
        string $eventType,
        string $deduplicationKey,
        array $payload,
        ?string $checkoutGroupKey = null,
        ?int $orderId = null,
        int $delayMinutes = 0,
    ): RoboDeskIntegrationEvent {
        return $this->outbox->queue(
            $eventType,
            $deduplicationKey,
            $checkoutGroupKey,
            $orderId,
            $payload,
            $delayMinutes,
        );
    }
}
