<?php

namespace App\Services\RoboDesk;

use App\Services\RoboDesk\Actions\ConfirmIdentityAction;

/**
 * Decides whether a generated child identity waits for the parent to approve it
 * over WhatsApp instead of being auto-approved on first success.
 *
 * Closed by default and only opens when the integration is enabled, the
 * identity action is enabled, AND its `gate_auto_approval` param is on. With
 * the gate closed the historical auto-approval behaviour is untouched, so
 * disabling RoboDesk can never leave an identity waiting on a reply that will
 * never come.
 */
class ConfirmIdentityGate
{
    public function __construct(private readonly RoboDeskActionRegistry $actions) {}

    public function isOpen(): bool
    {
        $action = $this->actions->find(ConfirmIdentityAction::KEY);

        if (! $action || ! $action->isLive()) {
            return false;
        }

        return filter_var($action->param('gate_auto_approval', '0'), FILTER_VALIDATE_BOOLEAN);
    }
}
