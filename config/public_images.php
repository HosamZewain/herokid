<?php

$publicImageQueueConnection = (string) env('PUBLIC_IMAGES_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database'));

return [
    'widths' => [320, 640, 960, 1440, 1920],
    'quality' => 82,
    'max_pixels' => 20000000,
    'max_bytes' => 10 * 1024 * 1024,
    // Never process order media, child photos, previews, PDFs or private disks.
    'catalog_prefixes' => ['stories/', 'store/products/', 'store/variants/', 'packages/', 'settings/'],
    // Even a sync-configured web request must not resize images inline.
    'queue_connection' => in_array($publicImageQueueConnection, ['', 'sync'], true) ? 'database' : $publicImageQueueConnection,
];
