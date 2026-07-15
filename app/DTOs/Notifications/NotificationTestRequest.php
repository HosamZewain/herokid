<?php

namespace App\DTOs\Notifications;

class NotificationTestRequest
{
    public function __construct(
        public string $recipient,
        public string $message,
        public ?string $channelType = 'telegram',
    ) {}
}
