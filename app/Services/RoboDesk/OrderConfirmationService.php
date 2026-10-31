<?php

namespace App\Services\RoboDesk;

use App\Models\CheckoutCustomerWorkflow;
use App\Models\Order;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\WhatsAppConversationMessage;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\RoboDesk\Conversations\RoboDeskConversationHistoryProvider;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderConfirmationService
{
    public function ready(): bool
    {
        $boundary = config('robodesk.confirmation.after_order_id');

        $audience = config('robodesk.confirmation.audience');
        $hashes = config('robodesk.confirmation.trial_phone_hashes', []);

        return config('robodesk.confirmation.enabled')
            && ($audience === 'all_new' || ($audience === 'trial' && is_array($hashes) && count($hashes) > 0
                && collect($hashes)->every(fn ($hash) => is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash))))
            && ! in_array(config('queue.default'), ['sync', 'null', null], true)
            && config('robodesk.confirmation.contract_ready')
            && app(RoboDeskConversationHistoryProvider::class)->configured()
            && config('robodesk.confirmation.language') === 'ar-EG'
            && $boundary !== null && ctype_digit((string) $boundary);
    }

    private function phoneAllowed(?string $phone): bool
    {
        if (! Phone::isValidMobile($phone)) {
            return false;
        }

        return config('robodesk.confirmation.audience') === 'all_new'
            || in_array(hash('sha256', '+'.Phone::forWhatsApp($phone)), config('robodesk.confirmation.trial_phone_hashes', []), true);
    }

    public function register(Order $order): void
    {
        // Explicit future-order handoff only. Existing receipts and pending sends stay legacy-owned.
        if (app(ProcessLivePolicy::class)->ownsConfirmation($order)) {
            return;
        }
        app(RoboDeskOutbox::class)->queue(
            'order.pending_confirmation',
            'order.pending_confirmation:'.$order->checkoutGroupKey(),
            $order->checkoutGroupKey(),
            $order->id,
            ['confirmation' => [
                'protocol' => 1,
                'eligible_at_creation' => $this->ready() && $this->phoneAllowed(data_get($order->delivery_details, 'phone')) && $order->status === 'new'
                    && $order->id > (int) config('robodesk.confirmation.after_order_id'),
                'template' => config('robodesk.confirmation.template'),
                'language' => config('robodesk.confirmation.language'),
            ]],
        );
    }

    public function canSend(RoboDeskIntegrationEvent $event): bool
    {
        if (! $this->ready() || data_get($event->payload, 'confirmation.eligible_at_creation') !== true
            || $event->order_id <= (int) config('robodesk.confirmation.after_order_id')) {
            return false;
        }
        $orders = Order::withTrashed()->where('checkout_group_key', $event->checkout_group_key)->get();

        if ($orders->isEmpty() || ! $orders->every(fn (Order $order): bool => ! $order->trashed() && $order->status === 'new'
            && $order->id > (int) config('robodesk.confirmation.after_order_id'))) {
            return false;
        }
        $phone = data_get($orders->first()->delivery_details, 'phone');
        if (! Phone::isValidMobile($phone) || ! $this->phoneAllowed($phone)) {
            return false;
        }
        $sentPhone = data_get($event->payload, 'customer.phone');

        return (! $sentPhone || Phone::forWhatsApp($phone) === $sentPhone)
            && $orders->every(fn (Order $order) => Phone::forWhatsApp(data_get($order->delivery_details, 'phone')) === Phone::forWhatsApp($phone));

    }

    public function confirmPolledMessage(RoboDeskIntegrationEvent $request, WhatsAppConversationMessage $message, string $phone, string $method): void
    {
        DB::transaction(function () use ($request, $message, $phone, $method): void {
            $message = WhatsAppConversationMessage::whereKey($message->id)->lockForUpdate()->firstOrFail();
            if ($message->confirmation_event_id !== null || (Schema::hasTable('order_process_responses') && DB::table('order_process_responses')->where('message_id', $message->id)->exists())) {
                return;
            }
            $request = RoboDeskIntegrationEvent::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $this->ready() || $request->status !== 'succeeded'
                || data_get($request->payload, 'confirmation.eligible_at_creation') !== true
                || $request->attempts < 1 || data_get($request->payload, 'customer.phone') !== $phone) {
                return;
            }
            $orders = Order::withTrashed()->where('checkout_group_key', $request->checkout_group_key)
                ->orderBy('id')->lockForUpdate()->get();
            $workflow = CheckoutCustomerWorkflow::where('checkout_group_key', $request->checkout_group_key)->first();
            if ($workflow?->confirmation_status === 'confirmed' || $orders->isEmpty()
                || ! $orders->every(fn (Order $order): bool => ! $order->trashed() && $order->status === 'new'
                    && Phone::forWhatsApp(data_get($order->delivery_details, 'phone')) === $phone)) {
                return;
            }
            CheckoutCustomerWorkflow::updateOrCreate(['checkout_group_key' => $request->checkout_group_key], [
                'confirmation_status' => 'confirmed', 'confirmed_at' => now(), 'rejected_at' => null,
                'robodesk_conversation_id' => data_get($message->provider_metadata, 'interactionId'),
                'last_customer_activity_at' => $message->sent_at,
                'metadata' => array_merge($workflow?->metadata ?? [], [
                    'confirmation_request_id' => $request->event_id,
                    'confirmation_message_record_id' => $message->id,
                    'confirmation_method' => $method,
                ]),
            ]);
            $request->update(['last_error' => null]);
            $message->update(['confirmation_event_id' => $request->id]);
            foreach ($orders as $order) {
                $order->forceFill(['status' => 'new_confirmed'])->save();
                $order->statusLogs()->create(['status_type' => 'order', 'status' => 'new_confirmed',
                    'notes' => 'أكد العميل الطلب عبر رسالة تأكيد الطلب في RoboDesk.']);
            }
        });
    }
}
