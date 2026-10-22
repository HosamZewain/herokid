<?php

return [
    // Deployment never enables billable work. Admin activation is also required.
    'enabled' => (bool) env('ORDER_AUTOMATION_ENABLED', false),
    'queue' => 'order-automation',
    // The template integration contract must be implemented/verified before enabling sending.
    'delivery_ready' => false,
];
