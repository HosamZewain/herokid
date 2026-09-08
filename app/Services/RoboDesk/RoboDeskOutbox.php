<?php

namespace App\Services\RoboDesk;

use App\Jobs\SendRoboDeskEventJob;
use App\Models\RoboDeskIntegrationEvent;
use Illuminate\Support\Str;

class RoboDeskOutbox
{
    public function __construct(private readonly RoboDeskSettings $settings) {}

    public function queue(
        string $integrationKey,
        string $deduplicationKey,
        array $payload = [],
        ?string $checkoutGroupKey = null,
        ?int $orderId = null,
    ): RoboDeskIntegrationEvent {
        $event = RoboDeskIntegrationEvent::query()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            [
                'event_id' => (string) Str::uuid(),
                'direction' => 'outbound',
                'event_type' => $integrationKey,
                'aggregate_type' => $checkoutGroupKey ? 'checkout' : ($orderId ? 'order' : null),
                'aggregate_id' => $checkoutGroupKey ?: ($orderId ? (string) $orderId : null),
                'checkout_group_key' => $checkoutGroupKey,
                'order_id' => $orderId,
                // Parked rather than dropped when the integration is off, so an
                // admin can enable it and release the backlog.
                'status' => $this->settings->enabled() ? 'pending' : 'held',
                'payload' => $payload,
                'available_at' => now(),
            ],
        );

        if ($event->wasRecentlyCreated && $event->status === 'pending') {
            SendRoboDeskEventJob::dispatch($event->id)->afterCommit();
        }

        return $event;
    }

    public function release(RoboDeskIntegrationEvent $event): void
    {
        abort_unless($event->direction === 'outbound', 422);

        $event->forceFill(['status' => 'pending', 'last_error' => null, 'available_at' => now()])->save();

        SendRoboDeskEventJob::dispatch($event->id)->afterCommit();
    }
}
