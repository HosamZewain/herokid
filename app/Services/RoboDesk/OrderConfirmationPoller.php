<?php

namespace App\Services\RoboDesk;

use App\Models\RoboDeskIntegrationEvent;
use App\Models\WhatsAppConversation;
use Carbon\CarbonImmutable;

class OrderConfirmationPoller
{
    public function __construct(private OrderConfirmationService $confirmations) {}

    public function needsFullWindow(string $phone): bool
    {
        return $this->confirmations->ready() && RoboDeskIntegrationEvent::where('direction', 'outbound')
            ->where('event_type', 'order.pending_confirmation')->where('payload->customer->phone', ltrim($phone, '+'))
            ->where('payload->confirmation->eligible_at_creation', true)->whereIn('status', ['succeeded', 'processing', 'unknown'])
            ->whereHas('order', fn ($query) => $query->where('status', 'new'))
            ->whereDoesntHave('confirmationWorkflow', fn ($query) => $query->where('confirmation_status', 'confirmed'))->exists();
    }

    private function review($requests, string $reason): void
    {
        foreach ($requests as $request) {
            if ($request->confirmationWorkflow?->confirmation_status !== 'confirmed') {
                $request->update(['last_error' => 'confirmation_review:'.$reason]);
            }
        }
    }

    public function process(WhatsAppConversation $conversation): void
    {
        if (! $this->confirmations->ready() || $conversation->account_key !== config('robodesk.conversations.account_key', 'primary')) {
            return;
        }
        $phone = ltrim($conversation->phone, '+');
        $requests = RoboDeskIntegrationEvent::where('direction', 'outbound')->where('event_type', 'order.pending_confirmation')
            ->where('status', 'succeeded')->where('payload->customer->phone', $phone)
            ->where('payload->confirmation->eligible_at_creation', true)->with(['confirmationWorkflow', 'order'])->get()
            ->filter(fn ($request) => filled(data_get($request->payload, 'confirmation.send_started_at')));
        $pending = $requests->filter(fn ($request) => $request->confirmationWorkflow?->confirmation_status !== 'confirmed'
            && $request->order?->status === 'new');
        if ($pending->isEmpty()) {
            return;
        }
        $earliest = $pending->map(fn ($request) => CarbonImmutable::parse(data_get($request->payload, 'confirmation.send_started_at'))->utc()->startOfSecond())->min();
        // Saved encrypted evidence is rechecked even on an empty delta page. This
        // covers history/receipt races and never moves the provider cursor backwards.
        $messages = $conversation->messages()->where('sent_at', '>=', $earliest->format('Y-m-d H:i:s.v'))
            ->orderBy('sent_at')->orderBy('id')->get();
        $templates = $messages->filter(fn ($message) => $message->direction === 'outbound'
            && data_get($message->provider_metadata, 'contract_fields_present') === true
            && data_get($message->provider_metadata, 'templateName') === 'herokid_order_confirmation'
            && filled(data_get($message->provider_metadata, 'channelId')));
        $ambiguousRequests = $requests->filter(fn ($request) => $templates->contains(fn ($template) => data_get($template->provider_metadata, 'channelId') === data_get($request->response_payload, 'message_id')
            && data_get($template->provider_metadata, 'interactionId') !== data_get($request->response_payload, 'interaction_id')));
        $this->review($ambiguousRequests, 'template_interaction_mismatch');
        foreach ($messages as $message) {
            $meta = $message->provider_metadata ?? [];
            if ($message->confirmation_event_id !== null || $message->sent_at->isFuture() || $message->direction !== 'inbound' || trim((string) $message->body) !== 'تأكيد الطلب'
                || ($meta['contract_fields_present'] ?? false) !== true) {
                continue;
            }
            $wamid = $meta['replyTo'];
            $messageEnd = $message->sent_at->startOfSecond()->addSecond();
            $knownReply = $wamid !== null && $requests->contains(fn ($request) => data_get($request->response_payload, 'message_id') === $wamid);
            // A quote of another message is ordinary typed confirmation, not a
            // template reference. Outbound AI/flow replies were excluded above.
            if (! $knownReply) {
                if (blank($meta['interactionId'] ?? null) || $ambiguousRequests->contains(fn ($request) => data_get($request->response_payload, 'interaction_id') === $meta['interactionId'])) {
                    continue;
                }
                // Use the provider's actual template timestamp, not receipt arrival
                // time or the mutable conversation variable containing an order number.
                // Meta inbound times truncate to seconds. A template anywhere in
                // that second may precede OR follow the typed text; never guess.
                $sameSecond = $templates->filter(fn ($template) => data_get($template->provider_metadata, 'interactionId') === $meta['interactionId']
                    && $template->sent_at->gte($message->sent_at->startOfSecond()) && $template->sent_at->lt($messageEnd));
                if ($sameSecond->isNotEmpty()) {
                    $this->review($pending->filter(fn ($request) => data_get($request->response_payload, 'interaction_id') === $meta['interactionId']), 'typed_same_second');

                    continue;
                }
                $prior = $templates->filter(fn ($template) => data_get($template->provider_metadata, 'interactionId') === $meta['interactionId']
                    && $template->sent_at->lt($message->sent_at))->sortByDesc(fn ($template) => $template->sent_at->format('U.u'))->values();
                if ($prior->isEmpty() || ($prior->count() > 1 && $prior[0]->sent_at->equalTo($prior[1]->sent_at))) {
                    continue; // No evidence or ambiguous latest template: never guess.
                }
                $missingEvidence = $requests->contains(fn ($request) => data_get($request->response_payload, 'interaction_id') === $meta['interactionId']
                    && CarbonImmutable::parse(data_get($request->payload, 'confirmation.send_started_at'))->gte($earliest)
                    && CarbonImmutable::parse(data_get($request->payload, 'confirmation.send_started_at'))->lt($messageEnd)
                    && ! $templates->contains(fn ($template) => data_get($template->provider_metadata, 'channelId') === data_get($request->response_payload, 'message_id')));
                if ($missingEvidence) {
                    continue; // Another known send may be the latest; wait for its actual history evidence.
                }
                $wamid = data_get($prior[0]->provider_metadata, 'channelId');
            }
            $matches = $requests->filter(fn ($request) => data_get($request->response_payload, 'message_id') === $wamid
                && CarbonImmutable::parse(data_get($request->payload, 'confirmation.send_started_at'))->startOfSecond()->lte($message->sent_at)
                && ($knownReply || data_get($request->response_payload, 'interaction_id') === $meta['interactionId']));
            if ($matches->count() === 1) {
                $matched = $matches->first();
                $outbound = $templates->filter(fn ($template) => data_get($template->provider_metadata, 'channelId') === $wamid);
                if ($outbound->contains(fn ($template) => data_get($template->provider_metadata, 'interactionId') !== data_get($matched->response_payload, 'interaction_id'))) {
                    $this->review(collect([$matched]), 'template_interaction_mismatch');

                    continue;
                }
                $this->confirmations->confirmPolledMessage($matches->first(), $message, $phone, $knownReply ? 'reply' : 'typed');
            }
        }
    }
}
