<?php

namespace App\DTOs\Ai;

class StructuredAiResult
{
    public function __construct(
        public readonly array $data,
        public readonly array $metadata = [],
        public readonly array $usage = [],
        public readonly ?string $prompt = null,
        public readonly ?string $actualCost = null,
        public readonly ?string $costSource = 'estimated',
    ) {}
}
