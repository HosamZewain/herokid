<?php

namespace App\Services\Agentic;

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
        $models = ['puzzle' => PuzzleGeneration::class, 'codex_scene' => CodexSceneRun::class,
            'confirmation' => RoboDeskIntegrationEvent::class, 'process_message' => OrderProcessRun::class];
        $work = [];
        $activeCount = 0;
        if ($recent->isNotEmpty()) {
            // Old in-flight work must not disappear behind newer completed receipts.
            foreach ($models as $type => $class) {
                $active = $class::whereIn('id', (clone $query)->where('executor_type', $type)->where('status', 'admitted')->select('executor_id'))
                    ->whereIn('status', ['queued', 'pending', 'processing', 'preparing', 'running', 'waiting_slot', 'sending', 'stopping', 'waiting_identity', 'waiting_response', 'waiting_staff']);
                $activeCount += (clone $active)->count();
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
            'current_status' => $work[$r->executor_type ?? 'puzzle']->get($r->executor_id)?->status ?? null]);

        return ['enabled' => app(ProductionExecutionSettings::class)->approved() !== null && config('agentic.observe') && config('agentic.evaluate') && config('agentic.provider') === 'codex',
            'control' => DB::table('ai_production_controls')->where('id', 1)->first(), 'rows' => $rows,
            'unlimited' => config('agentic.execution.unlimited') === true,
            'total' => $query->count(), 'active_count' => $activeCount, 'daily_limit' => (int) config('agentic.execution.daily_limit', 0)];
    }
}
