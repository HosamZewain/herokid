<?php

namespace App\Services\Agentic\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local approved benchmark calls share the same quota and lease as observation. */
class EvaluationBudgetProvider implements DecisionProvider
{
    public function __construct(private CodexSdkProvider $provider) {}

    public function name(): string
    {
        return 'codex';
    }

    public function health(): array
    {
        return $this->provider->health();
    }

    public function decide(array $input): array
    {
        if (! app()->environment(['local', 'testing']) || ! config('agentic.evaluate') || ! ($this->health()['ready'] ?? false)) {
            throw new \RuntimeException('benchmark_provider_not_ready');
        }
        $token = (string) Str::uuid();
        DB::transaction(function () use ($token) {
            $row = DB::table('ai_runtime_states')->where('id', 1)->lockForUpdate()->first();
            // An expired lease still needs the orchestrator's uncertainty reconciliation.
            if ($row->lease_token) {
                throw new \RuntimeException('benchmark_busy');
            }
            $day = now('Africa/Cairo')->toDateString();
            $used = $row->quota_day === $day ? $row->attempts : 0;
            if ($used >= max(0, (int) config('agentic.daily_limit'))) {
                throw new \RuntimeException('benchmark_quota_exhausted');
            }
            DB::table('ai_runtime_states')->where('id', 1)->update(['quota_day' => $day, 'attempts' => $used + 1,
                'lease_token' => $token, 'lease_until' => now()->addSeconds(120), 'updated_at' => now()]);
        });
        try {
            return $this->provider->decide($input);
        } finally {
            DB::table('ai_runtime_states')->where('id', 1)->where('lease_token', $token)->update(['lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
        }
    }
}
