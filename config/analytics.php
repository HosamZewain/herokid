<?php

return [
    'ga4' => [
        'property_id' => env('GA4_PROPERTY_ID'),
        'credentials_path' => env('GOOGLE_ANALYTICS_CREDENTIALS_PATH'),
        'credentials_base64' => env('GOOGLE_ANALYTICS_CREDENTIALS_BASE64'),
        'api_base_url' => env('GA4_DATA_API_BASE_URL', 'https://analyticsdata.googleapis.com/v1beta'),
        'token_url' => env('GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'request_timeout' => (int) env('ANALYTICS_REQUEST_TIMEOUT', 10),
        'cache_ttl' => (int) env('ANALYTICS_CACHE_TTL', 900),
        'breakdown_cache_ttl' => (int) env('ANALYTICS_BREAKDOWN_CACHE_TTL', 1800),
        'realtime_cache_ttl' => (int) env('ANALYTICS_REALTIME_CACHE_TTL', 60),
        'timezone' => env('ANALYTICS_TIMEZONE', env('APP_TIMEZONE', 'Africa/Cairo')),
    ],
];
