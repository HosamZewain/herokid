<?php

namespace App\Services\RoboDesk;

use App\Models\CheckoutCustomerWorkflow;
use App\Models\Order;
use App\Models\OrderCustomerReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RoboDeskInboundEventHandler
{
    public function __construct(private readonly RoboDeskOutbox $outbox) {}

    public function handle(string $type, array $data): void
    {
        DB::transaction(function () use ($type, $data): void {
            match ($type) {
                'order.confirmed' => $this->confirmCheckout($data),
                'order.rejected' => $this->rejectCheckout($data),
                'identity.approved', 'identity.changes_requested',
                'preview.approved', 'preview.changes_requested' => $this->recordReview($type, $data),
                default => throw ValidationException::withMessages(['type' => 'Unsupported RoboDesk event type.']),
            };
        });
    }

    private function confirmCheckout(array $data): void
    {
        $key = $this->checkoutKey($data);
        CheckoutCustomerWorkflow::query()->updateOrCreate(['checkout_group_key' => $key], [
            'confirmation_status' => 'confirmed',
            'confirmed_at' => now(),
            'rejected_at' => null,
            'customer_comment' => $data['comment'] ?? null,
            'robodesk_contact_id' => $data['contact_id'] ?? null,
            'robodesk_conversation_id' => $data['conversation_id'] ?? null,
            'last_customer_activity_at' => now(),
        ]);

        $this->updateOrders($key, 'under_review', 'أكد العميل الطلب عبر RoboDesk.');
    }

    private function rejectCheckout(array $data): void
    {
        $key = $this->checkoutKey($data);
        CheckoutCustomerWorkflow::query()->updateOrCreate(['checkout_group_key' => $key], [
            'confirmation_status' => 'rejected',
            'rejected_at' => now(),
            'customer_comment' => $data['comment'] ?? null,
            'robodesk_contact_id' => $data['contact_id'] ?? null,
            'robodesk_conversation_id' => $data['conversation_id'] ?? null,
            'last_customer_activity_at' => now(),
        ]);

        $this->updateOrders($key, 'cancelled', 'رفض العميل الطلب عبر RoboDesk.');
    }

    private function recordReview(string $type, array $data): void
    {
        $order = $this->order($data);
        $reviewType = str_starts_with($type, 'identity.') ? 'identity' : 'preview';
        $decision = str_ends_with($type, '.approved') ? 'approved' : 'changes_requested';
        $version = trim((string) ($data['version_reference'] ?? 'current')) ?: 'current';

        OrderCustomerReview::query()->updateOrCreate([
            'order_id' => $order->id,
            'review_type' => $reviewType,
            'version_reference' => $version,
        ], [
            'decision' => $decision,
            'customer_comment' => $data['comment'] ?? null,
            'source' => 'robodesk',
            'external_message_id' => $data['message_id'] ?? null,
            'external_conversation_id' => $data['conversation_id'] ?? null,
            'decided_at' => now(),
            'metadata' => ['contact_id' => $data['contact_id'] ?? null],
        ]);

        $nextStatus = match ([$reviewType, $decision]) {
            ['identity', 'approved'] => 'generating',
            ['identity', 'changes_requested'] => 'under_review',
            ['preview', 'approved'] => 'preview_uploaded',
            ['preview', 'changes_requested'] => 'under_review',
        };
        $this->updateSingleOrder($order, $nextStatus, $decision === 'approved'
            ? 'وافق العميل عبر RoboDesk.'
            : 'طلب العميل تعديلات عبر RoboDesk: '.trim((string) ($data['comment'] ?? '')));

        if ($reviewType === 'preview' && $decision === 'approved') {
            $this->requestPaymentWhenAllPreviewsAreApproved($order, $version);
        }
    }

    private function checkoutKey(array $data): string
    {
        $key = trim((string) ($data['checkout_reference'] ?? ''));
        if ($key === '' || ! Order::query()->where('checkout_group_key', $key)->exists()) {
            throw ValidationException::withMessages(['checkout_reference' => 'Checkout reference was not found.']);
        }

        return $key;
    }

    private function order(array $data): Order
    {
        $query = Order::query();
        if (filled($data['order_id'] ?? null)) {
            $query->whereKey((int) $data['order_id']);
        } elseif (filled($data['order_number'] ?? null)) {
            $query->where('order_number', (string) $data['order_number']);
        } else {
            throw ValidationException::withMessages(['order' => 'order_id or order_number is required.']);
        }

        return $query->firstOrFail();
    }

    private function updateOrders(string $key, string $status, string $notes): void
    {
        Order::query()->where('checkout_group_key', $key)->lockForUpdate()->get()
            ->each(fn (Order $order) => $this->updateSingleOrder($order, $status, $notes));
    }

    private function updateSingleOrder(Order $order, string $status, string $notes): void
    {
        if ($order->status === $status) {
            return;
        }

        $order->forceFill(['status' => $status])->save();
        $order->statusLogs()->create(['status_type' => 'order', 'status' => $status, 'notes' => $notes]);
    }

    private function requestPaymentWhenAllPreviewsAreApproved(Order $order, string $version): void
    {
        $orders = Order::query()
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->whereNotNull('story_id')
            ->lockForUpdate()
            ->get(['id']);

        $latestReviews = OrderCustomerReview::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('review_type', 'preview')
            ->latest('decided_at')
            ->latest('id')
            ->get()
            ->unique('order_id');

        if ($orders->isEmpty()
            || $latestReviews->count() !== $orders->count()
            || $latestReviews->contains(fn (OrderCustomerReview $review): bool => $review->decision !== 'approved')) {
            return;
        }

        CheckoutCustomerWorkflow::query()->updateOrCreate(
            ['checkout_group_key' => $order->checkoutGroupKey()],
            ['payment_request_status' => 'pending'],
        );

        $this->outbox->queue(
            'payment.requested',
            'payment.requested:'.$order->checkoutGroupKey().':'.$version,
            $order->checkoutGroupKey(),
            $order->id,
            ['triggered_by_order_id' => $order->id, 'preview_version_reference' => $version],
        );
    }
}
