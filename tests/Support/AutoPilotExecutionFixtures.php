<?php

namespace Tests\Support;

use App\Services\Agentic\Providers\CodexSdkProvider;
use App\Services\Agentic\Providers\DecisionProvider;
use App\Services\Agentic\Providers\MockProvider;

trait AutoPilotExecutionFixtures
{
    private function enableSyntheticExecution(array $actions = ['generate_product_image']): void
    {
        config(['agentic.observe' => true, 'agentic.evaluate' => true, 'agentic.provider' => 'codex', 'agentic.debounce_seconds' => 0,
            'agentic.execution.enabled' => true, 'agentic.execution.unlimited' => false, 'agentic.execution.activation_id' => 'synthetic-activation',
            'agentic.execution.activated_at' => now()->subMinute()->format('Y-m-d\TH:i:sP'), 'agentic.execution.actions' => $actions,
            'agentic.execution.all_checkouts' => true, 'agentic.execution.order_ids' => [], 'agentic.execution.daily_limit' => 10]);
        $this->mock(CodexSdkProvider::class)->shouldReceive('health')->andReturn(['ready' => true]);
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function name(): string
            {
                return 'codex';
            }

            public function decide(array $input): array
            {
                $response = parent::decide($input);
                $response['metadata'] = ['provider' => 'codex', 'authentication' => 'chatgpt', 'tools_used' => 0,
                    'isolation_digest' => str_repeat('a', 64), 'synthetic_test' => true];

                return $response;
            }
        });
    }
}
