<?php

namespace App\Console\Commands;

use App\Services\Agentic\ContextObserver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ObserveAgenticOrders extends Command
{
    protected $signature = 'agentic:observe';

    protected $description = 'Refresh observation-only agentic snapshots without calling AI or business executors';

    public function handle(ContextObserver $observer): int
    {
        if (! config('agentic.observe')) {
            $this->info('Observation disabled.');

            return self::SUCCESS;
        }
        $count = $observer->observeDirty();
        $last = DB::table('ai_runtime_states')->where('id', 1)->value('reconciled_at');
        if (! $last || Carbon::parse($last)->lte(now()->subMinutes(5))) {
            $count += $observer->reconcile();
        }
        $this->info('Observed checkout groups: '.$count);

        return self::SUCCESS;
    }
}
