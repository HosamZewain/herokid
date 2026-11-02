<?php

namespace App\Console\Commands;

use App\Models\CodexSceneRun;
use App\Services\CodexScenes\ManualSceneService;
use Illuminate\Console\Command;

class ResumeCodexQuality extends Command
{
    protected $signature = 'codex-scenes:resume-quality {run} {--execute} {--authorization=} {--skip-content-scene=}';

    protected $description = 'Explicitly resume one reviewed quality failure, preserving accepted scenes; dry-run by default';

    public function handle(ManualSceneService $service): int
    {
        $run = CodexSceneRun::findOrFail($this->argument('run'));
        $this->line(json_encode(['run_id' => $run->id, 'order_id' => $run->order_id, 'status' => $run->status,
            'accepted_scenes' => count($run->scenes ?? []), 'remaining_scenes' => 13 - count($run->scenes ?? [])]));
        if (! $this->option('execute')) {
            $this->info('Read-only plan. Execution requires an explicit authorization reference.');
            return self::SUCCESS;
        }
        $next = $service->resumeQuality($run, (string) $this->option('authorization'), $this->option('skip-content-scene') !== null ? (int) $this->option('skip-content-scene') : null);
        $this->line(json_encode(['resumed_run_id' => $next->id, 'status' => $next->status]));
        return self::SUCCESS;
    }
}
