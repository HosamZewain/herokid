<?php

namespace App\Services\Agentic;

class PolicyEngine
{
    public function __construct(private CapabilityRegistry $registry) {}

    public function evaluate(array $decision, array $context, array $remaining): array
    {
        $reason = null;
        $target = collect($this->registry->targets($context))->firstWhere('target_id', $decision['target_id']);
        if ($decision['context_version'] !== $context['context_version']) {
            $reason = 'STALE_CONTEXT';
        } elseif (! $target || ! in_array($decision['target_id'], $remaining, true) || $target['target_type'] !== $decision['target_type']) {
            $reason = 'INVALID_OR_COVERED_TARGET';
        } elseif ($decision['decision'] === 'wait' && $target['decision'] !== 'wait') {
            $reason = 'FALSE_WAITING';
        } elseif ($decision['decision'] === 'execute' && ($target['decision'] !== 'execute' || $target['action'] !== $decision['action'])) {
            $reason = $target['reason_code'];
        } elseif ($decision['decision'] === 'completed' && $target['decision'] !== 'completed') {
            $reason = 'INCOMPLETE_TARGET';
        }

        return ['version' => CapabilityRegistry::VERSION, 'allowed_proposal' => $reason === null,
            'reason_code' => $reason ?? 'VALID_SHADOW_PROPOSAL', 'execution_allowed' => false,
            'requires_executor_preflight' => $reason === null && $decision['decision'] === 'execute'];
    }
}
