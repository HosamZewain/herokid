<?php

namespace App\Services\RoboDesk;

use App\Services\RoboDesk\Actions\ConfirmOrderAction;

/**
 * Decides whether a new checkout waits for the customer to confirm on WhatsApp
 * before production may start.
 *
 * The gate is closed by default and only opens when the integration is enabled,
 * the order-confirm action is enabled, AND its `gate_production` param is on.
 * That triple condition matters: turning RoboDesk off must never strand orders
 * in a status nothing is left to advance.
 *
 * `pending_confirmation` is deliberately a status the Agent API does not pick
 * up — AgentCheckoutProductionService only acquires `new` — so an unconfirmed
 * checkout is invisible to production without touching the agent contract.
 */
class OrderConfirmationGate
{
    public const PENDING_STATUS = 'pending_confirmation';

    public const CONFIRMED_STATUS = 'new';

    public function __construct(private readonly RoboDeskActionRegistry $actions) {}

    public function isOpen(): bool
    {
        $action = $this->actions->find(ConfirmOrderAction::KEY);

        if (! $action || ! $action->isLive()) {
            return false;
        }

        return filter_var($action->param('gate_production', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Initial status for a customer-placed checkout. Staff-created orders pass
     * `false` — someone is already on the phone with the customer, so there is
     * no WhatsApp round-trip to wait for.
     */
    public function initialStatus(bool $customerPlaced = true): string
    {
        return $customerPlaced && $this->isOpen()
            ? self::PENDING_STATUS
            : self::CONFIRMED_STATUS;
    }

    public function isPending(?string $status): bool
    {
        return $status === self::PENDING_STATUS;
    }
}
