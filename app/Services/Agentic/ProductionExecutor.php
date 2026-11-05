<?php

namespace App\Services\Agentic;

use App\Jobs\AdvanceAutoPilotMessage;
use App\Jobs\SendOrderConfirmationJob;
use App\Models\AiAgentRun;
use App\Models\AiCheckoutState;
use App\Models\AiMemory;
use App\Models\AiOrderDecision;
use App\Models\AiProductionExecution;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\Agentic\Providers\CodexSdkProvider;
use App\Services\CodexScenes\BookPageService;
use App\Services\CodexScenes\ManualSceneService;
use App\Services\Orders\AutomationControl;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\RoboDesk\OrderConfirmationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Trusted application admission, outside the model sandbox. Never calls a paid provider. */
class ProductionExecutor
{
    public function __construct(private ProductionExecutionSettings $settings, private OrderContextBuilder $builder) {}

    public function dispatchPass(): array
    {
        $c = $this->settings->approved();
        if (! $c || ! config('agentic.observe') || ! config('agentic.evaluate') || config('agentic.provider') !== 'codex') {
            return ['status' => 'disabled', 'admitted' => 0];
        }
        if (! app(CodexSdkProvider::class)->health()['ready']) {
            return ['status' => 'provider_unavailable', 'admitted' => 0];
        }
        $decisions = AiOrderDecision::where('decision->decision', 'execute')
            ->whereNotIn('id', AiProductionExecution::select('decision_id'))
            ->whereIn('agent_run_id', AiAgentRun::where('provider', 'codex')->where('status', 'simulated')
                ->where('started_at', '>=', $c['since'])->whereNotNull('finished_at')->select('id'))
            ->orderBy('id')->limit(max(1, min(3, (int) ($c['batch_size'] ?? 3))))->get();
        $results = [];
        foreach ($decisions as $decision) {
            $results[] = $this->admit($decision, $c);
        }

        return ['status' => 'checked', 'admitted' => count(array_filter($results, fn ($r) => $r === 'admitted')), 'results' => $results];
    }

