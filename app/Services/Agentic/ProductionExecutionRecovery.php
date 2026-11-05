<?php

namespace App\Services\Agentic;

use App\Models\AdminActivityLog;
use App\Models\AiCheckoutState;
use App\Models\AiProductionExecution;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\OrderAccess;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Existing immutable receipts are the issue ledger; reads never call AI or a native executor. */
final class ProductionExecutionRecovery
{
    public const AUDIT_ACTION = 'autopilot.execution_recheck_requested';

    public function current(?string $checkoutKey = null): Collection
    {
        $activeKeys = AutoPilotOrderScope::activeOrdersQuery()
            ->whereNotIn('checkout_group_key', DB::table('order_group_merge_aliases')->select('source_checkout_group_key'))
            ->select('checkout_group_key');

        return AiProductionExecution::query()->join('ai_checkout_states as s', 's.checkout_key', '=', 'ai_production_executions.checkout_key')
            ->join('ai_order_decisions as d', 'd.id', '=', 'ai_production_executions.decision_id')
            ->join('ai_agent_runs as r', 'r.id', '=', 'd.agent_run_id')
            ->where('ai_production_executions.status', 'blocked')
            ->whereNotIn('ai_production_executions.reason_code', ['EXECUTION_DECISION_EXPIRED', 'EXECUTION_STALE_CONTEXT', 'EXECUTION_ALREADY_RECORDED'])
            ->whereNull('s.dirty_at')
            ->whereNotIn('s.status', ['inactive', 'redirected'])
            ->whereColumn('s.fingerprint', 'ai_production_executions.context_fingerprint')
            ->whereColumn('s.context_version', 'r.context_version')
            ->whereIn('ai_production_executions.checkout_key', $activeKeys)
            ->when($checkoutKey, fn ($q) => $q->where('ai_production_executions.checkout_key', $checkoutKey))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('ai_production_executions as newer')
                ->whereColumn('newer.checkout_key', 'ai_production_executions.checkout_key')
                ->whereColumn('newer.target_id', 'ai_production_executions.target_id')
                ->whereColumn('newer.id', '>', 'ai_production_executions.id'))
            ->orderBy('ai_production_executions.id')->get(['ai_production_executions.*']);
    }

    public function requested(Collection $receipts): Collection
    {
        return AdminActivityLog::where('action', self::AUDIT_ACTION)->where('subject_type', AiProductionExecution::class)
            ->whereIn('subject_id', $receipts->pluck('id'))->pluck('subject_id');
    }

    public function canRecheck(AiProductionExecution $receipt, ?AiCheckoutState $state): bool
    {
        $settings = app(ProductionExecutionSettings::class)->approved();
        if (! $state || ! $settings || ! config('agentic.observe') || ! config('agentic.evaluate') || config('agentic.provider') !== 'codex'
            || $receipt->status !== 'blocked' || $receipt->executor_id !== null || $receipt->executor_type !== null || $state->dirty_at
            || ! in_array($state->status, ['covered', 'partial'], true)
            || ! ExecutionFailureReason::allowsRecheck($receipt->reason_code)
            || ! hash_equals($receipt->context_fingerprint, $state->fingerprint ?? '')
            || ! in_array($receipt->target_id, $state->covered_targets ?? [], true)
            || ! app(ProductionExecutionSettings::class)->includes($settings, $state->representative_id)
            || ! in_array($receipt->action, $settings['actions'], true)
            || ($state->snapshot['execution_activation'] ?? null) !== $settings['activation_id']) {
            return false;
        }
        $target = collect(app(CapabilityRegistry::class)->targets($state->snapshot))->firstWhere('target_id', $receipt->target_id);

        return $target && $target['decision'] === 'execute' && $target['action'] === $receipt->action
            && DB::table('ai_order_decisions as d')->join('ai_agent_runs as r', 'r.id', '=', 'd.agent_run_id')
                ->where('d.id', $receipt->decision_id)->where('r.context_version', $state->context_version)->exists();
    }

    public function recheck(Order $order, AiProductionExecution $receipt, User $actor, Request $request, int $version): void
    {
        abort_unless($order->checkoutGroupKey() === $receipt->checkout_key, 404);
        $this->authorize($order, $actor->fresh());
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new RuntimeException('recheck_requires_own_transaction');
        }
        if (DB::getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        DB::transaction(function () use ($order, $receipt, $actor, $request, $version): void {
            // Same order as trusted admission/memory edits, then checkout and state.
            $control = DB::table('ai_production_controls')->where('id', 1)->lockForUpdate()->first();
            abort_unless($control && ! $control->paused, 409, 'تسليم الخطوات الجديدة متوقف؛ راجع المسؤول.');
            $rows = Order::where('checkout_group_key', $receipt->checkout_key)->orderBy('id')->lockForUpdate()->get();
            $order = $rows->firstWhere('id', $order->id);
            abort_unless($order, 404);
            DB::table('order_group_assignments')->where('checkout_group_key', $receipt->checkout_key)->lockForUpdate()->first();
            $this->authorize($order, $actor->fresh());
            $nativeControl = DB::table('order_automation_controls')->where('checkout_key', $receipt->checkout_key)->lockForUpdate()->first();
            abort_if(($nativeControl?->state ?? 'running') !== 'running' || AutomationHandoff::held($receipt->checkout_key)
                || app(AutomationAssignment::class)->owns($order), 409, 'الطلب متوقف أو له عمل قائم؛ راجع التحكم والنتائج الحالية.');
            $state = AiCheckoutState::where('checkout_key', $receipt->checkout_key)->lockForUpdate()->first();
            abort_unless($state && $state->context_version === $version && $this->canRecheck($receipt, $state), 409, 'تغيّرت الخطوة أو لم تعد مؤهلة لإعادة الفحص؛ حدّث الصفحة.');
            abort_if(AiProductionExecution::where('checkout_key', $receipt->checkout_key)->where('target_id', $receipt->target_id)->where('id', '>', $receipt->id)->exists()
                || $this->requested(collect([$receipt]))->isNotEmpty(), 409, 'يوجد إجراء أحدث لهذه الخطوة؛ حدّث الصفحة.');
            $fresh = app(OrderContextBuilder::class)->build($receipt->checkout_key);
            abort_unless(hash_equals($state->fingerprint, $fresh['fingerprint']), 409, 'تغيّرت بيانات الطلب؛ انتظر تحديث المتابعة ثم أعد الفحص.');
            $target = collect(app(CapabilityRegistry::class)->targets($fresh))->firstWhere('target_id', $receipt->target_id);
            abort_unless($target && $target['decision'] === 'execute' && $target['action'] === $receipt->action, 409, 'راجع السبب الحالي للطلب قبل إعادة الفحص.');
            $audit = AdminActivityLogger::log(self::AUDIT_ACTION, 'طلب إعادة فحص خطوة رفض إدخالها للتنفيذ؛ المحاولة السابقة محفوظة.', $receipt,
                ['order_id' => $order->id, 'target_id' => $receipt->target_id, 'context_version' => $version], $actor, $request);
            if (! $audit) {
                throw new RuntimeException('recheck_audit_required');
            }
            $state->fill(['covered_targets' => array_values(array_diff($state->covered_targets ?? [], [$receipt->target_id])),
                'status' => 'partial', 'error_code' => null, 'next_evaluation_at' => now()]);
            $state->trackReadyTargets(app(CapabilityRegistry::class)->targets($fresh));
            $state->save();
        });
    }

    private function authorize(Order $order, ?User $actor): void
    {
        abort_unless($actor && $actor->role === 'admin' && app(OrderAccess::class)->controls($order, $actor)
            && $actor->hasPermission('order_automation.view') && $actor->hasPermission('order_automation.manage')
            && $actor->hasPermission('order_automation.execute'), 403);
    }
}
