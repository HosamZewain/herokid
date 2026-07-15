<?php

namespace App\DTOs\Notifications;

class NotificationMessage
{
    public function __construct(
        public string $eventKey,
        public string $channelType,
        public string $recipient,
        public string $body,
        public string $severity = 'info',
        public ?string $subject = null,
        public ?string $notifiableType = null,
        public ?int $notifiableId = null,
        public array $payload = [],
        public ?string $parseMode = null,
    ) {}
}
