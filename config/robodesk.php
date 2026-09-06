<?php

return [
    /*
    | The integration is deliberately fail-closed. Production will not contact
    | RoboDesk until the shared credentials are supplied and enabled explicitly.
    |
    | Everything here is a DEFAULT. Admin-managed values in `settings`,
    | `robodesk_credentials`, and `robodesk_action_settings` take precedence.
    | See App\Services\RoboDesk\RoboDeskSettings.
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

    /*
    | Connection settings editable from Admin > التكاملات > RoboDesk. A missing
    | or empty `settings` row falls back to the legacy key named in
    | `setting_fallbacks`, then to the default below. Nothing is auto-seeded, so
    | env-driven deployments keep behaving exactly as they do today.
    */
    'settings' => [
        // RoboDesk authenticates with a single static token. The scheme is blank
        // by default, so the header goes out as `Authorization: <token>` rather
        // than `Bearer <token>`; set it if that ever changes.
        'robodesk_auth_header' => 'Authorization',
        'robodesk_auth_scheme' => '',
        'robodesk_default_channel' => '',
        'robodesk_default_language' => 'ar',

        // HMAC signing is off: the agreed contract is token-only in both
        // directions. Turning it on requires the matching secret to be saved.
        'robodesk_sign_outbound' => '0',
        'robodesk_inbound_auth_mode' => 'token',
        'robodesk_inbound_auth_header' => 'X-RoboDesk-Token',

        // Simulation mode keeps every outbound call inside HeroKid so the whole
        // customer journey can be walked through before RoboDesk exists.
        'robodesk_simulation_mode' => '0',

        'robodesk_gate_order_confirmation' => '0',
        'robodesk_gate_identity_confirmation' => '0',
    ],

    'setting_fallbacks' => [
        'robodesk_enabled' => 'robodesk.enabled',
        'robodesk_base_url' => 'robodesk.base_url',
        'robodesk_events_path' => 'robodesk.events_path',
        'robodesk_whatsapp_number' => 'robodesk.whatsapp_number',
        'robodesk_instapay_url' => 'robodesk.instapay_url',
        'robodesk_timeout_seconds' => 'robodesk.timeout_seconds',
        'robodesk_signature_tolerance_seconds' => 'robodesk.signature_tolerance_seconds',
        'robodesk_payment_proof_max_mb' => 'robodesk.payment_proof_max_mb',
    ],

    /*
    | Encrypted secrets held in `robodesk_credentials`. Each falls back to its
    | legacy env value until an admin saves one.
    */
    'credentials' => [
        'auth_token' => [
            'name_ar' => 'توكن الوصول إلى RoboDesk',
            'name_en' => 'RoboDesk API token',
            'legacy_config' => null,
        ],
        'outbound_secret' => [
            'name_ar' => 'مفتاح توقيع الأحداث الصادرة',
            'name_en' => 'Outbound signing secret',
            'legacy_config' => 'robodesk.outbound_secret',
        ],
        'inbound_secret' => [
            'name_ar' => 'مفتاح التحقق من الأحداث الواردة',
            'name_en' => 'Inbound verification secret',
            'legacy_config' => 'robodesk.inbound_secret',
        ],
    ],
];
