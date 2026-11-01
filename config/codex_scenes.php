<?php

return [
    'enabled' => (bool) env('CODEX_SCENES_ENABLED', false),
    'automatic' => (bool) env('CODEX_SCENES_AUTOMATIC', false),
    'concurrency' => 2,
    'spool' => env('CODEX_SCENES_SPOOL', '/var/lib/herokid-codex-spool'),
    'scene_count' => 13,
];
