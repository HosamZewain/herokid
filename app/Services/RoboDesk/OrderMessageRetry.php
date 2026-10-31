<?php

namespace App\Services\RoboDesk;

use App\Models\Order;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;

class OrderMessageRetry
{
    // These are definite no-send results. Unknown/network outcomes are never retryable.
    public const NO_SEND = ['template_disabled', 'conversation_race_retry_exhausted', 'confirmation_setup_required', 'template_contract_invalid'];

    public static function authorized(?User $actor): bool
    {
        return $actor && $actor->is_active && $actor->hasPermission('orders.view')
            && $actor->hasPermission('orders.update') && $actor->hasPermission('order_automation.execute');
    }

    public static function failed(string $status, ?string $error, ?array $receipt): bool
    {
        return ($status === 'blocked' && in_array($error, self::NO_SEND, true))
            || ($status === 'succeeded' && filled(data_get($receipt, 'message_id')) && data_get($receipt, 'delivery.status') === 'failed');
    }

    public function eligible(RoboDeskIntegrationEvent $event): bool
    {
        return $event->direction === 'outbound' && $event->event_type === 'order.pending_confirmation'
            && self::failed($event->status, $event->last_error, $event->response_payload)
            && ! RoboDeskIntegrationEvent::where('deduplication_key', 'manual-confirmation-retry:'.$event->id)->exists()
            && ! RoboDeskIntegrationEvent::where('checkout_group_key', $event->checkout_group_key)
                ->where('event_type', 'order.pending_confirmation')->where('id', '>', $event->id)->exists()
            && app(OrderConfirmationService::class)->canSend($event)
            && ! $event->confirmationWorkflow?->confirmed_at;
    }

    public function retry(Order $order, int $eventId, User $actor, string $reason): RoboDeskIntegrationEvent
    {
        abort_unless(self::authorized($actor), 403);

        return DB::transaction(function () use ($order, $eventId, $actor, $reason) {
            $event = RoboDeskIntegrationEvent::lockForUpdate()->findOrFail($eventId);
            abort_unless($event->checkout_group_key === $order->checkoutGroupKey(), 404);
            abort_unless($this->eligible($event), 422, 'المحاولة غير مؤهلة: قد تكون جارية أو غير مؤكدة أو تغيّر الطلب. حدّث الصفحة.');
            // Keep only enrollment/contact constraints. Rebuild contents and totals at send time.
            $payload = ['confirmation' => data_get($event->payload, 'confirmation'),
                'customer' => ['phone' => data_get($event->payload, 'customer.phone')],
                'manual_retry' => ['parent_id' => $event->id, 'requested_by' => $actor->id, 'reason' => $reason]];
            unset($payload['confirmation']['send_started_at']);
            $retry = app(RoboDeskOutbox::class)->queue('order.pending_confirmation', 'manual-confirmation-retry:'.$event->id,
                $event->checkout_group_key, $event->order_id, $payload);
            AdminActivityLogger::log('order_message.retry', 'طلب إعادة إرسال رسالة فاشلة', $order, ['source' => 'confirmation', 'source_id' => $event->id, 'retry_id' => $retry->id, 'reason' => $reason], $actor);

            return $retry;
        });
    }
}
