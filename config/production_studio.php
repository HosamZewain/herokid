<?php

return [
    'enabled' => env('HERO_KID_PRODUCTION_STUDIO_ENABLED', true),

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
