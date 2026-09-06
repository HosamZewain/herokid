<?php

namespace App\Services\RoboDesk;

use App\Jobs\SendRoboDeskEventJob;
use App\Models\RoboDeskIntegrationEvent;
use Illuminate\Support\Str;

class RoboDeskOutbox
{
    public function __construct(
        private readonly RoboDeskSettings $settings,
        private readonly RoboDeskCredentialService $credentials,
    ) {}

    public function queue(
        string $eventType,
        string $deduplicationKey,
        ?string $checkoutGroupKey = null,
        ?int $orderId = null,
        array $payload = [],
        int $delayMinutes = 0,
    ): RoboDeskIntegrationEvent {
        $availableAt = $delayMinutes > 0 ? now()->addMinutes($delayMinutes) : now();

        $event = RoboDeskIntegrationEvent::query()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            [
                'event_id' => (string) Str::uuid(),
                'direction' => 'outbound',
                'event_type' => $eventType,
                'aggregate_type' => $checkoutGroupKey ? 'checkout' : ($orderId ? 'order' : null),
                'aggregate_id' => $checkoutGroupKey ?: ($orderId ? (string) $orderId : null),
                'checkout_group_key' => $checkoutGroupKey,
                'order_id' => $orderId,
                'status' => $this->deliverable() ? 'pending' : 'held',
                'payload' => $payload,
                'available_at' => $availableAt,
            ],
        );

        if ($event->wasRecentlyCreated && $event->status === 'pending') {
            $job = SendRoboDeskEventJob::dispatch($event->id)->afterCommit();

            if ($delayMinutes > 0) {
                $job->delay($availableAt);
            }
        }

        return $event;
    }

    public function release(RoboDeskIntegrationEvent $event): void
    {
        abort_unless($event->direction === 'outbound', 422);
        $event->forceFill(['status' => 'pending', 'last_error' => null, 'available_at' => now()])->save();
        SendRoboDeskEventJob::dispatch($event->id)->afterCommit();
    }

    /**
     * An event is worth dispatching when the integration is on. Signing is
     * optional now that the contract is token-only, so only a run with signing
     * explicitly enabled still needs its secret. Anything not deliverable is
     * parked as `held` rather than dropped, so an admin can release it later.
     */
    private function deliverable(): bool
    {
        if (! $this->settings->enabled()) {
            return false;
        }

        return ! $this->settings->signsOutbound() || $this->credentials->has('outbound_secret');
    }
}
