<?php

namespace App\Services\RoboDesk\Conversations;

use RuntimeException;

class ConversationReplyException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
