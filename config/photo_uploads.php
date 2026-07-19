<?php

return [
    'max_files' => (int) env('PHOTO_UPLOAD_MAX_FILES', 5),
    'admin_max_files' => (int) env('ADMIN_ORDER_PHOTO_MAX_FILES', 10),
    'max_size_mb' => (int) env('PHOTO_UPLOAD_MAX_SIZE_MB', 15),
    'concurrency' => (int) env('PHOTO_UPLOAD_CONCURRENCY', 2),
    'temp_retention_hours' => (int) env('PHOTO_UPLOAD_TEMP_RETENTION_HOURS', 24),
    'max_long_edge' => (int) env('PHOTO_UPLOAD_MAX_LONG_EDGE', 2560),
    'jpeg_quality' => (int) env('PHOTO_UPLOAD_JPEG_QUALITY', 90),
    'disk' => env('PHOTO_UPLOAD_DISK', 'local'),
    'allowed_mimes' => [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
    ],
];
