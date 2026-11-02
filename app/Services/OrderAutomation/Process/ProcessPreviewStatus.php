<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationHandoff;
use Illuminate\Support\Facades\DB;

/** Reflect an accepted preview send without repeating any provider effect. */
class ProcessPreviewStatus
{
    public function synchronize(int $runId): void
    {
        DB::transaction(function () use ($runId) {
            $run = OrderProcessRun::lockForUpdate()->findOrFail($runId);
            if (! in_array($run->status, ['running', 'waiting_response'], true)
                || ! $run->automation->enabled || AutomationHandoff::held($run->checkout_key)) {
                return;
            }
            $runtime = app(ProcessRuntime::class);
            $node = $runtime->node($run);
            if ($node['type'] !== 'wait_response') {
                return;
            }
            $sent = $run->steps()->where('node_id', $node['config']['message_node'])->first();
            $messageNode = collect($run->revision->definition['nodes'])->firstWhere('id', $sent?->node_id);
            $template = data_get($messageNode, 'config.template');
            if (! in_array($template, [ProcessTemplates::REVIEW, ProcessTemplates::IDENTITY], true)
                || $sent?->status !== 'completed' || ! filled(data_get($sent->receipt, 'message_id'))
                || ! filled(data_get($sent->receipt, 'interaction_id'))) {
                return;
            }
            if ($template === ProcessTemplates::REVIEW) {
                $manifest = OrderReviewManifest::where('run_id', $run->id)->first();
                if (! $manifest || $manifest->approved_at) {
                    return;
                }
                $ids = array_unique(array_column($manifest->images, 'order_id'));
            } else {
                $ids = $run->identities()->where('status', 'succeeded')->pluck('order_id')->all();
            }
            // Preserve manual changes and immutable input guards. Status and its new
            // context fingerprint commit atomically; old manifests are never rewritten.
            $runtime->guard($run);
            $contexts = app(ProcessOrderContext::class);
            $orders = $contexts->orders($run->checkout_key, true)->whereIn('id', $ids);
            if ($orders->isEmpty() || $orders->contains(fn ($order) => ! in_array($order->status, ['new_confirmed', 'ready_preview', 'preview_uploaded'], true))) {
                return;
            }
            $changed = false;
            foreach ($orders as $order) {
                if ($order->status === 'preview_uploaded') {
                    continue;
                }
                $from = $order->status;
                $order->update(['status' => 'preview_uploaded']);
                $order->statusLogs()->create(['status_type' => 'order', 'status' => 'preview_uploaded',
                    'notes' => 'Automation: انتظار موافقة العميل بعد قبول إرسال المعاينة؛ مسار #'.$run->id]);
                AutomationActivity::record($order, 'preview_waiting_approval', 'تغيير الحالة إلى انتظار الموافقة بعد قبول إرسال المعاينة.',
                    ['run_id' => $run->id, 'step_id' => $sent->id, 'from' => $from, 'to' => 'preview_uploaded']);
                $changed = true;
            }
            if ($changed) {
                $run->update(['context' => $contexts->snapshot($contexts->orders($run->checkout_key, true), (int) ($run->context['fingerprint_version'] ?? 1)) + ['actor_id' => $run->context['actor_id']]]);
            }
        });
    }
}
