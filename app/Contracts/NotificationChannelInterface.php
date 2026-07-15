<?php

namespace App\Contracts;

use App\DTOs\Notifications\NotificationMessage;
use App\DTOs\Notifications\NotificationResult;
use App\DTOs\Notifications\NotificationTestRequest;

interface NotificationChannelInterface
{
    public function isAvailable(): bool;

    public function send(NotificationMessage $message): NotificationResult;

    public function test(NotificationTestRequest $request): NotificationResult;
}
