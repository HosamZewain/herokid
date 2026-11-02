<?php

namespace App\Console\Commands;

use App\Services\MobileGames\GameConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MobileGameRetention extends Command
{
    protected $signature = 'mobile-games:retention {--execute : Delete expired raw analytics after separate operational approval} {--confirm-cutoff= : Exact UTC date shown by the dry run}';

    protected $description = 'Inventory expired anonymous analytics; deletion is explicit and never scheduled by this release.';

    public function handle(GameConfiguration $configuration): int
    {
        $days = $configuration->current()['data']['raw_retention_days'];
        $cutoff = now()->utc()->subDays($days)->startOfDay();
        $this->info("UTC cutoff: {$cutoff->toDateString()} ({$days} days). Daily aggregates and configuration history are retained.");
        $tables = ['mobile_game_events', 'mobile_game_rounds', 'mobile_game_sessions'];
        foreach ($tables as $table) {
            $this->line($table.': '.DB::table($table)->where('received_at', '<', $cutoff)->count());
        }
        if (! $this->option('execute')) {
            $this->warn('Dry run only. Deletion and scheduling require separate operational approval.');

            return self::SUCCESS;
        }
        if ($this->option('confirm-cutoff') !== $cutoff->toDateString()) {
            $this->error('Deletion refused: provide the exact dry-run UTC cutoff after approval.');

            return self::FAILURE;
        }
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = 0;
            do {
                $ids = DB::table($table)->where('received_at', '<', $cutoff)->orderBy('id')->limit(1000)->pluck('id');
                $counts[$table] += DB::table($table)->whereIn('id', $ids)->where('received_at', '<', $cutoff)->delete();
            } while ($ids->count() === 1000);
        }
        Log::info('Anonymous game retention completed.', ['cutoff_utc' => $cutoff->toDateString(), 'deleted_counts' => $counts]);
        $this->info('Expired raw analytics removed; daily aggregates preserved.');

        return self::SUCCESS;
    }
}
