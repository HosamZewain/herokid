<?php

namespace App\Services\Agentic;

use App\Models\AiCheckoutState;
use App\Models\AiOrderDecision;
use App\Models\AiProductionExecution;
use Illuminate\Support\Facades\DB;

/** Read-only display projection. Never refreshes context or calls a provider. */
class DashboardMonitor
{
    public function snapshot(): array
    {
        $keys = AutoPilotOrderScope::activeOrdersQuery()
            ->whereNotIn('checkout_group_key', DB::table('order_group_merge_aliases')->select('source_checkout_group_key'))
            ->select('checkout_group_key')->distinct();
        $active = DB::query()->fromSub(clone $keys, 'active')->count();
        $states = AiCheckoutState::whereIn('checkout_key', $keys);
        $observed = (clone $states)->whereNotNull('observed_at')->count();
        $covered = (clone $states)->where('status', 'covered')->whereNull('dirty_at')->count();
        $errors = (clone $states)->whereIn('status', ['error', 'observation_error'])->count();
        // A checkout contributes at most one latest proposal. Dirty/obsolete
        // proposals remain in history, never presented as a current conclusion.
        $latest = AiOrderDecision::query()->join('ai_agent_runs as r', 'r.id', '=', 'ai_order_decisions.agent_run_id')
            ->join('ai_checkout_states as s', 's.last_run_id', '=', 'r.id')
            ->whereIn('s.checkout_key', clone $keys)->whereNull('s.dirty_at')
            ->whereColumn('s.fingerprint', 'r.fingerprint')->whereColumn('s.context_version', 'r.context_version')
            ->whereIn('s.status', ['covered', 'partial'])
            ->get(['ai_order_decisions.decision']);
        $groups = ['wait' => [], 'attention' => []];
        foreach ($latest as $proposal) {
            $decision = $proposal->decision;
            $bucket = match ($decision['decision'] ?? '') {
                'wait' => 'wait', 'human_required', 'escalate' => 'attention', default => null,
            };
            if ($bucket) {
                $reason = $decision['reason_code'] ?? 'UNKNOWN';
                $key = ($decision['decision'] ?? '').':'.$reason;
                $groups[$bucket][$key] ??= ['reason' => $reason, 'decision' => $decision['decision'], 'count' => 0];
                $groups[$bucket][$key]['count']++;
            }
        }
        foreach ($groups as &$group) {
            uasort($group, fn ($a, $b) => $b['count'] <=> $a['count']);
            $group = array_values($group);
        }
        unset($group);
        $rejections = AiProductionExecution::where('status', '!=', 'admitted')
            ->selectRaw('reason_code, COUNT(*) as total')->groupBy('reason_code')->orderByDesc('total')->get();
        $runtime = DB::table('ai_runtime_states')->where('id', 1)->first();

        return ['active' => $active, 'covered' => $covered, 'unobserved' => max(0, $active - $observed),
            'uncovered' => max(0, $active - $covered), 'errors' => $errors, 'groups' => $groups,
            'rejections' => $rejections, 'attempts' => $runtime?->quota_day === now('Africa/Cairo')->toDateString() ? (int) $runtime->attempts : 0,
            'daily_limit' => max(0, (int) config('agentic.daily_limit')), 'read_at' => now(),
            'reconciled_at' => $runtime?->reconciled_at];
    }
}
