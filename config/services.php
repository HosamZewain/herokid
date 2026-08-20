<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'meta_pixel' => [
        'id' => env('META_PIXEL_ID', '1011553001490691'),
        'conversions_api_enabled' => env('META_CONVERSIONS_API_ENABLED', true),
        'access_token' => env('META_CONVERSIONS_API_ACCESS_TOKEN'),
        'api_version' => env('META_GRAPH_API_VERSION', 'v23.0'),
        'test_event_code' => env('META_TEST_EVENT_CODE'),
    ],

    'google_analytics' => [
        'id' => env('GOOGLE_ANALYTICS_ID', 'G-NBQYMB9NT2'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
    ],

    'mobile_otp' => [
        'driver' => env('MOBILE_OTP_DRIVER', 'none'),
    ],

    'mobile_oauth' => [
        'google_client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GOOGLE_MOBILE_CLIENT_IDS', ''))
        ))),
        'apple_client_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('APPLE_MOBILE_CLIENT_IDS', ''))
        ))),
    ],

];
