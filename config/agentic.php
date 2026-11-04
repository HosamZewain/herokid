<?php

return [
    'name' => 'HeroKid Auto Pilot',
    'memory_limit' => 30,
    // Observation and decision evaluation retain their Shadow semantics.
    'observe' => (bool) env('AGENTIC_OBSERVE', false),
    'evaluate' => (bool) env('AGENTIC_EVALUATE', false),
    'provider' => env('AGENTIC_PROVIDER', 'codex'),
    'daily_limit' => (int) env('AGENTIC_DAILY_LIMIT', 100),
    'cycles_per_pass' => (int) env('AGENTIC_CYCLES_PER_PASS', 3),
    'debounce_seconds' => 30,
    'reconcile_batch' => 50,
    'timeout_seconds' => 90,
    'max_context_bytes' => 196608,
    'bridge_socket' => env('AGENTIC_BRIDGE_SOCKET', '/run/herokid-agentic/decision.sock'),
    'mcp_enabled' => (bool) env('AGENTIC_MCP_ENABLED', false),
    // A separately approved production release must supply every admission bound.
    // These are production starts, NOT Codex decisions or paid-provider retries.
    'execution' => [
        'enabled' => (bool) env('AUTOPILOT_EXECUTION_ENABLED', false),
        'activation_id' => env('AUTOPILOT_EXECUTION_ACTIVATION'),
        'activated_at' => env('AUTOPILOT_EXECUTION_ACTIVATED_AT'),
        'actions' => array_values(array_filter(explode(',', (string) env('AUTOPILOT_EXECUTION_ACTIONS', '')))),
        'order_ids' => array_values(array_filter(explode(',', (string) env('AUTOPILOT_EXECUTION_ORDER_IDS', '')))),
        'all_checkouts' => (bool) env('AUTOPILOT_EXECUTION_ALL_CHECKOUTS', false),
        // Explicit opt-in; a missing/zero cap alone never grants unlimited execution.
        'unlimited' => env('AUTOPILOT_EXECUTION_UNLIMITED', false),
        'daily_limit' => (int) env('AUTOPILOT_EXECUTION_DAILY_LIMIT', 0),
        'batch_size' => 3,
        'decision_max_age_seconds' => 900,
    ],
];
