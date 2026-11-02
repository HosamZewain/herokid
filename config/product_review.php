<?php

return [
    // Explicit activation only after the shared Meta/RoboDesk contract is verified.
    'enabled' => (bool) env('PRODUCT_REVIEW_ENABLED', false),
    'template_ready' => (bool) env('PRODUCT_REVIEW_TEMPLATE_READY', false),
    'book_pages_enabled' => (bool) env('COLORING_BOOK_PAGES_ENABLED', false),
    'cover_size' => '1024x1536',
    'cover_quality' => 'high',
    'cover_budget_usd' => (float) env('COLORING_BOOK_COVER_BUDGET_USD', 0.5),
    'max_pages' => 1000,
];
