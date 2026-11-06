<?php

return [
    'enabled' => false,
    'model' => 'gpt-6-luna',
    'daily_budget_micros' => 5000000,
    'turn_reservation_micros' => 100000,
    'max_output_tokens' => 3000,
    'max_tool_calls' => 4,
    'max_tool_bytes' => 12000,
    'max_prompt_bytes' => 32000,
    'timeout_seconds' => 55,
    'per_minute' => 5,
    'session_daily_turns' => 40,
    'queue' => 'storefront-assistant',
    // Standard USD/million tokens, verified from official OpenAI pricing 2026-11-06.
    'input_micros_per_million' => 100000,
    'output_micros_per_million' => 500000,
    'retention_days' => 30,
    'capability_ttl' => 180,
];
