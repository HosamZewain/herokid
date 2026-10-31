<?php

return [
    // Deployment is not activation. A reviewed worker and future-order boundary are required.
    'enabled' => (bool) env('ORDER_PROCESS_ENABLED', false),
    'after_order_id' => env('ORDER_PROCESS_AFTER_ORDER_ID'),
    'confirmation_owner' => env('ORDER_PROCESS_CONFIRMATION_OWNER', 'legacy'),
    'queue' => 'order-process',
    'identity_enabled' => (bool) env('ORDER_PROCESS_IDENTITY_ENABLED', false),
    'identity_template_ready' => (bool) env('ORDER_PROCESS_IDENTITY_TEMPLATE_READY', false),
    'identity_budget_usd' => (float) env('ORDER_PROCESS_IDENTITY_BUDGET_USD', 0),
];
