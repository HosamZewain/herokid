<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A system assignee in the existing assignment UI, never an employee account. */
class AutomationAssignment
{
    public function keys(bool $includeScenes = true): Builder
    {
        $runs = DB::table('order_process_runs as owned_run')->select('owned_run.checkout_key')
            ->where(function ($q) {
                $q->whereIn('owned_run.status', ['queued', 'running', 'sending', 'waiting_identity', 'waiting_response', 'retry_queued', 'uncertain'])
                    ->orWhereExists(fn ($task) => $task->selectRaw('1')->from('order_process_identities')->whereColumn('run_id', 'owned_run.id')->whereIn('status', ['processing', 'uncertain']))
                    ->orWhere(function ($waiting) {
                        $waiting->where('owned_run.status', 'waiting_staff')
                            ->whereExists(fn ($step) => $step->selectRaw('1')->from('order_process_steps')
                                ->whereColumn('run_id', 'owned_run.id')->whereIn('type', ['approve_identity', 'approve_previews'])->where('status', 'completed'))
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
            ->where('candidate_flow.accepts_new_runs', true)
            ->join('order_automation_revisions as candidate_revision', 'candidate_revision.id', '=', 'candidate_flow.revision_id')
            ->where('candidate_event.status', 'pending')
            ->whereNotIn('candidate_event.checkout_key', DB::table('order_automation_holds')->select('checkout_key'))
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

        $puzzles = DB::table('puzzle_generations')->select('checkout_key')
            ->whereIn('status', ['queued', 'processing', 'uncertain'])
            ->where(fn ($q) => $q->whereNotIn('checkout_key', DB::table('order_automation_holds')->select('checkout_key'))->orWhereNotNull('requested_by'));
        $keys = $runs->union($confirmations)->union($evaluating)->union($puzzles);

        if (app(PuzzleAutomation::class)->enabled()) {
            $floor = max((int) config('puzzle_automation.after_event_id'), (int) DB::table('puzzle_automation_cursors')->where('id', 1)->value('event_id'));
            $pendingPuzzles = DB::table('order_process_events as puzzle_event')->select('puzzle_event.checkout_key')
                ->join('orders as puzzle_order', 'puzzle_order.checkout_group_key', '=', 'puzzle_event.checkout_key')
                ->join('order_items as puzzle_item', 'puzzle_item.order_id', '=', 'puzzle_order.id')
                ->join('products as puzzle_product', 'puzzle_product.id', '=', 'puzzle_item.product_id')
                ->where('puzzle_event.id', '>', $floor)->whereIn('puzzle_event.type', ['status_entered', 'automation_resumed'])
                ->where('puzzle_event.data->status', 'new_confirmed')->whereNull('puzzle_order.deleted_at')
                ->whereNotIn('puzzle_order.status', ['new', 'cancelled', 'completed'])
                ->whereIn('puzzle_product.slug', config('puzzle_automation.product_slugs'))
                ->whereNotIn('puzzle_event.checkout_key', DB::table('order_automation_holds')->select('checkout_key'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('puzzle_generations')->whereColumn('order_item_id', 'puzzle_item.id'));
            $keys->union($pendingPuzzles);
        }

        return $includeScenes ? $keys->union($sceneTrials) : $keys;
    }

    public function owns(Order $order, bool $includeScenes = true): bool
    {
        if ($order->trashed()) {
            return false;
        }

        return DB::query()->fromSub($this->keys($includeScenes), 'automation_owned')
            ->where('checkout_key', $order->checkoutGroupKey())->exists();
    }

    public function assertAvailable(Order $order): void
    {
        if ($this->owns($order)) {
            throw ValidationException::withMessages(['assignment' => 'المسؤول الحالي هو Automation. انتظر تسليم الطلب للتيم أو أوقف تنفيذ هذا الطلب من شاشة الأوتوميشن قبل استلامه.']);
        }
    }
}
