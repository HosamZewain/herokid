<?php

namespace App\Services\Agentic;

/** Deliberately contains no execution method, jobs, business locks or adapters. */
final class ExecutionGateway
{
    public function simulate(array $decision, array $policy, array $context): array
    {
        return ['mode' => 'shadow', 'executed' => false, 'reserved' => false,
            'result' => ! $policy['allowed_proposal'] ? 'blocked' : ($decision['decision'] === 'execute' ? 'would_request_executor_preflight' : 'observation'),
            'idempotency_preview' => hash('sha256', json_encode([$decision['action'], $decision['target_id'],
                collect($context['units'])->firstWhere('unit_key', $decision['target_id'])['input_fingerprint'] ?? $context['fingerprint'],
                collect($context['units'])->firstWhere('unit_key', $decision['target_id'])['spec_fingerprint'] ?? null], JSON_THROW_ON_ERROR))];
    }
}
