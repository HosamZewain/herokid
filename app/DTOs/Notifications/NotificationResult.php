<?php

namespace App\DTOs\Notifications;

class NotificationResult
{
    public function __construct(
        public bool $successful,
        public string $status,
        public ?string $errorMessage = null,
        public array $response = [],
    ) {}

    public static function success(array $response = []): self
    {
        return new self(true, 'sent', null, $response);
    }

    public static function failure(string $errorMessage, array $response = []): self
    {
        return new self(false, 'failed', $errorMessage, $response);
    }
}
