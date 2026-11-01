<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderPaymentReview;
use App\Models\OrderPaymentReviewSignal;
use App\Models\OrderProcessRun;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\RoboDesk\Conversations\ConversationPhone;
use App\Support\AdminActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** An attachment is a request for human review, never evidence of settled money. */
class ProcessPaymentReview
{
    public function runs(): Builder
    {
        $query = OrderProcessRun::query()->where('account_key', config('robodesk.conversations.account_key', 'primary'))
            ->whereIn('status', ['running', 'waiting_response', 'waiting_staff'])
            ->where('order_id', '>', (int) config('order_process.after_order_id'))
            ->whereHas('order', fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', \App\Support\OrderStatusRegistry::keysForBehavior(\App\Support\OrderStatusRegistry::TYPE_ORDER, 'cancelled')))
            ->whereHas('automation', fn ($q) => $q->where('enabled', true))
            ->whereNotIn('id', OrderPaymentReview::select('run_id')->where('status', 'closed'));
        $policy = app(ProcessLivePolicy::class);
        if (! config('order_process.payment_review_enabled') || ! $policy->enabled()) {
            return $query->whereRaw('1 = 0');
        }
        if (config('order_process.enrollment_mode') === 'trial') {
            $query->where('order_id', (int) config('order_process.trial_order_id'))
                ->where('automation_id', (int) config('order_process.trial_automation_id'));
        }

        return $query;
    }

    public function needsFullWindow(string $phone): bool
    {
        return $this->runs()->where('phone_hash', hash('sha256', '+'.ltrim($phone, '+')))->exists();
    }

    public function process(WhatsAppConversation $conversation): void
    {
        if ($conversation->account_key !== config('robodesk.conversations.account_key', 'primary')) {
            return;
        }
        $runs = $this->runs()->where('phone_hash', $conversation->phone_hash)->with(['steps.messageAttempts', 'revision', 'automation'])->get();
        if ($runs->isEmpty()) {
            return;
        }
        // Include inactive/closed competing runs in ambiguity checks: a shared interaction
        // must not silently assign an attachment to another checkout after review closes.
        $allRuns = OrderProcessRun::where('account_key', $conversation->account_key)->where('phone_hash', $conversation->phone_hash)
            ->with(['steps.messageAttempts', 'revision'])->get();
        $sent = collect();
        foreach ($allRuns as $run) {
            foreach ($run->steps->where('type', 'whatsapp') as $step) {
                $node = collect($run->revision->definition['nodes'] ?? [])->firstWhere('id', $step->node_id);
                if (data_get($node, 'config.template') !== ProcessTemplates::IDENTITY) {
                    continue;
                }
                $attempts = $step->messageAttempts->isEmpty() ? collect([$step]) : $step->messageAttempts;
                foreach ($attempts->where('status', 'completed') as $attempt) {
                    $wamid = data_get($attempt->receipt, 'message_id');
                    $interaction = data_get($attempt->receipt, 'interaction_id');
                    if ($wamid && $interaction) {
                        $sent->push(['run_id' => $run->id, 'checkout_key' => $run->checkout_key, 'wamid' => $wamid, 'interaction' => $interaction]);
                    }
                }
            }
        }
        $messages = $conversation->messages()->where('sent_at', '>=', $runs->min('created_at'))->orderBy('sent_at')->get();
        foreach ($messages as $message) {
            $interaction = data_get($message->provider_metadata, 'interactionId');
            if ($message->direction !== 'inbound' || empty($message->attachments) || ! $interaction || $message->sent_at->isFuture()) {
                continue;
            }
            $candidates = $sent->where('interaction', $interaction);
            if ($candidates->pluck('checkout_key')->unique()->count() !== 1 || $candidates->pluck('run_id')->unique()->count() !== 1) {
                continue;
            }
            $run = $runs->firstWhere('id', $candidates->first()['run_id'] ?? null);
            if (! $run) {
                continue;
            }
            $evidence = $messages->filter(fn ($m) => $m->direction === 'outbound'
                && data_get($m->provider_metadata, 'templateName') === ProcessTemplates::IDENTITY
                && data_get($m->provider_metadata, 'interactionId') === $interaction
                && $candidates->contains('wamid', data_get($m->provider_metadata, 'channelId'))
                // Incoming Meta time has second precision: same-second ordering is ambiguous.
                && $m->sent_at->lt($message->sent_at->startOfSecond()));
            if ($evidence->isEmpty()) {
                continue;
            }
            DB::transaction(function () use ($run, $message) {
                $current = $this->runs()->whereKey($run->id)->lockForUpdate()->first();
                if (! $current || ! $this->eligible($current)) {
                    return;
                }
                // Message-level locking and uniqueness prevent concurrent cross-order reuse.
                $message->newQuery()->whereKey($message->id)->lockForUpdate()->firstOrFail();
                if (OrderPaymentReviewSignal::where('message_id', $message->id)->exists()) {
                    return;
                }
                $review = OrderPaymentReview::firstOrCreate(['run_id' => $current->id], ['checkout_key' => $current->checkout_key, 'status' => 'pending']);
                if ($review->status !== 'pending') {
                    return;
                }
                $review->signals()->create(['message_id' => $message->id]);
            });
        }
    }

    private function eligible(OrderProcessRun $run): bool
    {
        $policy = app(ProcessLivePolicy::class);
        $order = $run->order;
        $phone = ConversationPhone::canonical(data_get($order?->delivery_details, 'phone'));

        return $order && ! $order->trashed() && $order->checkoutGroupKey() === $run->checkout_key
            && $phone && hash('sha256', $phone) === $run->phone_hash
            && $policy->allowsCheckout($run->checkout_key) && $policy->allowsAutomation($run->automation_id)
            && $policy->authorized($run->automation);
    }

    public function close(Order $order, int $id, User $actor, string $note, int $seenSignalId): void
    {
        abort_unless($actor->is_active && $actor->role === 'admin' && $actor->hasPermission('orders.view')
            && $actor->hasPermission('robodesk.review_payments'), 403);
        DB::transaction(function () use ($order, $id, $actor, $note, $seenSignalId) {
            $review = OrderPaymentReview::where('checkout_key', $order->checkoutGroupKey())->findOrFail($id);
            // Same lock order as ingestion: run, then review.
            OrderProcessRun::whereKey($review->run_id)->lockForUpdate()->firstOrFail();
            $review = OrderPaymentReview::lockForUpdate()->findOrFail($id);
            abort_unless($review->status === 'pending', 409, 'تم إنهاء المراجعة بالفعل.');
            abort_unless((int) $review->signals()->max('id') === $seenSignalId, 409, 'وصل مرفق جديد. حدّث الصفحة وراجعه أولاً.');
            $review->update(['status' => 'closed', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            AdminActivityLogger::log('order.payment_review_closed', 'إنهاء مراجعة مرفقات العميل دون تسجيل دفعة تلقائية', $review,
                ['order_id' => $order->id], $actor);
        });
    }
}
