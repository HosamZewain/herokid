<?php

namespace App\Services\Agentic;

use App\Models\OrderProcessRun;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\OrderAutomation\Process\ProcessTemplates;
use Illuminate\Validation\ValidationException;

/** SELECT-only: only the current message node of an already owned, enabled run. */
class MessageDispatchContext
{
    public function forCheckout(string $key): ?array
    {
        $policy = app(ProcessLivePolicy::class);
        if (! $policy->enabled() || ! $policy->allowsCheckout($key)) {
            return null;
        }
        $runs = OrderProcessRun::with(['revision', 'automation', 'steps'])->where('checkout_key', $key)
            ->whereIn('status', ['queued', 'running'])->orderBy('id')->get();
        $candidates = [];
        foreach ($runs as $run) {
            if (! $run->automation?->enabled || ! $policy->allowsAutomation($run->automation_id) || ! $policy->authorized($run->automation)) {
                continue;
            }
            try {
                $policy->check($run->revision->definition);
                $authority = clone $run->automation;
                $authority->setRelation('revision', $run->revision);
                $authority->process_activated_by = $run->context['actor_id'];
                $contexts = app(ProcessOrderContext::class);
                $current = $contexts->snapshot($contexts->forRun($run), (int) ($run->context['fingerprint_version'] ?? 1));
                if (! $policy->authorized($authority) || $run->account_key !== config('robodesk.conversations.account_key', 'primary')
                    || ! hash_equals($run->context['fingerprint'], $current['fingerprint'])) {
                    continue;
                }
            } catch (ValidationException|\RuntimeException) {
                continue;
            }
            $node = collect($run->revision?->definition['nodes'] ?? [])->firstWhere('id', $run->node_id);
            $template = $node['config']['template'] ?? null;
            $step = $run->steps->firstWhere('node_id', $run->node_id);
            if (($node['type'] ?? null) !== 'whatsapp' || ($step && ($step->status !== 'pending' || $step->effect_key))
                || ! in_array($template, ['herokid_order_confirmation', ProcessTemplates::IDENTITY, ProcessTemplates::REVIEW], true)) {
                continue;
            }
            $candidates[] = ['run_id' => $run->id, 'version' => $run->version, 'node_id' => $run->node_id,
                'template' => $template, 'action' => $template === 'herokid_order_confirmation' ? 'send_order_confirmation' : 'send_product_preview'];
        }

        return count($candidates) === 1 ? $candidates[0] : null;
    }
}
