<?php

return [
    'disk' => env('BOOKLET_PREVIEW_DISK', 'local'),
    'max_upload_mb' => (int) env('BOOKLET_PREVIEW_MAX_MB', 50),
    'max_pages' => (int) env('BOOKLET_PREVIEW_MAX_PAGES', 100),
    'media_grant_minutes' => (int) env('BOOKLET_PREVIEW_GRANT_MINUTES', 30),
];
