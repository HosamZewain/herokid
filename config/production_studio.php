<?php

return [
    'enabled' => env('HERO_KID_PRODUCTION_STUDIO_ENABLED', true),

    'automation' => [
        'enabled' => env('HERO_KID_PRODUCTION_STUDIO_AUTOMATION_ENABLED', false),
        'scene_concurrency' => max(1, (int) env('HERO_KID_AUTOMATION_SCENE_CONCURRENCY', 2)),
        'validation_policy_version' => env('HERO_KID_AUTOMATION_VALIDATION_POLICY_VERSION', 'identity-v1'),
        'fingerprint_version' => env('HERO_KID_AUTOMATION_FINGERPRINT_VERSION', 'automation-fingerprint-v1'),
        'prompt_template_version' => env('HERO_KID_AUTOMATION_PROMPT_TEMPLATE_VERSION', 'production-prompt-v1'),
        'layout_template_version' => env('HERO_KID_AUTOMATION_LAYOUT_TEMPLATE_VERSION', 'layout-print-v1'),
        'max_retries' => max(0, (int) env('HERO_KID_AUTOMATION_MAX_RETRIES', 2)),
        'identity_pass_threshold' => (int) env('HERO_KID_AUTOMATION_IDENTITY_PASS_THRESHOLD', 85),
        'scene_adherence_threshold' => (int) env('HERO_KID_AUTOMATION_SCENE_ADHERENCE_THRESHOLD', 80),
        'default_style_preset' => env('HERO_KID_AUTOMATION_DEFAULT_STYLE', 'premium_storybook'),
        'default_generation_quality' => env('HERO_KID_AUTOMATION_DEFAULT_QUALITY', 'high'),
        'phase2' => [
            'story_attempt_limit' => max(1, (int) env('HERO_KID_AUTOMATION_STORY_ATTEMPTS', 1)),
            'character_profile_attempt_limit' => max(1, (int) env('HERO_KID_AUTOMATION_PROFILE_ATTEMPTS', 1)),
            'child_reference_attempt_limit' => max(1, (int) env('HERO_KID_AUTOMATION_CHILD_REFERENCE_ATTEMPTS', 3)),
            'story_text_cost_fallback' => env('HERO_KID_AUTOMATION_STORY_TEXT_COST', '0.0100'),
            'vision_analysis_cost_fallback' => env('HERO_KID_AUTOMATION_VISION_ANALYSIS_COST', '0.0100'),
            'identity_validation_cost_fallback' => env('HERO_KID_AUTOMATION_IDENTITY_VALIDATION_COST', '0.0100'),
        ],
        'phase3' => [
            'cover_attempt_limit' => max(1, (int) env('HERO_KID_AUTOMATION_COVER_ATTEMPTS', 3)),
            'scene_attempt_limit' => max(1, (int) env('HERO_KID_AUTOMATION_SCENE_ATTEMPTS', 3)),
            'cover_story_relevance_threshold' => (int) env('HERO_KID_AUTOMATION_COVER_RELEVANCE_THRESHOLD', 80),
            'scene_preparation_cost_fallback' => env('HERO_KID_AUTOMATION_SCENE_PREP_COST', '0.0100'),
            'cover_validation_cost_fallback' => env('HERO_KID_AUTOMATION_COVER_VALIDATION_COST', '0.0100'),
            'scene_validation_cost_fallback' => env('HERO_KID_AUTOMATION_SCENE_VALIDATION_COST', '0.0100'),
        ],
        'phase4' => [
            'layout_job_stale_minutes' => (int) env('HERO_KID_AUTOMATION_LAYOUT_STALE_MINUTES', 15),
            'min_effective_dpi' => (int) env('HERO_KID_AUTOMATION_MIN_EFFECTIVE_DPI', 180),
            'dpi_policy' => env('HERO_KID_AUTOMATION_DPI_POLICY', 'warn'),
        ],
        'queue' => [
            'connection' => env('QUEUE_CONNECTION', 'database'),
            'name' => env('HERO_KID_AUTOMATION_QUEUE', 'default'),
            'worker_timeout' => (int) env('HERO_KID_AUTOMATION_WORKER_TIMEOUT', 300),
            'job_timeout' => (int) env('HERO_KID_AUTOMATION_JOB_TIMEOUT', 300),
            'tries' => (int) env('HERO_KID_AUTOMATION_JOB_TRIES', 1),
            'backoff' => array_values(array_filter(array_map(
                'intval',
                explode(',', env('HERO_KID_AUTOMATION_JOB_BACKOFF', '30,90,180'))
            ))),
            'max_exceptions' => (int) env('HERO_KID_AUTOMATION_MAX_EXCEPTIONS', 1),
            'heartbeat_stale_minutes' => (int) env('HERO_KID_AUTOMATION_STALE_MINUTES', 15),
            'recovery_limit' => (int) env('HERO_KID_AUTOMATION_RECOVERY_LIMIT', 20),
        ],
    ],

    'statuses' => [
        'draft' => 'مسودة',
        'in_progress' => 'قيد التنفيذ',
        'waiting_review' => 'بانتظار المراجعة',
        'approved' => 'معتمد',
        'ready_for_print' => 'جاهز للطباعة',
        'completed' => 'مكتمل',
        'archived' => 'مؤرشف',
        'cancelled' => 'ملغي',
    ],

    'stages' => [
        'intake' => 'استلام ومراجعة البيانات',
        'story_review' => 'مراجعة القصة',
        'character_profile' => 'ملف الشخصية',
        'scene_preparation' => 'تحضير المشاهد',
        'image_generation' => 'إنتاج الصور',
        'image_review' => 'مراجعة الصور',
        'layout' => 'التصميم والإخراج',
        'quality_check' => 'مراجعة الجودة',
        'print_ready' => 'جاهز للطباعة',
    ],

    'ai' => [
        'fal' => [
            'enabled' => env('FAL_ENABLED', false),
            'key' => env('FAL_KEY'),
            'default_model' => env('FAL_DEFAULT_MODEL', 'fal-ai/flux-kontext/dev'),
            'default_premium_model' => env('FAL_DEFAULT_PREMIUM_MODEL', 'fal-ai/flux-pro/kontext'),
            'request_timeout' => (int) env('FAL_REQUEST_TIMEOUT', 180),
            'max_retries' => (int) env('FAL_MAX_RETRIES', 2),
        ],

        'style_presets' => [
            'premium_storybook' => 'Premium colorful magical children book illustration, polished, warm lighting, expressive but gentle.',
            'soft_watercolor' => 'Soft watercolor-inspired children book art, warm palette, gentle details, clean readable composition.',
            'cinematic_3d' => 'Premium cinematic 3D storybook illustration, soft lighting, friendly proportions, high detail.',
        ],

        'costs' => [
            'fal-ai/flux-kontext/dev' => '0.0300',
            'fal-ai/flux-pro/kontext' => '0.0800',
        ],
    ],
];
