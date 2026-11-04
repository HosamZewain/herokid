<?php

namespace App\Services\Agentic\Providers;

/** Safe transport codes only. No provider payloads or customer text. */
final class TransientDecisionFailure extends \RuntimeException
{
    public const CODES = ['DECISION_BRIDGE_UNAVAILABLE', 'DECISION_TRANSPORT_TIMEOUT'];

    public function __construct(string $code)
    {
        if (! in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException('unsupported_transient_decision_code');
        }
        parent::__construct($code);
    }
}
