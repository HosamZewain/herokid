<?php

namespace Tests\Feature;

use App\Models\SalesConversation;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesAssistantBudgetRaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || config('database.connections.mysql.host') !== 'hk-sales-mysql' || DB::getDatabaseName() !== 'testing') {
            $this->markTestSkipped('Requires the dedicated disposable hk-sales-mysql/testing container.');
        }
        Http::preventStrayRequests();
        Queue::fake();
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    public function test_real_mysql_concurrent_reservations_never_exceed_daily_cap_or_duplicate_a_turn(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Real committed MySQL row-lock test.');
        }
        Setting::updateOrCreate(['key' => 'sales_assistant_daily_budget_micros'], ['value' => '300000']);
        $processes = [];
        foreach (range(1, 12) as $i) {
            $c = SalesConversation::create(['id' => (string) Str::uuid(), 'session_hash' => hash('sha256', 'race-'.$i), 'visitor_hash' => hash('sha256', 'visitor-'.$i)]);
            $pipes = [];
            $process = proc_open([PHP_BINARY, base_path('tests/Support/sales-budget-race.php'), $c->id, (string) Str::uuid()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $reserved = 0;
        foreach ($processes as [$process,$pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stderr);
            $this->assertContains($stdout, ['RESERVED', 'LIMITED']);
            $reserved += (int) ($stdout === 'RESERVED');
        }
        $this->assertSame(3, $reserved);
        $this->assertDatabaseCount('sales_turns', 3);
        $this->assertSame(300000, (int) DB::table('sales_daily_budgets')->value('reserved_micros'));
        DB::table('sales_daily_budgets')->update(['reserved_micros' => 0]);
        DB::table('sales_turns')->delete();
        $c = SalesConversation::firstOrFail();
        $nonce = (string) Str::uuid();
        $processes = [];
        foreach (range(1, 6) as $i) {
            $pipes = [];
            $p = proc_open([PHP_BINARY, base_path('tests/Support/sales-budget-race.php'), $c->id, $nonce], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            fclose($pipes[0]);
            $processes[] = [$p, $pipes];
        }
        foreach ($processes as [$p,$pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($p), $err);
            $this->assertSame('RESERVED', $out);
        }
        $this->assertDatabaseCount('sales_turns', 1);
        $this->assertSame(100000, (int) DB::table('sales_daily_budgets')->value('reserved_micros'));
    }
}
