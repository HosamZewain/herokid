<?php

namespace App\Services\RoboDesk;

/**
 * Decides whether a generated child identity waits for the parent to approve it
 * instead of being auto-approved on first success.
 *
 * Part of the agreed order journey rather than an integration: there is no
 * identity integration yet, but the gate and its `identity_pending_confirmation`
 * status are already in place for when one is added.
 */
class ConfirmIdentityGate
{
    public function __construct(private readonly RoboDeskSettings $settings) {}

    public function isOpen(): bool
    {
        return $this->settings->gatesIdentityConfirmation();
    }
}
