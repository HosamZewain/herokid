<?php

return [
    // These switches enable observation/evaluation only. There is no execute mode.
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
];
