<?php

namespace App\Services\Agentic\Providers;

/** Contract-test double, never evidence of real model accuracy. */
class MockProvider implements DecisionProvider
{
    public function name(): string
    {
        return 'mock';
    }

    public function health(): array
    {
        return ['ready' => app()->environment(['local', 'testing']), 'reason' => 'mock_not_a_real_model'];
    }

    public function decide(array $input): array
    {
        if (! $this->health()['ready']) {
            throw new \RuntimeException('mock_forbidden_in_production');
        }
        $target = collect($input['capabilities'])->first(fn ($t) => in_array($t['target_id'], $input['remaining_targets'], true));
        unset($target['requires_executor_preflight']);

        return ['decision' => $target + ['reason' => 'Deterministic contract fixture: '.$target['reason_code'],
            'confidence' => 1.0, 'context_version' => $input['context']['context_version']], 'metadata' => ['provider' => 'mock', 'real_model' => false]];
    }
}
