<?php

return [
    /*
    | The integration is deliberately fail-closed. Production will not contact
    | RoboDesk until the shared credentials are supplied and enabled explicitly.
    */
    'enabled' => (bool) env('ROBODESK_ENABLED', false),
    'base_url' => rtrim((string) env('ROBODESK_BASE_URL', 'https://herokid.robodesk.ai'), '/'),
    // Phase one cannot activate preview, payment or AI workflows accidentally.
    'confirmation_only' => (bool) env('ROBODESK_CONFIRMATION_ONLY', true),
    'confirmation' => [
        'enabled' => (bool) env('ROBODESK_CONFIRMATION_ENABLED', false),
        // Default to a bounded owner-approved pilot, never all customers by accident.
        'audience' => env('ROBODESK_CONFIRMATION_AUDIENCE', 'trial'),
        'trial_phone_hashes' => array_values(array_filter(explode(',', (string) env('ROBODESK_CONFIRMATION_TRIAL_PHONE_HASHES', '')))),
        // Capture MAX(orders.id), including deleted rows, immediately before activation.
        'after_order_id' => env('ROBODESK_CONFIRMATION_AFTER_ORDER_ID'),
        'template' => 'herokid_order_confirmation',
        // RoboDesk maps ar-EG to Meta ar; sending ar directly falls back to English.
        'language' => 'ar-EG',
        // Enable only after message-id receipt + polling metadata contract is verified.
        'contract_ready' => (bool) env('ROBODESK_CONFIRMATION_CONTRACT_READY', false),
    ],
    'events_path' => '/api/integrations/herokid/v1/events',
    'whatsapp_number' => (string) env('ROBODESK_WHATSAPP_NUMBER', '01501188884'),
    'inbound_secret' => (string) env('ROBODESK_INBOUND_SECRET', ''),
    'outbound_secret' => (string) env('ROBODESK_OUTBOUND_SECRET', ''),
    'timeout_seconds' => (int) env('ROBODESK_TIMEOUT_SECONDS', 15),
    'signature_tolerance_seconds' => (int) env('ROBODESK_SIGNATURE_TOLERANCE_SECONDS', 300),
    'payment_proof_max_mb' => (int) env('ROBODESK_PAYMENT_PROOF_MAX_MB', 10),
    'instapay_url' => env('ROBODESK_INSTAPAY_URL'),
    // Credentials/enabled state live in encrypted admin-managed DB settings.
    'conversations' => [
        'account_key' => (string) env('ROBODESK_CONVERSATIONS_ACCOUNT_KEY', 'primary'),
        'sync_cooldown_seconds' => 12,
        // Scan/dispatch incrementally; never fetch RoboDesk during web requests
        // unless an employee explicitly opens/refreshes a conversation.
        'background_interval_seconds' => 600,
        // Delta cursors cannot see backdated imports or changes to older senders.
        // Reconcile the provider's recent-history window on the existing request,
        // not with an additional call or an unbounded sweep during web requests.
        'full_reconciliation_interval_seconds' => 3600,
        'background_batch_size' => 20,
        'background_scan_size' => 100,
        'background_requests_per_minute' => 20,
    ],
];
