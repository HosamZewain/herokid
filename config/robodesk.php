<?php

return [
    /*
    | The integration is deliberately fail-closed. Production will not contact
    | RoboDesk until the shared credentials are supplied and enabled explicitly.
    */
    'enabled' => (bool) env('ROBODESK_ENABLED', false),
    'base_url' => rtrim((string) env('ROBODESK_BASE_URL', 'https://herokid.robodesk.ai'), '/'),
    'events_path' => '/api/integrations/herokid/v1/events',
    'whatsapp_number' => (string) env('ROBODESK_WHATSAPP_NUMBER', '01501188884'),
    'inbound_secret' => (string) env('ROBODESK_INBOUND_SECRET', ''),
    'outbound_secret' => (string) env('ROBODESK_OUTBOUND_SECRET', ''),
    'timeout_seconds' => (int) env('ROBODESK_TIMEOUT_SECONDS', 15),
    'signature_tolerance_seconds' => (int) env('ROBODESK_SIGNATURE_TOLERANCE_SECONDS', 300),
    'payment_proof_max_mb' => (int) env('ROBODESK_PAYMENT_PROOF_MAX_MB', 10),
    'instapay_url' => env('ROBODESK_INSTAPAY_URL'),
];
