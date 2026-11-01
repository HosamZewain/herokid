<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use App\Services\Orders\AutomationActivity;
use App\Services\RoboDesk\TemplateDelivery;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessResponsePoller
{
    public function needsFullWindow(string $phone): bool
    {
        return app(ProcessLivePolicy::class)->enabled() && OrderProcessRun::where('account_key', config('robodesk.conversations.account_key', 'primary'))
            ->where('phone_hash', hash('sha256', '+'.ltrim($phone, '+')))->whereIn('status', ['sending', 'running', 'waiting_response', 'uncertain', 'retry_queued'])->exists();
    }

    public function process(WhatsAppConversation $conversation): void
    {
        if (! app(ProcessLivePolicy::class)->enabled() || $conversation->account_key !== config('robodesk.conversations.account_key', 'primary')) {
            return;
        }
        $runs = OrderProcessRun::where('account_key', $conversation->account_key)->where('phone_hash', $conversation->phone_hash)->get();
        $pending = $runs->where('status', 'waiting_response')->filter(fn ($r) => $r->automation->enabled);
        if ($pending->isEmpty()) {
            return;
        }
        $steps = OrderProcessStep::whereIn('run_id', $runs->pluck('id'))->where('type', 'whatsapp')->with('messageAttempts')->get()->flatMap(function ($step) {
            if ($step->messageAttempts->isEmpty()) {
                return [$step];
            }

            return $step->messageAttempts->map(function ($attempt) use ($step) {
                $copy = clone $step;
                $copy->receipt = $attempt->receipt;
                $copy->status = $attempt->status;
                $copy->started_at = $attempt->started_at;
                $copy->setRelation('messageAttempt', $attempt);

                return $copy;
            });
        });
        $messages = $conversation->messages()->where('sent_at', '>=', $pending->min('created_at'))->orderBy('sent_at')->orderBy('id')->get();
        $templates = $messages->filter(fn ($m) => $m->direction === 'outbound' && data_get($m->provider_metadata, 'contract_fields_present') === true && filled(data_get($m->provider_metadata, 'templateName')) && filled(data_get($m->provider_metadata, 'channelId')));
        foreach ($steps->where('status', 'completed') as $sent) {
            $run = $pending->firstWhere('id', $sent->run_id);
            if (! $run) {
                continue;
            }
            $evidence = $templates->filter(fn ($m) => data_get($m->provider_metadata, 'channelId') === data_get($sent->receipt, 'message_id')
                && data_get($m->provider_metadata, 'interactionId') === data_get($sent->receipt, 'interaction_id')
                && data_get($m->provider_metadata, 'templateName') === data_get(collect($run->revision->definition['nodes'])->firstWhere('id', $sent->node_id), 'config.template'));
            if ($evidence->count() === 1) {
                $receipt = $sent->receipt;
                $receipt['delivery'] = TemplateDelivery::normalize(data_get($evidence->first()->provider_metadata, 'delivery'));
                if ($sent->relationLoaded('messageAttempt')) {
                    $sent->messageAttempt->update(['receipt' => $receipt]);
                    DB::transaction(function () use ($sent, $receipt) {
                        $current = OrderProcessStep::lockForUpdate()->findOrFail($sent->id);
                        if (data_get($current->receipt, 'message_id') === data_get($receipt, 'message_id')) {
                            $current->update(['receipt' => $receipt]);
                        }
                    });
                    $sent->receipt = $receipt;
                } else {
                    $sent->update(['receipt' => $receipt]);
                }
            }
        }
        foreach ($messages as $message) {
            $meta = $message->provider_metadata ?? [];
            if ($message->direction !== 'inbound' || $message->confirmation_event_id !== null || $message->sent_at->isFuture() || ($meta['contract_fields_present'] ?? false) !== true) {
                continue;
            }
            $reply = $meta['replyTo'] ?? null;
            $matching = $steps->filter(fn ($s) => $reply && data_get($s->receipt, 'message_id') === $reply);
            $exact = $matching->isNotEmpty();
            if (! $exact) {
                // A known legacy template must never be reassigned to a new process.
                if ($reply && RoboDeskIntegrationEvent::where('event_type', 'order.pending_confirmation')->where('response_payload->message_id', $reply)->exists()) {
                    continue;
                }
                if (empty($meta['interactionId']) || $steps->contains(fn ($s) => in_array($s->status, ['processing', 'uncertain'], true))) {
                    continue;
                }
                $same = $templates->filter(fn ($m) => data_get($m->provider_metadata, 'interactionId') === $meta['interactionId']);
                if ($same->contains(fn ($m) => $m->sent_at->gte($message->sent_at->startOfSecond()) && $m->sent_at->lt($message->sent_at->startOfSecond()->addSecond()))) {
                    continue;
                }
                $prior = $same->filter(fn ($m) => $m->sent_at->lt($message->sent_at))->sortByDesc(fn ($m) => $m->sent_at->format('U.u'))->values();
                if ($prior->isEmpty() || ($prior->count() > 1 && $prior[0]->sent_at->equalTo($prior[1]->sent_at))) {
                    continue;
                }
                // Missing evidence of another accepted send can hide the latest template.
                if ($steps->contains(fn ($s) => $s->status === 'completed' && data_get($s->receipt, 'interaction_id') === $meta['interactionId'] && $s->started_at->lt($message->sent_at->startOfSecond()->addSecond())
                    && ! $templates->contains(fn ($t) => data_get($t->provider_metadata, 'channelId') === data_get($s->receipt, 'message_id')))) {
                    continue;
                }
                $matching = $steps->filter(fn ($s) => data_get($s->receipt, 'message_id') === data_get($prior[0]->provider_metadata, 'channelId'));
            }
            if ($matching->count() !== 1) {
                continue;
            }
            $sent = $matching->first();
            $run = $pending->firstWhere('id', $sent->run_id);
            if (! $run || $sent->status !== 'completed' || ! $sent->started_at || $message->sent_at->lt($sent->started_at->startOfSecond())) {
                continue;
            }
            $node = app(ProcessRuntime::class)->node($run);
            if ($node['type'] !== 'wait_response' || $node['config']['message_node'] !== $sent->node_id) {
                continue;
            }
            $evidence = $templates->filter(fn ($m) => data_get($m->provider_metadata, 'channelId') === data_get($sent->receipt, 'message_id'));
            if ($evidence->count() !== 1 || data_get($evidence->first()->provider_metadata, 'interactionId') !== data_get($sent->receipt, 'interaction_id')
                || data_get($evidence->first()->provider_metadata, 'templateName') !== data_get(collect($run->revision->definition['nodes'])->firstWhere('id', $sent->node_id), 'config.template')
                || $evidence->first()->sent_at->gte($message->sent_at->startOfSecond()->addSecond())) {
                continue;
            }
            if (! $exact && data_get($sent->receipt, 'interaction_id') !== ($meta['interactionId'] ?? null)) {
                continue;
            }
            $case = collect($node['config']['cases'])->first(fn ($c) => $c['text'] === trim((string) $message->body));
            if ($case) {
                $this->consume($run, $message, $case);
            }
        }
    }

    private function consume(OrderProcessRun $run, WhatsAppConversationMessage $message, array $case): void
    {
        DB::transaction(function () use ($run, $message, $case) {
            $message = WhatsAppConversationMessage::lockForUpdate()->findOrFail($message->id);
            if ($message->confirmation_event_id || DB::table('order_process_responses')->where('message_id', $message->id)->exists()) {
                return;
            }
            $expectedNode = $run->node_id;
            $expectedVersion = $run->version;
            $run = OrderProcessRun::lockForUpdate()->findOrFail($run->id);
            if (! app(ProcessLivePolicy::class)->enabled() || $run->status !== 'waiting_response' || ! $run->automation->enabled || $run->node_id !== $expectedNode || $run->version !== $expectedVersion) {
                return;
            }
            $currentCase = collect(app(ProcessRuntime::class)->node($run)['config']['cases'])->first(fn ($c) => $c['text'] === trim((string) $message->body));
            if ($currentCase !== $case) {
                return;
            }
            try {
                app(ProcessRuntime::class)->guard($run);
            } catch (RuntimeException) {
                $run->update(['status' => 'blocked', 'error_code' => 'response_context_changed']);

                return;
            }
            $step = $run->steps()->where('node_id', $run->node_id)->firstOrFail();
            if ($step->status !== 'waiting') {
                return;
            }
            DB::table('order_process_responses')->insert(['run_id' => $run->id, 'step_id' => $step->id, 'message_id' => $message->id, 'decision' => $case['key'], 'created_at' => now(), 'updated_at' => now()]);
            AutomationActivity::record($run->checkout_key, 'customer_response', 'تسجيل رد العميل على رسالة الأوتوميشن', ['stage' => 'تسجيل رد العميل', 'decision' => $case['text'], 'source_id' => $message->id]);
            $step->update(['status' => 'completed', 'completed_at' => now()]);
            app(ProcessRuntime::class)->move($run, $case['next']);
        });
    }
}
