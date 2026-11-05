<?php

return [
    // Separate read-only conversation runtime. Never point this at the decision socket.
    'enabled' => (bool) env('ADMIN_ASSISTANT_ENABLED', false),
    'bridge_socket' => env('ADMIN_ASSISTANT_BRIDGE_SOCKET', '/run/herokid-assistant/decision.sock'),
    'timeout_seconds' => 30,
    'per_user_per_minute' => 6,
];
