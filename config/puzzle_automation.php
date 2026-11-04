<?php

return [
    'enabled' => (bool) env('PUZZLE_AUTOMATION_ENABLED', false),
    // A deployment-time event boundary; never backfill already confirmed orders.
    'after_event_id' => env('PUZZLE_AUTOMATION_AFTER_EVENT_ID'),
    'product_slugs' => ['personalized-child-adventure-hdf-puzzle', 'personalized-child-family-photo-mug', 'bostr-mkhss-bsor-altfl-mkas-a4'],
    'model_code' => 'gpt-image-2',
    'size' => '1536x1024',
    // Portrait artwork; purchased print dimensions remain in the item prompt.
    'product_sizes' => ['bostr-mkhss-bsor-altfl-mkas-a4' => '1024x1536'],
    'quality' => 'high',
    'budget_usd' => 0.50,
];
