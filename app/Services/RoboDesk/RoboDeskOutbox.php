<?php

namespace App\Services\RoboDesk;

use App\Jobs\SendRoboDeskEventJob;
use App\Models\RoboDeskIntegrationEvent;
use Illuminate\Support\Str;

class RoboDeskOutbox
{
    public function queue(
        string $eventType,
        string $deduplicationKey,
        ?string $checkoutGroupKey = null,
        ?int $orderId = null,
        array $payload = [],
    ): RoboDeskIntegrationEvent {
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
                'status' => config('robodesk.enabled') && filled(config('robodesk.outbound_secret')) ? 'pending' : 'held',
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
