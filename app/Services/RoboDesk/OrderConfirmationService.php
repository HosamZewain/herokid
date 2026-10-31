<?php

namespace App\Services\RoboDesk;

use App\Models\CheckoutCustomerWorkflow;
use App\Models\Order;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\RoboDesk\Conversations\RoboDeskConversationHistoryProvider;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderConfirmationService
{
    public function ready(): bool
    {
        $boundary = config('robodesk.confirmation.after_order_id');

        return config('robodesk.enabled') && config('robodesk.confirmation.enabled')
            && ! in_array(config('queue.default'), ['sync', 'null', null], true)
            && filled(config('robodesk.inbound_secret'))
            && config('robodesk.confirmation.contract_ready')
            && app(RoboDeskConversationHistoryProvider::class)->configured()
            && filled(config('robodesk.confirmation.language'))
            && $boundary !== null && ctype_digit((string) $boundary);
    }

    public function register(Order $order): void
    {
        app(RoboDeskOutbox::class)->queue(
            'order.pending_confirmation',
            'order.pending_confirmation:'.$order->checkoutGroupKey(),
            $order->checkoutGroupKey(),
            $order->id,
            ['confirmation' => [
                'protocol' => 1,
                'eligible_at_creation' => $this->ready() && $order->status === 'new'
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
        if (! Phone::isValidMobile($phone)) {
            return false;
        }
        $sentPhone = data_get($event->payload, 'customer.phone');

        return ! $sentPhone || Phone::forWhatsApp($phone) === $sentPhone;

    }

    public function confirm(array $data): void
    {
        $data = Validator::make($data, [
            'order_number' => 'required|string|max:255',
            'template_message_id' => 'required|string|max:255',
            'interaction_id' => 'required|string|max:255',
            'sender_phone' => 'required|string|max:50',
            'message_id' => 'required|string|max:255',
            'contact_id' => 'nullable|string|max:255',
            'conversation_id' => 'nullable|string|max:255',
        ])->validate();

        DB::transaction(function () use ($data): void {
            // Same request row serializes simultaneous clicks even with different callback UUIDs.
            $request = RoboDeskIntegrationEvent::where('direction', 'outbound')
                ->where('event_type', 'order.pending_confirmation')
                ->where('payload->display_reference', $data['order_number'])
                ->where('response_payload->message_id', $data['template_message_id'])
                ->where('response_payload->interaction_id', $data['interaction_id'])
                ->lockForUpdate()->first();
            abort_unless($request && $request->status === 'succeeded'
                && data_get($request->payload, 'confirmation.eligible_at_creation') === true
                && $request->attempts > 0, 409, 'Confirmation message was not found.');
            $data['checkout_reference'] = $request->checkout_group_key;

            $sentPhone = data_get($request->payload, 'customer.phone');
            abort_unless($sentPhone && Phone::forWhatsApp($data['sender_phone']) === $sentPhone,
                403, 'Confirmation sender mismatch.');
            $orders = Order::withTrashed()->where('checkout_group_key', $data['checkout_reference'])
                ->orderBy('id')->lockForUpdate()->get();
            abort_if($orders->isEmpty(), 409, 'Checkout no longer exists.');
            $workflow = CheckoutCustomerWorkflow::where('checkout_group_key', $data['checkout_reference'])->first();
            if ($workflow?->confirmation_status === 'confirmed') {
                // A second click never changes timestamps or regresses a later workflow state.
                return;
            }
            abort_unless($orders->every(fn (Order $order): bool => ! $order->trashed() && $order->status === 'new'),
                409, 'Checkout is no longer awaiting confirmation.');
            $currentPhone = app(RoboDeskCheckoutPayload::class)->build($data['checkout_reference'])['customer']['phone'];
            abort_unless($currentPhone === $sentPhone, 409, 'Checkout contact has changed.');

            CheckoutCustomerWorkflow::updateOrCreate(['checkout_group_key' => $data['checkout_reference']], [
                'confirmation_status' => 'confirmed', 'confirmed_at' => now(), 'rejected_at' => null,
                'robodesk_contact_id' => $data['contact_id'] ?? null,
                'robodesk_conversation_id' => $data['conversation_id'] ?? null,
                'last_customer_activity_at' => now(),
                'metadata' => array_merge($workflow?->metadata ?? [], [
                    'confirmation_request_id' => $request->event_id,
                    'confirmation_message_id' => $data['message_id'],
                ]),
            ]);
            foreach ($orders as $order) {
                $order->forceFill(['status' => 'new_confirmed'])->save();
                $order->statusLogs()->create(['status_type' => 'order', 'status' => 'new_confirmed',
                    'notes' => 'أكد العميل الطلب عبر زر تأكيد الطلب في RoboDesk.']);
            }
        });
    }
}
