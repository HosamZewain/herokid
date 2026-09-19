<?php

return [
    /*
    | Persistent private media. On AWS this should be the private-prefixed
    | scoped S3 disk. The local default keeps shared-hosting installations
    | backward compatible.
    */
    'private_disk' => env('PRIVATE_MEDIA_DISK', 'local'),

    /*
    | Persistent public media. Keep this on the local public disk until a
    | public delivery layer such as CloudFront is configured.
    */
    'public_disk' => env('PUBLIC_MEDIA_DISK', 'public'),

    /*
    | Working files that require a real operating-system path. This disk must
    | use Laravel's local driver and must never be configured as S3.
    */
    'processing_disk' => env('PROCESSING_DISK', 'local'),
];
