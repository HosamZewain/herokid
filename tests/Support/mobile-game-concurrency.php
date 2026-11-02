<?php

use App\Services\MobileGames\GameEvents;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// Isolated MySQL integration probe, deliberately impossible to run against production.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'testing'
    || config('database.connections.mysql.host') !== 'herokid-codex-trial-mysql') {
    throw new RuntimeException('Requires the isolated internal-network test database.');
}
Http::preventStrayRequests();
foreach (['mobile_game_events', 'mobile_game_rounds', 'mobile_game_daily', 'mobile_game_sessions'] as $table) {
    if (DB::table($table)->count() !== 0) {
        throw new RuntimeException('Expected empty synthetic analytics tables.');
    }
}
$round = (string) Str::uuid();
$start = ['id' => (string) Str::uuid(), 'name' => 'game_started', 'game_id' => 'memory', 'round_id' => $round,
    'level' => 3, 'rules_version' => 1, 'occurred_at' => now()->subSeconds(60)->utc()->toIso8601String()];
$end = array_replace($start, ['id' => (string) Str::uuid(), 'name' => 'game_completed', 'occurred_at' => now()->utc()->toIso8601String(), 'duration_ms' => 30000, 'moves' => 6]);
DB::disconnect();
$children = [];
for ($i = 0; $i < 8; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Fork failed');
    }
    if ($pid === 0) {
        DB::purge();
        try {
            $service = app(GameEvents::class);
            foreach ($i % 2 ? [$start, $end] : [$end, $start] as $event) {
                $service->record($event, 'ios', '1.1.0');
            }
            // A different UUID for the same round must still not increment counters.
            $service->record(array_replace($end, ['id' => (string) Str::uuid()]), 'ios', '1.1.0');
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, get_class($e)." in concurrency probe\n");
            exit(1);
        }
    }
    $children[] = $pid;
}
$failed = false;
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $failed = $failed || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
}
DB::purge();
if ($failed) {
    throw new RuntimeException('Concurrent child failed');
}
foreach (['game_started' => 1, 'game_completed' => 1, 'matched_completed' => 1, 'duration_sum' => 30000, 'duration_count' => 1, 'moves_sum' => 6] as $key => $expected) {
    if ((int) DB::table('mobile_game_daily')->sum($key) !== $expected) {
        throw new RuntimeException('Incorrect concurrent aggregate: '.$key);
    }
}
echo "PASS: 8 processes, reverse arrivals, event and round dedupe; exactly one matched round.\n";
// Only this synthetic probe's rows; never a broad cleanup command.
DB::table('mobile_game_events')->where('round_id', $round)->delete();
DB::table('mobile_game_rounds')->where('id', $round)->delete();
DB::table('mobile_game_daily')->where('platform', 'ios')->where('app_version', '1.1.0')->where('game_id', 'memory')->delete();
