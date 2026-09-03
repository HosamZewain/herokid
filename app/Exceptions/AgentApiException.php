<?php

namespace App\Exceptions;

use RuntimeException;

class AgentApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function payload(): array
    {
        return array_filter([
            'success' => false,
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
            'details' => $this->details ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
