<?php

namespace App\Console\Commands;

use App\Services\CodexScenes\ManualSceneService;
use Illuminate\Console\Command;

class SyncCodexScenes extends Command
{
    protected $signature = 'codex-scenes:sync';

    protected $description = 'Enroll eligible stories and synchronize the bounded Codex scene queue';

    public function handle(ManualSceneService $service): int
    {
        if (config('codex_scenes.enabled')) {
            $service->tick();
        }

        return self::SUCCESS;
    }
}
