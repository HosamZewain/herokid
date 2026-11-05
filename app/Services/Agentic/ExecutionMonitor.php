<?php

namespace App\Services\Agentic;

use App\Models\AiCheckoutState;
use App\Models\AiProductionExecution;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderProcessRun;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use Illuminate\Support\Facades\DB;

class ExecutionMonitor
{
    public function snapshot(?string $checkoutKey = null): array
    {
        $query = AiProductionExecution::query()->when($checkoutKey, fn ($q) => $q->where('checkout_key', $checkoutKey));
        $recent = (clone $query)->latest('id')->limit(20)->get();
        $recovery = app(ProductionExecutionRecovery::class);
        $issues = $recovery->current($checkoutKey);
        $requested = $recovery->requested($issues);
        $states = AiCheckoutState::whereIn('checkout_key', $issues->pluck('checkout_key'))->get()->keyBy('checkout_key');
        $control = DB::table('ai_production_controls')->where('id', 1)->first();
        // Covered evaluation does not resolve a rejected admission. Keep current
        // unresolved receipts visible even behind newer unrelated completed work.
        $recent = $recent->merge($issues->take(20))->unique('id');
        $models = ['puzzle' => PuzzleGeneration::class, 'codex_scene' => CodexSceneRun::class,
            'confirmation' => RoboDeskIntegrationEvent::class, 'process_message' => OrderProcessRun::class];
        $outcomes = array_fill_keys(['waiting', 'running', 'production_completed', 'message_accepted', 'workflow_completed', 'failed', 'review', 'stopped', 'unknown'], 0);
        foreach ($models as $type => $class) {
            // Count distinct native work, across all receipts, not just the display's
            // last twenty. A shared process run is one workflow, not N delivered messages.
            $native = $class::whereIn('id', (clone $query)->where('executor_type', $type)->where('status', 'admitted')->select('executor_id'));
            foreach ((clone $native)->selectRaw('status, COUNT(*) as total')->groupBy('status')->get() as $group) {
                $outcomes[$this->outcome($type, $group->status)] += (int) $group->total;
            }
            $known = (clone $native)->count();
            $expected = (clone $query)->where('executor_type', $type)->where('status', 'admitted')->whereNotNull('executor_id')->distinct()->count('executor_id');
            $outcomes['unknown'] += max(0, $expected - $known);
        }
        $outcomes['unknown'] += (clone $query)->where('status', 'admitted')
            ->where(fn ($q) => $q->whereNull('executor_type')->orWhereNull('executor_id')->orWhereNotIn('executor_type', array_keys($models)))->count();
        $ready = AiCheckoutState::when($checkoutKey, fn ($q) => $q->where('checkout_key', $checkoutKey))
            ->whereNotIn('status', ['inactive', 'redirected'])->where('ready_target_count', '>', 0);
        $readiness = ['checkouts' => (clone $ready)->count(), 'targets' => (int) (clone $ready)->sum('ready_target_count'),
            'oldest_at' => (clone $ready)->min('ready_since'),
            'attention_checkouts' => (clone $ready)->whereIn('status', ['error', 'observation_error'])->count()];
        $work = [];
        if ($recent->isNotEmpty()) {
            // Old in-flight work must not disappear behind newer completed receipts.
            foreach ($models as $type => $class) {
                $active = $class::whereIn('id', (clone $query)->where('executor_type', $type)->where('status', 'admitted')->select('executor_id'))
                    ->whereIn('status', ['queued', 'pending', 'processing', 'preparing', 'running', 'waiting_slot', 'sending', 'stopping', 'waiting_identity', 'waiting_response', 'waiting_staff', 'retry_queued', 'retry_authorized']);
                $ids = $active->orderBy('id')->limit(20)->pluck('id');
                if ($ids->isNotEmpty()) {
                    $recent = $recent->merge((clone $query)->where('executor_type', $type)->whereIn('executor_id', $ids)->latest('id')->limit(20)->get())->unique('id');
                }
            }
        }
        foreach ($models as $type => $class) {
            $ids = $recent->where('executor_type', $type)->pluck('executor_id')->filter();
            $work[$type] = $ids->isEmpty() ? collect() : $class::whereIn('id', $ids)->get(['id', 'status'])->keyBy('id');
        }
        $orders = Order::whereIn('id', $recent->pluck('order_id'))->with('checkoutReference:id,checkout_group_key,short_reference')->get()->keyBy('id');
        $rows = $recent->map(fn ($r) => ['receipt' => $r, 'order' => $orders->get($r->order_id),
            'current_status' => ($work[$r->executor_type ?? ''] ?? collect())->get($r->executor_id)?->status ?? null,
            'needs_intervention' => $issues->contains('id', $r->id),
            'recheck_requested' => $requested->contains($r->id),
            'recheck_available' => ! $control?->paused && $issues->contains('id', $r->id) && ! $requested->contains($r->id)
                && $recovery->canRecheck($r, $states->get($r->checkout_key)),
            'context_version' => $states->get($r->checkout_key)?->context_version]);

        return ['enabled' => app(ProductionExecutionSettings::class)->approved() !== null && config('agentic.observe') && config('agentic.evaluate') && config('agentic.provider') === 'codex',
            'control' => $control, 'rows' => $rows,
            'intervention_count' => $issues->count(), 'recheck_requested_count' => $requested->unique()->count(),
            'unlimited' => config('agentic.execution.unlimited') === true,
            'outcomes' => $outcomes, 'readiness' => $readiness,
            'admitted' => (clone $query)->where('status', 'admitted')->count(),
            'not_admitted' => (clone $query)->where('status', '!=', 'admitted')->count(),
            'total' => $query->count(), 'active_count' => $outcomes['waiting'] + $outcomes['running'], 'daily_limit' => (int) config('agentic.execution.daily_limit', 0)];
    }

    private function outcome(string $type, ?string $status): string
    {
        return match (true) {
            in_array($status, ['queued', 'pending', 'waiting_slot', 'waiting_identity', 'waiting_response', 'waiting_staff', 'retry_queued', 'retry_authorized'], true) => 'waiting',
            in_array($status, ['processing', 'preparing', 'running', 'sending', 'stopping'], true) => 'running',
            $status === 'failed' => 'failed',
            in_array($status, ['blocked', 'review', 'manual_artwork', 'needs_manual', 'quality_review', 'held', 'paused'], true) => 'review',
            in_array($status, ['cancelled', 'stopped'], true) => 'stopped',
            $type === 'confirmation' && $status === 'succeeded' => 'message_accepted',
            $type === 'process_message' && $status === 'completed' => 'workflow_completed',
            in_array($type, ['puzzle', 'codex_scene'], true) && in_array($status, ['succeeded', 'completed'], true) => 'production_completed',
            default => 'unknown',
        };
    }
}
