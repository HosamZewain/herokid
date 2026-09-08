<?php

return [
    /*
    | Fail-closed by default. Nothing is sent until the integration is enabled
    | and its API URL, token and payload are filled in from
    | Admin > التكاملات > RoboDesk.
    |
    | Values here are DEFAULTS only; admin-saved rows in `settings`,
    | `robodesk_integration_settings` win.
    */
    'enabled' => (bool) env('ROBODESK_ENABLED', false),
    'whatsapp_number' => (string) env('ROBODESK_WHATSAPP_NUMBER', '01501188884'),
    'timeout_seconds' => (int) env('ROBODESK_TIMEOUT_SECONDS', 15),
    'payment_proof_max_mb' => (int) env('ROBODESK_PAYMENT_PROOF_MAX_MB', 10),
    'instapay_url' => env('ROBODESK_INSTAPAY_URL'),

    'settings' => [
        'robodesk_inbound_auth_header' => 'X-RoboDesk-Token',
        'robodesk_simulation_mode' => '0',

        // Journey switches. These are business rules, not integration config,
        // so they live on the general tab rather than on an integration screen.
        'robodesk_gate_order_confirmation' => '0',
        'robodesk_gate_identity_confirmation' => '0',
    ],

    'setting_fallbacks' => [
        'robodesk_enabled' => 'robodesk.enabled',
        'robodesk_whatsapp_number' => 'robodesk.whatsapp_number',
        'robodesk_instapay_url' => 'robodesk.instapay_url',
        'robodesk_timeout_seconds' => 'robodesk.timeout_seconds',
        'robodesk_payment_proof_max_mb' => 'robodesk.payment_proof_max_mb',
    ],

    /*
    | Journey behaviour that is not integration config. Fixed defaults rather
    | than form fields; promote one to a setting if it ever needs tuning.
    */
    'journey' => [
        'identity_max_revisions' => 3,
        'identity_comment_prompt_prefix' => 'Apply the following parent feedback while keeping the child recognizable:',
    ],

    /*
    | One entry per integration. Each is configured with exactly three fields —
    | API URL, token, JSON payload — and exposes the variables its payload may
    | reference. Adding the next one (identity confirmation, item confirmation,
    | CSAT…) is a new entry here plus a trigger; no new classes, no new UI.
    */
    'integrations' => [
        'order.confirm' => [
            'name_ar' => 'تأكيد الطلب',
            'name_en' => 'Order confirmation',
            'description_ar' => 'يُستدعى فور إنشاء الطلب من المتجر أو التطبيق، لإرسال تفاصيله للعميل على واتساب.',
            'trigger_ar' => 'عند إنشاء طلب جديد',

            // What RoboDesk sends back for this flow, shown on the screen so
            // the contract is documented where it is configured.
            'inbound' => [
                'events' => [
                    'order.confirmed' => 'العميل أكد الطلب — ينتقل الطلب من «بانتظار التأكيد» إلى «طلب جديد»',
                    'order.rejected' => 'العميل رفض الطلب — تُلغى كل طلبات العملية',
                ],
                'example' => [
                    'id' => '8f14e45f-ceea-4a7b-9e8f-2c2d3f6b1a90',
                    'type' => 'order.confirmed',
                    'data' => [
                        'checkout_reference' => 'CHK-20260907-A7X2QP',
                        'comment' => 'تم التأكيد من العميل',
                        'contact_id' => 'rd-contact-123',
                        'conversation_id' => 'rd-conv-456',
                        'message_id' => 'rd-msg-789',
                    ],
                ],
            ],
            'variables' => [
                'checkout_reference' => 'رقم عملية الشراء',
                'short_reference' => 'الرقم المختصر',
                'order_number' => 'رقم الطلب',
                'order_numbers' => 'كل أرقام الطلبات في العملية',
                'customer_name' => 'اسم ولي الأمر',
                'customer_phone' => 'رقم الواتساب',
                'children' => 'أسماء الأطفال',
                'items_summary' => 'ملخص العناصر',
                'items_total' => 'إجمالي العناصر',
                'delivery_fee' => 'مصاريف التوصيل',
                'discount' => 'الخصم',
                'total' => 'الإجمالي',
                'currency' => 'العملة',
                'delivery_address' => 'عنوان الشحن كاملًا',
                'delivery_country' => 'الدولة',
                'delivery_governorate' => 'المحافظة',
                'delivery_city' => 'المدينة',
                'delivery_street' => 'الشارع',
                'customer_notes' => 'ملاحظات العميل',
                'order_status' => 'حالة الطلب',
                'payment_status' => 'حالة الدفع',
            ],
        ],
    ],
];
