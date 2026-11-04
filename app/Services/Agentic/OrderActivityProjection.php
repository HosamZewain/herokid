<?php

namespace App\Services\Agentic;

use App\Models\AiAgentRun;
use App\Models\AiCheckoutState;
use App\Models\Order;
use App\Services\Orders\OrderAccess;
use Illuminate\Support\Facades\DB;

/** Order page reads saved decisions without invoking AI or altering production. */
class OrderActivityProjection
{
    public function forOrder(Order $order): array
    {
        $key = app(OrderContextBuilder::class)->canonicalKey($order->checkoutGroupKey());
        if ($key !== $order->checkoutGroupKey()) {
            // A merge alias cannot reveal a checkout outside this employee's scope.
            app(OrderAccess::class)->assert(Order::where('checkout_group_key', $key)->firstOrFail());
        }
        $state = AiCheckoutState::where('checkout_key', $key)->first();
        $runs = AiAgentRun::where('checkout_state_id', $state?->id ?? 0);

        return ['state' => $state, 'total' => (clone $runs)->count(),
            'runtime' => DB::table('ai_runtime_states')->where('id', 1)
                ->first(['provider_error', 'quota_day', 'attempts', 'activity_state_id', 'activity_stage', 'lease_until']),
            'history' => $runs->with('decision')->orderByDesc('started_at')->orderByDesc('id')->limit(10)->get(),
            'targets' => $state?->snapshot ? app(CapabilityRegistry::class)->targets($state->snapshot) : [],
            'execution' => app(ExecutionMonitor::class)->snapshot($key)];
    }
}
