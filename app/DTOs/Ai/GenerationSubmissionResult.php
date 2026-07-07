<?php

namespace App\DTOs\Ai;

class GenerationSubmissionResult
{
    public function __construct(
        public readonly string $externalRequestId,
        public readonly string $status,
        public readonly ?string $statusUrl = null,
        public readonly ?string $responseUrl = null,
        public readonly array $raw = [],
    ) {}
}
