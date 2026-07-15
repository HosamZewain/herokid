<?php

return [
    'channels' => [
        'telegram' => [
            'type' => 'telegram',
            'display_name' => 'Telegram',
            'credential_type' => 'bot_token',
        ],
    ],

    'events' => [
        'order.created' => [
            'group' => 'orders',
            'name_ar' => 'طلب جديد',
            'name_en' => 'New order received',
            'severity' => 'info',
            'default_enabled' => true,
        ],
        'production.project.created' => [
            'group' => 'production',
            'name_ar' => 'تم إرسال طلب إلى الاستوديو',
            'name_en' => 'Production Studio project created',
            'severity' => 'info',
            'default_enabled' => true,
        ],
        'production.project.started' => [
            'group' => 'production',
            'name_ar' => 'بدأ مشروع استوديو الإنتاج',
            'name_en' => 'Production Studio project started',
            'severity' => 'info',
            'default_enabled' => false,
        ],
        'production.project.completed' => [
            'group' => 'production',
            'name_ar' => 'اكتمل مشروع استوديو الإنتاج',
            'name_en' => 'Production Studio project completed',
            'severity' => 'success',
            'default_enabled' => true,
        ],
        'production.project.stuck' => [
            'group' => 'production',
            'name_ar' => 'مشروع استوديو الإنتاج متوقف',
            'name_en' => 'Production Studio project stuck',
            'severity' => 'warning',
            'default_enabled' => true,
        ],
        'production.project.budget_exceeded' => [
            'group' => 'production',
            'name_ar' => 'تجاوزت ميزانية الإنتاج',
            'name_en' => 'Production Studio budget exceeded',
            'severity' => 'critical',
            'default_enabled' => true,
        ],
        'ai.generation.completed' => [
            'group' => 'ai',
            'name_ar' => 'اكتملت مهمة توليد AI',
            'name_en' => 'AI generation completed',
            'severity' => 'success',
            'default_enabled' => false,
        ],
        'ai.generation.failed' => [
            'group' => 'ai',
            'name_ar' => 'فشلت مهمة توليد AI',
            'name_en' => 'AI generation failed',
            'severity' => 'error',
            'default_enabled' => true,
        ],
        'ai.generation.stuck' => [
            'group' => 'ai',
            'name_ar' => 'مهمة توليد AI متوقفة',
            'name_en' => 'AI generation stuck',
            'severity' => 'warning',
            'default_enabled' => true,
        ],
        'ai.generation.budget_exceeded' => [
            'group' => 'ai',
            'name_ar' => 'تجاوزت تكلفة مهمة AI الحد المحدد',
            'name_en' => 'AI generation cost exceeded threshold',
            'severity' => 'critical',
            'default_enabled' => true,
        ],
    ],

    'settings' => [
        'notification_production_stuck_after_minutes' => '120',
        'notification_ai_job_stuck_after_minutes' => '20',
        'notification_repeat_stuck_alert_after_minutes' => '180',
        'notification_production_default_ai_budget_usd' => '2.00',
        'notification_ai_job_warning_cost_usd' => '0.20',
        'notification_ai_project_warning_cost_usd' => '2.00',
        'notification_notify_on_budget_80_percent' => '1',
        'notification_last_stuck_check_run_at' => '',
    ],

    'telegram' => [
        'api_base_url' => env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'),
        'legacy_token' => env('TELEGRAM_BOT_TOKEN'),
        'legacy_default_chat_id' => env('TELEGRAM_DEFAULT_CHAT_ID'),
        'timeout_seconds' => (int) env('TELEGRAM_REQUEST_TIMEOUT', 10),
    ],
];
