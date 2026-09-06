<?php

namespace App\Services\RoboDesk;

use App\Models\CheckoutCustomerWorkflow;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\OrderCsatResponse;
use App\Models\OrderCustomerReview;
use App\Services\ChildIdentity\ChildIdentityApprovalService;
use App\Services\ChildIdentity\ChildIdentityAttemptService;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Services\RoboDesk\Actions\ConfirmIdentityAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoboDeskInboundEventHandler
{
    public function __construct(
        private readonly RoboDeskOutbox $outbox,
        private readonly RoboDeskActionRegistry $actions,
        private readonly OrderConfirmationGate $gate,
        private readonly ChildIdentityApprovalService $approvals,
        private readonly ChildIdentityAttemptService $attempts,
        private readonly ChildIdentityEventLogger $identityEvents,
    ) {}

    public function handle(string $type, array $data): void
    {
        DB::transaction(function () use ($type, $data): void {
            match ($type) {
                'order.confirmed' => $this->confirmCheckout($data),
                'order.rejected' => $this->rejectCheckout($data),
                'identity.approved' => $this->approveIdentity($data),
                'identity.changes_requested' => $this->requestIdentityChanges($data),
                'preview.approved', 'preview.changes_requested' => $this->recordReview($type, $data),
                'csat.submitted' => $this->recordCsat($data),
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

        // Confirmation releases the checkout into the production queue. Only
        // orders actually parked at pending_confirmation move; if the gate was
        // never enabled the order is already `new` and is left untouched, so a
        // confirmation can never drag a live order backwards.
        Order::query()
            ->where('checkout_group_key', $key)
            ->where('status', OrderConfirmationGate::PENDING_STATUS)
            ->lockForUpdate()
            ->get()
            ->each(fn (Order $order) => $this->updateSingleOrder(
                $order,
                OrderConfirmationGate::CONFIRMED_STATUS,
                'أكد العميل الطلب عبر RoboDesk.',
            ));
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

    private function approveIdentity(array $data): void
    {
        [$identity, $attempt] = $this->resolveIdentity($data);

        $this->approvals->approve($identity, $attempt, null, 'robodesk');

        if ($order = $this->identityOrder($identity, $data)) {
            $this->recordDecision($order, 'identity', $data, 'approved');

            if ($order->status === 'identity_pending_confirmation') {
                $this->updateSingleOrder($order, 'new', 'اعتمد العميل هوية الطفل عبر RoboDesk.');
            }
        }
    }

    /**
     * The revision loop: the parent's comment is written into the identity's
     * prompt override and a fresh attempt is queued. The attempt is initiated as
     * `robodesk`, not `customer`, which both bypasses the customer attempt cap
     * and keeps the result out of the auto-approval path.
     */
    private function requestIdentityChanges(array $data): void
    {
        [$identity] = $this->resolveIdentity($data, requireAttempt: false);
        $comment = trim((string) ($data['comment'] ?? ''));

        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => 'A comment is required to request identity changes.']);
        }

        $action = $this->actions->get(ConfirmIdentityAction::KEY);
        $maxRevisions = max(0, (int) $action->param('max_revisions', 3));
        $used = (int) $identity->events()->where('event_type', 'identity.revision_requested')->count();

        $this->identityEvents->record(
            $identity,
            'identity.revision_requested',
            'طلب العميل تعديل الهوية عبر RoboDesk: '.$comment,
            ['revision_number' => $used + 1, 'max_revisions' => $maxRevisions],
            actorType: 'customer',
            source: 'robodesk',
        );

        $order = $this->identityOrder($identity, $data);

        if ($order) {
            $this->recordDecision($order, 'identity', $data, 'changes_requested');
        }

        // Out of automatic revisions: leave it for a human rather than burning
        // more provider spend on the same feedback.
        if ($used >= $maxRevisions) {
            if ($order) {
                $this->updateSingleOrder($order, 'revision_requested', 'تجاوز العميل عدد التعديلات التلقائية. مطلوب مراجعة بشرية.');
            }

            return;
        }

        $prefix = trim((string) $action->param('comment_prompt_prefix', ''));
        $identity->forceFill([
            'prompt_override' => trim($this->attempts->promptFor($identity)."\n\n".$prefix."\n".$comment),
        ])->save();

        $this->attempts->create($identity, (string) Str::uuid(), 'robodesk');
    }

    private function recordReview(string $type, array $data): void
    {
        $order = $this->order($data);
        $decision = str_ends_with($type, '.approved') ? 'approved' : 'changes_requested';
        $version = trim((string) ($data['version_reference'] ?? 'current')) ?: 'current';

        $this->recordDecision($order, 'preview', $data, $decision, $version);

        $this->updateSingleOrder(
            $order,
            $decision === 'approved' ? 'preview_uploaded' : 'revision_requested',
            $decision === 'approved'
                ? 'وافق العميل على المعاينة عبر RoboDesk.'
                : 'طلب العميل تعديلات على المعاينة عبر RoboDesk: '.trim((string) ($data['comment'] ?? '')),
        );

        if ($decision === 'approved') {
            $this->requestPaymentWhenAllPreviewsAreApproved($order, $version);
        }
    }

    private function recordCsat(array $data): void
    {
        $key = $this->checkoutKey($data);
        $order = Order::query()->where('checkout_group_key', $key)->orderBy('id')->first();

        OrderCsatResponse::query()->updateOrCreate(
            ['external_message_id' => $data['message_id'] ?? ($key.':csat')],
            [
                'checkout_group_key' => $key,
                'order_id' => $order?->id,
                'score' => isset($data['score']) ? (int) $data['score'] : null,
                'comment' => $data['comment'] ?? null,
                'source' => 'robodesk',
                'external_conversation_id' => $data['conversation_id'] ?? null,
                'responded_at' => now(),
                'metadata' => ['contact_id' => $data['contact_id'] ?? null],
            ],
        );
    }

    private function recordDecision(
        Order $order,
        string $reviewType,
        array $data,
        string $decision,
        string $version = 'current',
    ): void {
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
    }

    /**
     * Identity events address either a funnel-stage identity (by uuid) or one
     * already attached to an order. The uuid path is what lets a pre-checkout
     * identity be reviewed at all — it has no order number yet.
     *
     * @return array{0: ChildIdentityRequest, 1: ?ChildIdentityGenerationAttempt}
     */
    private function resolveIdentity(array $data, bool $requireAttempt = true): array
    {
        $identity = null;

        if (filled($data['identity_uuid'] ?? null)) {
            $identity = ChildIdentityRequest::query()->where('uuid', $data['identity_uuid'])->first();
        }

        if (! $identity && (filled($data['order_id'] ?? null) || filled($data['order_number'] ?? null))) {
            $identity = $this->order($data)->childIdentityRequest;
        }

        if (! $identity) {
            throw ValidationException::withMessages([
                'identity' => 'identity_uuid, order_id or order_number is required to resolve the identity.',
            ]);
        }

        $attempt = null;

        if (filled($data['attempt_id'] ?? null)) {
            $attempt = ChildIdentityGenerationAttempt::query()
                ->where('child_identity_request_id', $identity->id)
                ->find($data['attempt_id']);
        }

        $attempt ??= $identity->attempts()
            ->where('status', 'succeeded')
            ->whereNotNull('output_storage_path')
            ->latest('id')
            ->first();

        if ($requireAttempt && ! $attempt) {
            throw ValidationException::withMessages(['attempt' => 'No generated identity attempt is available to approve.']);
        }

        return [$identity, $attempt];
    }

    private function identityOrder(ChildIdentityRequest $identity, array $data): ?Order
    {
        if (filled($data['order_id'] ?? null) || filled($data['order_number'] ?? null)) {
            return $this->order($data);
        }

        return $identity->convertedOrder;
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
