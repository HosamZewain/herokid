<?php

namespace App\Console\Commands;

use App\Models\CodexSceneRun;
use App\Services\CodexScenes\LocalCleanupPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PlanCodexLocalCleanup extends Command
{
    protected $signature = 'codex-scenes:local-cleanup-plan {--limit=5} {--hold-dispatch-lock}';

    protected $description = 'Read-only verification of completed Codex outputs on S3 before local copy cleanup';

    public function handle(LocalCleanupPlan $plans): int
    {
        $plan = $plans->build((int) $this->option('limit'));
        if (! $this->option('hold-dispatch-lock')) {
            $this->line(json_encode($plan, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        // S3 reads finished before this short fence. Existing generation continues;
        // no new dispatch can race the shared-cache idle check during OS cleanup.
        $lock = Cache::lock('codex-scenes-sync', 180);
        if (! $lock->get()) {
            return self::FAILURE;
        }
        try {
            $plan['runs'] = array_values(array_filter($plan['runs'], fn ($entry) => $plans->unchanged($entry)));
            $plan['generated_at'] = now()->timestamp;
            $plan['allow_generated_cache'] = ! CodexSceneRun::where('active_slot', 1)->whereNotIn('status', ['waiting_slot', 'quality_review'])->exists();
            $this->line(json_encode($plan, JSON_THROW_ON_ERROR));
            fflush(STDOUT);
            stream_set_timeout(STDIN, 90);

            return trim((string) fgets(STDIN)) === 'release' ? self::SUCCESS : self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
