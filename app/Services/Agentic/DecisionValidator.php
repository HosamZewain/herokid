<?php

namespace App\Services\Agentic;

use InvalidArgumentException;

class DecisionValidator
{
    public const TYPES = ['execute', 'wait', 'human_required', 'escalate', 'completed'];
    public const FIELDS = ['decision', 'action', 'target_type', 'target_id', 'reason_code', 'reason', 'confidence', 'context_version', 'requires_human'];

    public function validate(mixed $value): array
    {
        if (! is_array($value) || count($value) !== count(self::FIELDS) || array_diff(self::FIELDS, array_keys($value))
            || ! in_array($value['decision'], self::TYPES, true)
            || ! in_array($value['target_type'], ['checkout', 'production_unit'], true)
            || ! is_string($value['target_id']) || ! preg_match('/\A[a-zA-Z0-9:_-]{1,160}\z/', $value['target_id'])
            || ! is_string($value['reason_code']) || ! preg_match('/\A[A-Z][A-Z0-9_]{0,79}\z/', $value['reason_code'])
            || ! is_string($value['reason']) || mb_strlen($value['reason']) < 1 || mb_strlen($value['reason']) > 1500
            || (! is_int($value['confidence']) && ! is_float($value['confidence'])) || ! is_finite((float) $value['confidence'])
            || $value['confidence'] < 0 || $value['confidence'] > 1 || ! is_int($value['context_version']) || $value['context_version'] < 1
            || ! is_bool($value['requires_human'])
            || $value['requires_human'] !== in_array($value['decision'], ['human_required', 'escalate'], true)
            || ($value['action'] !== null && ! in_array($value['action'], CapabilityRegistry::ACTIONS, true))
            || ($value['decision'] === 'execute' && $value['action'] === null)
            || ($value['decision'] !== 'execute' && $value['action'] !== null)) {
            throw new InvalidArgumentException('invalid_decision_schema');
        }

        return $value;
    }
}
