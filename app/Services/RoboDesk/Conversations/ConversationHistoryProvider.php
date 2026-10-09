<?php

namespace App\Services\RoboDesk\Conversations;

interface ConversationHistoryProvider
{
    public function configured(): bool;

    public function fetch(string $phone, ?string $after = null): ConversationHistoryPage;
}
