<?php

namespace App\DTOs\Ai;

class GenerationStatusResult
{
    public function __construct(
        public readonly string $status,
        public readonly array $raw = [],
        public readonly ?string $imageUrl = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $actualCost = null,
    ) {}

    public function isCompleted(): bool
    {
        return in_array($this->status, ['COMPLETED', 'completed'], true);
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['FAILED', 'ERROR', 'failed'], true);
    }
}
