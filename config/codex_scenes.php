<?php

return [
    'enabled' => (bool) env('CODEX_SCENES_ENABLED', false),
    'spool' => env('CODEX_SCENES_SPOOL', '/var/lib/herokid-codex-spool'),
    'scene_count' => 13,
];
