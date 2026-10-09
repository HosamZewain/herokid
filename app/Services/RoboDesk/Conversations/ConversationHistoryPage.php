<?php

namespace App\Services\RoboDesk\Conversations;

final class ConversationHistoryPage
{
    public function __construct(
        public readonly string $phone,
        public readonly array $messages,
        public readonly bool $cursorReset = false,
    ) {}
}
