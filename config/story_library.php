<?php

return [
    'disk' => env('STORY_LIBRARY_DISK', env('PRIVATE_MEDIA_DISK', 'local')),
    'max_pdf_mb' => 50,
    'max_pages' => 200,
];
