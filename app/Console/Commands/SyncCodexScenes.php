<?php

namespace App\Console\Commands;

use App\Services\CodexScenes\ManualSceneService;
use Illuminate\Console\Command;

class SyncCodexScenes extends Command
{
    protected $signature = 'codex-scenes:sync';

    protected $description = 'Stage and import only explicitly requested manual Codex scene trials';

    public function handle(ManualSceneService $service): int
    {
        if (config('codex_scenes.enabled')) {
            $service->tick();
        }

        return self::SUCCESS;
    }
}
