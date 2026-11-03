<?php

namespace App\Console\Commands;

use App\Services\Agentic\MemoryService;
use Illuminate\Console\Command;

class ImportAutoPilotMemory extends Command
{
    protected $signature = 'autopilot:import-reviewed-memory';

    protected $description = 'Import the owner-reviewed memory locally; production uses the authorized admin action';

    public function handle(MemoryService $memory): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Use the authorized memory-management screen in production.');

            return self::FAILURE;
        }
        $this->info('Imported '.$memory->importOwnerReview().' reviewed memory entries.');

        return self::SUCCESS;
    }
}
