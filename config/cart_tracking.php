<?php

return [
    'abandoned_after_minutes' => (int) env('CART_ABANDONED_AFTER_MINUTES', 360),
    'activity_retention_days' => (int) env('CART_ACTIVITY_RETENTION_DAYS', 60),
];
