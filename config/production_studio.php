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
];
