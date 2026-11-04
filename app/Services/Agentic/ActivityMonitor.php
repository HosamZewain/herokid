<?php

namespace App\Services\Agentic;

use App\Models\AiAgentRun;
use App\Models\AiCheckoutState;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only operational telemetry. A display refresh never calls the model. */
class ActivityMonitor
{
    public function record(string $token, string $stage, ?AiCheckoutState $state = null, ?AiAgentRun $run = null, array $targets = []): void
    {
        DB::table('ai_runtime_states')->where('id', 1)->where('lease_token', $token)->update([
            'activity_stage' => $stage, 'activity_state_id' => $state?->id, 'activity_run_id' => $run?->id,
            'activity_targets' => json_encode($targets), 'activity_updated_at' => now(),
        ]);
    }

    public function snapshot(): array
    {
        $control = DB::table('ai_runtime_states')->where('id', 1)->first();
        $stage = 'idle';
        $working = false;
        $lease = $control?->lease_token;
        if ($lease) {
            $expired = ! $control->lease_until || Carbon::parse($control->lease_until)->isPast();
            $timeout = $control->activity_stage === 'awaiting_decision' ? (int) config('agentic.timeout_seconds', 90) + 15 : 120;
            $stalled = $control->activity_updated_at && Carbon::parse($control->activity_updated_at)->lt(now()->subSeconds($timeout));
            $stage = $expired ? 'worker_unknown' : ($stalled ? 'stalled' : $control->activity_stage);
            $working = ! $expired && ! $stalled;
        } elseif (! config('agentic.observe')) {
            $stage = 'observation_disabled';
        } elseif (! config('agentic.evaluate')) {
            $stage = 'evaluation_disabled';
        } elseif ($control?->provider_error) {
            $stage = 'provider_unavailable';
        } elseif ($control?->quota_day === now('Africa/Cairo')->toDateString() && $control->attempts >= max(0, (int) config('agentic.daily_limit'))) {
            $stage = 'quota_deferred';
        } elseif (AiCheckoutState::whereIn('status', ['pending', 'partial', 'quota_deferred'])->exists()) {
            $stage = 'waiting_queue';
        } elseif (AiCheckoutState::whereIn('status', ['error', 'observation_error'])->exists()) {
            $stage = 'attention_required';
        } elseif (AiCheckoutState::whereNotNull('dirty_at')->exists()
            || AutoPilotOrderScope::activeOrdersQuery()->whereNotIn('checkout_group_key', AiCheckoutState::whereNotIn('status', ['unobserved', 'redirected'])->select('checkout_key'))->exists()) {
            $stage = 'awaiting_observation';
        }
        $state = $control?->activity_state_id ? AiCheckoutState::find($control->activity_state_id) : null;
        $order = $state?->representative_id ? Order::with('checkoutReference')->find($state->representative_id) : null;
        $lastRun = $control?->last_run_id ? AiAgentRun::with('decision')->find($control->last_run_id) : null;

        return ['stage' => $stage, 'working' => $working, 'order' => $order, 'execution' => app(ExecutionMonitor::class)->snapshot(),
            'order_reference' => $order?->checkoutReference?->short_reference ?: ($order ? 'Order '.$order->id : null),
            'targets' => json_decode($control?->activity_targets ?? '[]', true) ?: [],
            'started_at' => $control?->activity_started_at, 'updated_at' => $control?->activity_updated_at,
            'last_outcome' => $control?->last_outcome, 'last_run' => $lastRun,
            'observed_at' => AiCheckoutState::max('observed_at'), 'provider_checked_at' => $control?->provider_checked_at,
            'provider_error' => $control?->provider_error, 'read_at' => now()->toISOString()];
    }
}
