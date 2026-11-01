<?php

namespace App\Jobs;

use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\RoboDesk\OrderConfirmationSendException;
use App\Services\RoboDesk\OrderConfirmationService;
use App\Services\RoboDesk\OrderConfirmationTransport;
use App\Services\RoboDesk\OrderMessageRetry;
use App\Services\RoboDesk\RoboDeskCheckoutPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendOrderConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // start/sendMsg has no verified idempotency contract. An uncertain send must never be retried blindly.
    public int $tries = 1;

    public int $timeout = 110;

    public function __construct(public readonly int $eventId) {}

    public function handle(OrderConfirmationService $confirmations, OrderConfirmationTransport $transport, RoboDeskCheckoutPayload $checkouts): void
    {
        $event = RoboDeskIntegrationEvent::find($this->eventId);
        if (! $event || $event->event_type !== 'order.pending_confirmation' || $event->direction !== 'outbound'
            || $event->status !== 'pending' || $event->attempts > 0) {
            return;
        }
        $retryActor = data_get($event->payload, 'manual_retry.requested_by');
        if ($retryActor && ! OrderMessageRetry::authorized(User::find($retryActor))) {
            $event->update(['status' => 'held', 'last_error' => 'Retry permission revoked.']);

            return;
        }
        $parentId = data_get($event->payload, 'manual_retry.parent_id');
        if ($parentId) {
            $parent = RoboDeskIntegrationEvent::find($parentId);
            if (! $parent || $parent->checkout_group_key !== $event->checkout_group_key
                || ! OrderMessageRetry::failed($parent->status, $parent->last_error, $parent->response_payload)) {
                $event->update(['status' => 'held', 'last_error' => 'Previous attempt is no longer a verified failure.']);

                return;
            }
        }
        if (! $confirmations->canSend($event)) {
            $event->update(['status' => 'held', 'last_error' => 'Confirmation is not eligible for sending.']);

            return;
        }
        $data = array_replace_recursive($checkouts->build($event->checkout_group_key), $event->payload ?? []);
        unset($data['children'], $data['instapay_url'], $data['amounts']['paid'], $data['amounts']['remaining']);
        $account = (string) config('robodesk.conversations.account_key', 'primary');
        // Same contact lock as history sync: serialize two orders and receipt persistence.
        $lock = Cache::lock('robodesk-history:'.hash('sha256', $account.hash('sha256', '+'.$data['customer']['phone'])), 130);
        if (! $lock->get()) {
            // No provider call or durable attempt yet. Requeue only this unclaimed work.
            self::dispatch($event->id)->delay(now()->addSeconds(30))->afterCommit();

            return;
        }
        try {
            if (! $confirmations->canSend($event->fresh())) {
                $event->update(['status' => 'held', 'last_error' => 'Confirmation is not eligible for sending.']);

                return;
            }
            $fresh = array_replace_recursive($checkouts->build($event->checkout_group_key), $event->payload ?? []);
            if ($fresh['customer']['phone'] !== $data['customer']['phone']) {
                $event->update(['status' => 'held', 'last_error' => 'Checkout contact changed before sending.']);

                return;
            }
            $data = $fresh;
            unset($data['children'], $data['instapay_url'], $data['amounts']['paid'], $data['amounts']['remaining']);
            $data['confirmation']['send_started_at'] = now()->utc()->toISOString();
            // Durable compare-and-set precedes even closing the provider conversation.
            if (! RoboDeskIntegrationEvent::whereKey($event->id)->where('status', 'pending')->where('attempts', 0)
                ->update(['status' => 'processing', 'attempts' => 1, 'payload' => json_encode($data, JSON_THROW_ON_ERROR)])) {
                return;
            }
            $event->refresh();
            \App\Services\Orders\AutomationActivity::record($event->checkout_group_key, 'confirmation_send_started', 'بدء محاولة إرسال تأكيد الطلب', ['source_id' => $event->id, 'stage' => 'تأكيد الطلب']);
            try {
                $receipt = $transport->send($data);
                $event->update(['status' => 'succeeded', 'response_payload' => $receipt, 'processed_at' => now(), 'last_error' => null]);
            } catch (OrderConfirmationSendException $exception) {
                $event->update(['status' => 'blocked', 'last_error' => $exception->reason]);
            } catch (Throwable) {
                $event->update(['status' => 'unknown', 'last_error' => 'Close/send result uncertain. Review RoboDesk; do not resend automatically.']);
            }
        } finally {
            $lock->release();
        }
    }
}
