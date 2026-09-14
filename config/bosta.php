<?php

return [
    'enabled' => env('BOSTA_ENABLED', false),
    'base_url' => env('BOSTA_BASE_URL', 'https://app.bosta.co/api/v2'),
    'api_key' => env('BOSTA_API_KEY'),
    'business_location_id' => env('BOSTA_BUSINESS_LOCATION_ID'),
    'country_id' => env('BOSTA_COUNTRY_ID', '60e4482c7cb7d4bc4849c4d5'),
    'default_package_type' => env('BOSTA_DEFAULT_PACKAGE_TYPE', 'Small'),
    'allow_open_package' => env('BOSTA_ALLOW_OPEN_PACKAGE', false),
    'webhook_header' => env('BOSTA_WEBHOOK_HEADER', 'X-Bosta-Webhook-Secret'),
    'webhook_secret' => env('BOSTA_WEBHOOK_SECRET'),
    'timeout' => (int) env('BOSTA_TIMEOUT', 30),
    'connect_timeout' => (int) env('BOSTA_CONNECT_TIMEOUT', 10),
    'retries' => (int) env('BOSTA_RETRIES', 2),
    'pickup_sync_enabled' => env('BOSTA_PICKUP_SYNC_ENABLED', true),
    'pickup_sync_interval_minutes' => (int) env('BOSTA_PICKUP_SYNC_INTERVAL_MINUTES', 5),
    'pickup_sync_pages' => (int) env('BOSTA_PICKUP_SYNC_PAGES', 5),
];
