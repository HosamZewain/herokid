<?php

return [
    // Deployment is not activation. A reviewed worker and future-order boundary are required.
    'payment_review_enabled' => (bool) env('ORDER_PROCESS_PAYMENT_REVIEW_ENABLED', false),
    'enabled' => (bool) env('ORDER_PROCESS_ENABLED', false),
    // Trial mode is explicitly pinned to one single-order checkout and one workflow.
    'enrollment_mode' => env('ORDER_PROCESS_ENROLLMENT_MODE', 'all_new'),
    'trial_order_id' => env('ORDER_PROCESS_TRIAL_ORDER_ID'),
    'trial_automation_id' => env('ORDER_PROCESS_TRIAL_AUTOMATION_ID'),
    'after_order_id' => env('ORDER_PROCESS_AFTER_ORDER_ID'),
    'confirmation_owner' => env('ORDER_PROCESS_CONFIRMATION_OWNER', 'legacy'),
    'queue' => 'order-process',
    'identity_enabled' => (bool) env('ORDER_PROCESS_IDENTITY_ENABLED', false),
    'identity_template_ready' => (bool) env('ORDER_PROCESS_IDENTITY_TEMPLATE_READY', false),
    'identity_budget_usd' => (float) env('ORDER_PROCESS_IDENTITY_BUDGET_USD', 0),
];
