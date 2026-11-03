<?php

namespace App\Services\Agentic\Providers;

interface DecisionProvider
{
    public function name(): string;

    public function health(): array;

    /** Returns ['decision' => structured JSON, 'metadata' => allowlisted runtime facts]. */
    public function decide(array $input): array;
}
