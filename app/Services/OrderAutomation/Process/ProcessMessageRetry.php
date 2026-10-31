<?php

namespace App\Services\OrderAutomation\Process;

use App\Jobs\RetryOrderProcessMessage;
use App\Models\Order;
use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
use App\Models\User;
use App\Services\RoboDesk\OrderMessageRetry;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessMessageRetry
{
    public function eligible(OrderProcessStep $step): bool
    {
        $run = $step->run;
        if ($step->type !== 'whatsapp' || ! app(ProcessLivePolicy::class)->enabled() || ! $run->automation->enabled
            || ! in_array($run->status, ['blocked', 'waiting_response'], true)
            || ! OrderMessageRetry::failed($step->status === 'completed' ? 'succeeded' : $step->status, $run->error_code, $step->receipt)) {
            return false;
        }
        $node = app(ProcessRuntime::class)->node($run);
        $waiting = $node['type'] === 'wait_response' && $node['config']['message_node'] === $step->node_id;
        if ((! $waiting && $run->node_id !== $step->node_id) || ($waiting && (! $run->wait_until || $run->wait_until->isPast()))) {
            return false;
        }
        try {
            app(ProcessLivePolicy::class)->check($run->revision->definition);
            app(ProcessRuntime::class)->guard($run);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    public function retry(Order $order, int $stepId, User $actor, int $version, string $reason): void
    {
        abort_unless(OrderMessageRetry::authorized($actor), 403);
        DB::transaction(function () use ($order, $stepId, $actor, $version, $reason) {
            $step = OrderProcessStep::findOrFail($stepId);
            $run = OrderProcessRun::lockForUpdate()->findOrFail($step->run_id);
            $step = OrderProcessStep::lockForUpdate()->findOrFail($stepId);
            $step->setRelation('run', $run);
            abort_unless($run->checkout_key === $order->checkoutGroupKey(), 404);
            abort_unless($run->version === $version, 409);
            abort_unless($this->eligible($step), 422, 'المحاولة غير مؤهلة لإعادة الإرسال أو تغيّر سياق الطلب.');
            self::retainHistorical($step);
            $attempt = $step->messageAttempts()->create(['number' => $step->messageAttempts()->max('number') + 1,
                'status' => 'pending', 'requested_by' => $actor->id, 'reason' => $reason]);
            $step->update(['status' => 'retry_pending']);
            $run->update(['status' => 'retry_queued', 'version' => $run->version + 1]);
            RetryOrderProcessMessage::dispatch($run->id, $step->id, $attempt->id, $run->version)->afterCommit();
            AdminActivityLogger::log('order_message.retry', 'طلب إعادة إرسال مرحلة فاشلة', $order,
                ['source' => 'process', 'step_id' => $step->id, 'attempt_id' => $attempt->id, 'reason' => $reason], $actor);
        });
    }

    public static function retainHistorical(OrderProcessStep $step): void
    {
        if ($step->messageAttempts()->exists() || ! $step->started_at) {
            return;
        }
        $step->messageAttempts()->create(['number' => 1, 'status' => $step->status,
            'receipt' => $step->receipt, 'started_at' => $step->started_at, 'completed_at' => $step->completed_at,
            'error_code' => $step->run->error_code]);
    }
}
