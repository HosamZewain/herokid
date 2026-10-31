<?php

namespace App\Services\RoboDesk;

use App\Jobs\SendOrderConfirmationJob;
use App\Jobs\SendRoboDeskEventJob;
use App\Models\RoboDeskIntegrationEvent;
use Illuminate\Support\Facades\DB;
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
        $allowed = ! config('robodesk.confirmation_only') || $eventType === 'order.pending_confirmation';
        if ($eventType === 'order.pending_confirmation') {
            $allowed = data_get($payload, 'confirmation.eligible_at_creation') === true;
        }
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
                'status' => $allowed && ($eventType === 'order.pending_confirmation'
                    || (config('robodesk.enabled') && filled(config('robodesk.outbound_secret')))) ? 'pending' : 'held',
                'payload' => $payload,
                'available_at' => now(),
            ],
        );

        if ($event->wasRecentlyCreated && $event->status === 'pending') {
            $job = $eventType === 'order.pending_confirmation' ? SendOrderConfirmationJob::class : SendRoboDeskEventJob::class;
            $job::dispatch($event->id)->afterCommit();
        }

        return $event;
    }

    public function release(RoboDeskIntegrationEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $event = RoboDeskIntegrationEvent::lockForUpdate()->findOrFail($event->id);
            abort_unless($event->direction === 'outbound', 422);
            abort_if(config('robodesk.confirmation_only') && $event->event_type !== 'order.pending_confirmation', 422);
            if ($event->event_type === 'order.pending_confirmation') {
                abort_unless($event->attempts === 0 && app(OrderConfirmationService::class)->canSend($event), 422, 'Confirmation is not eligible for release.');
            }
            abort_if($event->status === 'succeeded', 422, 'Event already accepted.');
            $event->forceFill(['status' => 'pending', 'last_error' => null, 'available_at' => now()])->save();
            $job = $event->event_type === 'order.pending_confirmation' ? SendOrderConfirmationJob::class : SendRoboDeskEventJob::class;
            $job::dispatch($event->id)->afterCommit();
        });
    }
}
