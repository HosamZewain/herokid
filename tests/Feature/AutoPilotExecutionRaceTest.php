<?php

namespace Tests\Feature;

use App\Models\AiProductionExecution;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\MemoryService;
use App\Services\Agentic\ProductionExecutor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgenticFixtures;
use Tests\Support\AutoPilotExecutionFixtures;
use Tests\TestCase;

/** Run separately on the isolated synthetic MySQL database: requires real commits. */
class AutoPilotExecutionRaceTest extends TestCase
{
    use AgenticFixtures, AutoPilotExecutionFixtures;

    public function test_mysql_edit_committed_between_selection_and_lock_is_rejected(): void
    {
        $this->syntheticDatabase();
        $state = $this->observedCheckout('mysql-race');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $originalIsolation = DB::selectOne('SELECT @@transaction_isolation as level')->level;
        config(['database.connections.execution_race' => config('database.connections.mysql')]);
        $injected = false;
        DB::listen(function ($query) use (&$injected, $state) {
            if (! $injected && $query->connectionName !== 'execution_race' && str_contains($query->sql, 'ai_memories') && str_contains(strtolower($query->sql), 'for update')) {
                $injected = true;
                DB::connection('execution_race')->table('orders')->where('id', $state->representative_id)->update(['child_name' => 'Changed before checkout lock']);
            }
        });
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertTrue($injected);
        $this->assertSame('EXECUTION_STALE_CONTEXT', AiProductionExecution::sole()->reason_code);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertSame($originalIsolation, DB::selectOne('SELECT @@transaction_isolation as level')->level);
        DB::disconnect('execution_race');
    }

    public function test_new_active_memory_cannot_commit_during_an_admission(): void
    {
        $this->syntheticDatabase();
        config(['database.connections.execution_gate' => config('database.connections.mysql')]);
        $gate = DB::connection('execution_gate');
        $gate->beginTransaction();
        $gate->table('ai_production_controls')->where('id', 1)->lockForUpdate()->first();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $input = ['title' => 'Synthetic rule', 'guidance' => 'Keep existing approvals', 'scope_type' => 'global',
            'scope_key' => '', 'status' => 'active', 'change_reason' => 'Synthetic concurrency test'];
        try {
            app(MemoryService::class)->save(null, $input, null);
            $this->fail('A new rule bypassed the in-flight admission lock.');
        } catch (QueryException $e) {
            $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0));
        } finally {
            $gate->rollBack();
            DB::disconnect('execution_gate');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $this->assertDatabaseCount('ai_memories', 0);
        $this->assertSame('active', app(MemoryService::class)->save(null, $input, null)->status);
        $this->assertDatabaseCount('ai_memory_versions', 1);
    }

    private function syntheticDatabase(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql' || config('database.connections.mysql.database') !== 'autopilot_synthetic') {
            $this->markTestSkipped('Requires the explicit isolated MySQL fixture database.');
        }
        // Requires real commits. Never run the inherited suite without rollback,
        // and never migrate until the dedicated synthetic database is verified.
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        Http::preventStrayRequests();
        Queue::fake();
        config(['order_process.enabled' => false, 'puzzle_automation.enabled' => true]);
        $this->enableSyntheticExecution();
    }
}
