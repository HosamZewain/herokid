<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A system assignee in the existing assignment UI, never an employee account. */
class AutomationAssignment
{
    public function keys(): Builder
    {
        $runs = DB::table('order_process_runs as owned_run')->select('owned_run.checkout_key')
            ->where(function ($q) {
                $q->whereIn('owned_run.status', ['queued', 'running', 'sending', 'waiting_identity', 'waiting_response', 'retry_queued', 'uncertain'])
                    ->orWhereExists(fn ($task) => $task->selectRaw('1')->from('order_process_identities')->whereColumn('run_id', 'owned_run.id')->whereIn('status', ['processing', 'uncertain']))
                    ->orWhere(function ($waiting) {
                        $waiting->where('owned_run.status', 'waiting_staff')
                            ->whereExists(fn ($step) => $step->selectRaw('1')->from('order_process_steps')
                                ->whereColumn('run_id', 'owned_run.id')->where('type', 'approve_identity')->where('status', 'completed'))
                            ->whereNotExists(fn ($review) => $review->selectRaw('1')->from('order_payment_reviews')
                                ->whereColumn('run_id', 'owned_run.id'))
                            ->whereNotExists(fn ($paid) => $paid->selectRaw('1')->from('orders as paid_order')
                                ->whereColumn('paid_order.checkout_group_key', 'owned_run.checkout_key')->where('paid_amount_cents', '>', 0));
                    });
            });
        $confirmations = DB::table('robodesk_integration_events as confirmation')->select('confirmation.checkout_group_key')
            ->where('event_type', 'order.pending_confirmation')->whereIn('confirmation.status', ['pending', 'processing', 'succeeded', 'unknown'])
            ->where('payload->confirmation->eligible_at_creation', true)
            ->where(fn ($q) => $q->whereNull('response_payload->delivery->status')->orWhere('response_payload->delivery->status', '!=', 'failed'))
            ->whereExists(fn ($order) => $order->selectRaw('1')->from('orders as confirming_order')
                ->whereColumn('confirming_order.checkout_group_key', 'confirmation.checkout_group_key')
                ->whereNull('confirming_order.deleted_at')->where('confirming_order.status', 'new'));

        // Reserve the short interval between a committed status event and enrollment.
        // Full catalog/permission conditions are evaluated by the worker; rejected events
        // release this temporary reservation when marked processed.
        $evaluating = DB::table('order_process_events as candidate_event')->select('candidate_event.checkout_key')
            ->join('order_automations as candidate_flow', 'candidate_flow.enabled', '=', DB::raw('1'))
            ->join('order_automation_revisions as candidate_revision', 'candidate_revision.id', '=', 'candidate_flow.revision_id')
            ->where('candidate_event.status', 'pending')
            ->whereColumn('candidate_event.id', '>', 'candidate_flow.process_after_event_id')
            ->whereColumn('candidate_revision.definition->trigger', 'candidate_event.type')
            ->whereColumn('candidate_revision.definition->conditions->status', 'candidate_event.data->status')
            ->whereNotExists(fn ($existing) => $existing->selectRaw('1')->from('order_process_runs as prior_run')
                ->whereColumn('prior_run.automation_id', 'candidate_flow.id')->whereColumn('prior_run.checkout_key', 'candidate_event.checkout_key'));
        if (! app(ProcessLivePolicy::class)->enabled()) {
            $evaluating->whereRaw('1 = 0');
        } elseif (config('order_process.enrollment_mode') === 'trial') {
            $evaluating->where('candidate_flow.id', (int) config('order_process.trial_automation_id'))
                ->where('candidate_event.order_id', (int) config('order_process.trial_order_id'));
        }

        $sceneTrials = DB::table('codex_scene_runs')->select('checkout_key')->where('active_slot', 1);

        return $runs->union($confirmations)->union($evaluating)->union($sceneTrials);
    }

    public function owns(Order $order): bool
    {
        if ($order->trashed()) {
            return false;
        }

        return DB::query()->fromSub($this->keys(), 'automation_owned')
            ->where('checkout_key', $order->checkoutGroupKey())->exists();
    }

    public function assertAvailable(Order $order): void
    {
        if ($this->owns($order)) {
            throw ValidationException::withMessages(['assignment' => 'المسؤول الحالي هو Automation. انتظر تسليم الطلب للتيم أو أوقف تنفيذ هذا الطلب من شاشة الأوتوميشن قبل استلامه.']);
        }
    }
}
