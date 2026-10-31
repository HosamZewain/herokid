<?php

namespace App\Jobs;

use App\Models\RoboDeskIntegrationEvent;
use App\Services\RoboDesk\OrderConfirmationSendException;
use App\Services\RoboDesk\OrderConfirmationService;
use App\Services\RoboDesk\OrderConfirmationTransport;
use App\Services\RoboDesk\RoboDeskCheckoutPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendOrderConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // start/sendMsg has no verified idempotency contract. An uncertain send must never be retried blindly.
    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public readonly int $eventId) {}

    public function handle(OrderConfirmationService $confirmations, OrderConfirmationTransport $transport, RoboDeskCheckoutPayload $checkouts): void
    {
        $event = RoboDeskIntegrationEvent::find($this->eventId);
        if (! $event || $event->event_type !== 'order.pending_confirmation' || $event->direction !== 'outbound'
            || $event->status !== 'pending' || $event->attempts > 0) {
            return;
        }
        if (! $confirmations->canSend($event)) {
            $event->update(['status' => 'held', 'last_error' => 'Confirmation is not eligible for sending.']);

            return;
        }
        $data = array_merge($checkouts->build($event->checkout_group_key), $event->payload ?? []);
        unset($data['children'], $data['instapay_url'], $data['amounts']['paid'], $data['amounts']['remaining']);
        // Atomic durable claim before HTTP: worker redelivery cannot send a second message after a crash.
        if (! RoboDeskIntegrationEvent::whereKey($event->id)->where('status', 'pending')->where('attempts', 0)
            ->update(['status' => 'processing', 'attempts' => 1, 'payload' => json_encode($data, JSON_THROW_ON_ERROR)])) {
            return;
        }
        try {
            $receipt = $transport->send($data);
            $event->update(['status' => 'succeeded', 'response_payload' => $receipt, 'processed_at' => now(), 'last_error' => null]);
        } catch (OrderConfirmationSendException $exception) {
            $event->update(['status' => 'blocked', 'last_error' => $exception->reason]);
        } catch (Throwable) {
            // No response body/credentials/customer data in exceptions or failed-job logs.
            $event->update(['status' => 'unknown', 'last_error' => 'Send result uncertain. Reconcile the original template message in RoboDesk; do not resend.']);
        }
    }
}