    private function admit(AiOrderDecision $receipt, array $c): string
    {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new RuntimeException('execution_requires_own_transaction');
        }
        if (DB::getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            // A REPEATABLE READ snapshot opened before the checkout lock could hide
            // a committed edit from the final preflight. Apply only to this next
            // transaction, never to later jobs on the shared worker connection.
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return DB::transaction(function () use ($receipt, $c) {
            // Serialize budget, pause and admission receipts; never retry a transaction
            // containing an executor. Executors acquire their normal checkout/pool locks.
            $control = DB::table('ai_production_controls')->where('id', 1)->lockForUpdate()->first();
            if (! $control || $control->paused) {
                return 'paused';
            }
            $day = now('Africa/Cairo')->toDateString();
            $used = $control->quota_day === $day ? $control->admissions : 0;
            if (! $c['unlimited'] && $used >= $c['daily_limit']) {
                return 'quota_deferred';
            }
            if (AiProductionExecution::where('decision_id', $receipt->id)->exists()) {
                return 'already_recorded';
            }
            $run = AiAgentRun::findOrFail($receipt->agent_run_id);
            $state = AiCheckoutState::findOrFail($run->checkout_state_id);
            $decision = app(DecisionValidator::class)->validate($receipt->decision);
            $unit = collect($run->snapshot['units'])->firstWhere('unit_key', $decision['target_id']);
            $orderId = $unit ? (int) substr($unit['order_ref'], 6) : $state->representative_id;
            $key = hash('sha256', json_encode([$state->checkout_key, $decision['target_id'], $decision['action'], $run->fingerprint]));
            $duplicate = AiProductionExecution::where('idempotency_key', $key)->exists();
            $result = ['status' => 'blocked', 'reason_code' => 'EXECUTOR_PREFLIGHT_FAILED'];
            try {
                $result = DB::transaction(function () use ($receipt, $run, $state, $decision, $unit, $c, $orderId, $duplicate) {
                    if ($duplicate) {
                        throw new RuntimeException('EXECUTION_ALREADY_RECORDED');
                    }
                    if (! $this->settings->includes($c, $state->representative_id) || ! in_array($decision['action'], $c['actions'], true)) {
                        throw new RuntimeException('EXECUTION_SCOPE_EXCLUDED');
                    }
                    $meta = $run->provider_metadata ?? [];
                    if ($run->provider !== 'codex' || $run->status !== 'simulated' || ! ($receipt->policy['allowed_proposal'] ?? false)
                        || ($meta['authentication'] ?? null) !== 'chatgpt' || ($meta['tools_used'] ?? null) !== 0
                        || ! preg_match('/\A[a-f0-9]{64}\z/', $meta['isolation_digest'] ?? '')
                        || ($run->snapshot['execution_activation'] ?? null) !== $c['activation_id']) {
                        throw new RuntimeException('EXECUTION_ATTESTATION_REQUIRED');
                    }
                    if ($run->finished_at->lt(now()->subSeconds(max(60, min(900, (int) ($c['decision_max_age_seconds'] ?? 900)))))) {
                        throw new RuntimeException('EXECUTION_DECISION_EXPIRED');
                    }
                    // A memory edit cannot commit between the final check and admission.
                    AiMemory::orderBy('id')->lockForUpdate()->get(['id']);
                    $guard = function () use ($state, $run, $decision): void {
                        $fresh = $this->builder->build($state->checkout_key);
                        if (! hash_equals($run->fingerprint, $fresh['fingerprint'])) {
                            throw new RuntimeException('EXECUTION_STALE_CONTEXT');
                        }
                        $fresh['context_version'] = $run->context_version;
                        if (! app(PolicyEngine::class)->evaluate($decision, $fresh, [$decision['target_id']])['allowed_proposal']) {
                            throw new RuntimeException('EXECUTION_POLICY_BLOCKED');
                        }
                    };
                    $order = Order::where('checkout_group_key', $state->checkout_key)->findOrFail($orderId);
                    if (in_array($decision['action'], ['send_order_confirmation', 'send_product_preview'], true)) {
                        return $this->message($order, $run->snapshot, $decision['action'], $guard);
                    }
                    if (! $unit) {
                        throw new RuntimeException('EXECUTION_UNIT_REQUIRED');
                    }
                    if ($decision['action'] === 'start_story_scene_generation') {
                        $work = app(ManualSceneService::class)->start($order, null, $guard);
                    } else {
                        $item = OrderItem::where('order_id', $order->id)->findOrFail((int) substr($unit['item_ref'], 5));
                        if ($decision['action'] === 'generate_book_pages') {
                            $manifest = OrderReviewManifest::where('checkout_key', $state->checkout_key)->latest('id')->firstOrFail();
                            $work = app(BookPageService::class)->start($item, $manifest, false, $guard);
                        } else {
                            $work = app(PuzzleAutomation::class)->admitForAutoPilot($item, $unit['unit_key'], $guard);
                        }
                    }

                    return ['status' => 'admitted', 'reason_code' => 'EXISTING_EXECUTOR_ACCEPTED',
                        'executor_type' => in_array($decision['action'], ['generate_product_image', 'generate_book_cover'], true) ? 'puzzle' : 'codex_scene',
                        'executor_id' => (string) $work->id];
                });
            } catch (\Throwable $e) {
                $result['reason_code'] = ExecutionFailureReason::from($e);
            }
            $saved = AiProductionExecution::create($result + ['decision_id' => $receipt->id,
                'idempotency_key' => $result['status'] === 'admitted' ? $key : hash('sha256', 'not-admitted:'.$receipt->id),
                'activation_id' => $c['activation_id'], 'checkout_key' => $state->checkout_key, 'order_id' => $orderId,
                'target_id' => $decision['target_id'], 'action' => $decision['action'], 'context_fingerprint' => $run->fingerprint]);
            if ($saved->status === 'admitted') {
                DB::table('ai_production_controls')->where('id', 1)->update(['quota_day' => $day, 'admissions' => $used + 1, 'updated_at' => now()]);
            }
            app(AutoPilotAudit::class)->admission($saved);
            if ($saved->reason_code === 'EXECUTION_DECISION_EXPIRED') {
                // Re-evaluate just this expired target once, through the normal AI
                // quota and scheduler. Other units retain their covered status.
                $current = AiCheckoutState::lockForUpdate()->findOrFail($state->id);
                if ($current->fingerprint === $run->fingerprint && $current->context_version === $run->context_version) {
                    $current->fill(['covered_targets' => array_values(array_diff($current->covered_targets ?? [], [$decision['target_id']])),
                        'status' => 'partial', 'next_evaluation_at' => now(), 'error_code' => null]);
                    $current->trackReadyTargets(app(CapabilityRegistry::class)->targets($current->snapshot));
                    $current->save();
                }
            }

            return $saved->status;
        });
    }

    private function message(Order $order, array $context, string $action, \Closure $guard): array
    {
        $key = $order->checkoutGroupKey();
        if ($expected = $context['message_dispatch'] ?? null) {
            $run = OrderProcessRun::where('checkout_key', $key)->lockForUpdate()->findOrFail($expected['run_id']);
            Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            $guard();
            if ($expected['action'] !== $action || ! AutomationControl::admits($key)
                || app(MessageDispatchContext::class)->forCheckout($key) !== $expected) {
                throw new RuntimeException('EXECUTION_MESSAGE_CHANGED');
            }
            AdvanceAutoPilotMessage::dispatch($run->id, $expected)->afterCommit();

            return ['status' => 'admitted', 'reason_code' => 'EXISTING_EXECUTOR_ACCEPTED', 'executor_type' => 'process_message', 'executor_id' => (string) $run->id];
        }
        Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
        $guard();
        $event = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->latest('id')->lockForUpdate()->first();
        if ($action !== 'send_order_confirmation' || ! $event || $event->status !== 'pending' || $event->attempts !== 0
            || data_get($event->payload, 'manual_retry') || ! AutomationControl::admits($key)
            || ! app(OrderConfirmationService::class)->canSend($event)) {
            throw new RuntimeException('EXECUTION_MESSAGE_CHANGED');
        }
        SendOrderConfirmationJob::dispatch($event->id)->afterCommit();

        return ['status' => 'admitted', 'reason_code' => 'EXISTING_EXECUTOR_ACCEPTED', 'executor_type' => 'confirmation', 'executor_id' => (string) $event->id];
    }
}
