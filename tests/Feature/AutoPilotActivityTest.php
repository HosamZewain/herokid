<?php

namespace Tests\Feature;

use App\Models\AiAgentRun;
use App\Models\AiCheckoutState;
use App\Services\Agentic\ActivityMonitor;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\Providers\DecisionProvider;
use App\Services\Agentic\Providers\MockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AutoPilotActivityTest extends TestCase
{
    use AgenticFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['agentic.observe' => true, 'agentic.evaluate' => true, 'agentic.provider' => 'mock', 'agentic.debounce_seconds' => 0,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 999999, 'order_process.enabled' => false]);
    }

    public function test_inflight_activity_reports_real_checkout_and_remaining_units_then_keeps_last_decision(): void
    {
        $state = $this->observedCheckout('current-work', 2);
        $provider = new class extends MockProvider
        {
            public array $seen = [];
            public function decide(array $input): array
            {
                $this->seen[] = app(ActivityMonitor::class)->snapshot();

                return parent::decide($input);
            }
        };
        $this->app->instance(DecisionProvider::class, $provider);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertCount(2, $provider->seen);
        foreach ($provider->seen as $i => $snapshot) {
            $this->assertSame('awaiting_decision', $snapshot['stage']);
            $this->assertTrue($snapshot['working']);
            $this->assertSame($state->representative_id, $snapshot['order']->id);
            $this->assertCount(2 - $i, $snapshot['targets']);
            $this->assertNotNull($snapshot['started_at']);
            $this->assertArrayNotHasKey('lease_token', $snapshot);
        }
        $done = app(ActivityMonitor::class)->snapshot();
        $this->assertFalse($done['working']);
        $this->assertSame('covered', $done['last_outcome']);
        $this->assertSame($state->fresh()->last_run_id, $done['last_run']->id);
        app(DecisionOrchestrator::class)->evaluatePass();
        $idle = app(ActivityMonitor::class)->snapshot();
        $this->assertSame('idle', $idle['stage']);
        $this->assertSame($done['last_run']->id, $idle['last_run']->id);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_stalled_and_expired_workers_are_displayed_without_mutation_or_retry(): void
    {
        $state = $this->observedCheckout('stalled');
        DB::table('ai_runtime_states')->where('id', 1)->update(['lease_token' => 'synthetic-lease', 'lease_until' => now()->addMinute(),
            'activity_stage' => 'awaiting_decision', 'activity_state_id' => $state->id, 'activity_updated_at' => now()->subMinutes(5)]);
        $before = DB::table('ai_runtime_states')->first();
        $snapshot = app(ActivityMonitor::class)->snapshot();
        $this->assertSame('stalled', $snapshot['stage']);
        $this->assertFalse($snapshot['working']);
        $this->assertEquals($before, DB::table('ai_runtime_states')->first());
        $this->travel(2)->minutes();
        $this->assertSame('worker_unknown', app(ActivityMonitor::class)->snapshot()['stage']);
        $this->assertDatabaseCount('ai_agent_runs', 0);
        $this->assertSame('pending', $state->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_disabled_quota_and_provider_states_remain_available_without_calling_ai(): void
    {
        $this->mock(DecisionProvider::class)->shouldNotReceive('health')->shouldNotReceive('decide');
        $this->observedCheckout('waiting');
        $monitor = app(ActivityMonitor::class);
        $this->assertSame('waiting_queue', $monitor->snapshot()['stage']);
        config(['agentic.observe' => false]);
        $this->assertSame('observation_disabled', $monitor->snapshot()['stage']);
        config(['agentic.observe' => true, 'agentic.evaluate' => false]);
        $this->assertSame('evaluation_disabled', $monitor->snapshot()['stage']);
        config(['agentic.evaluate' => true, 'agentic.daily_limit' => 1]);
        DB::table('ai_runtime_states')->where('id', 1)->update(['attempts' => 1, 'quota_day' => now('Africa/Cairo')->toDateString()]);
        $this->assertSame('quota_deferred', $monitor->snapshot()['stage']);
        DB::table('ai_runtime_states')->where('id', 1)->update(['provider_error' => 'PROVIDER_NOT_READY']);
        $this->assertSame('provider_unavailable', $monitor->snapshot()['stage']);
        $this->assertDatabaseCount('ai_agent_runs', 0);
    }

    public function test_old_worker_cannot_replace_activity_of_a_new_lease(): void
    {
        DB::table('ai_runtime_states')->where('id', 1)->update(['lease_token' => 'new-worker', 'activity_stage' => 'checking_provider']);
        app(ActivityMonitor::class)->record('old-worker', 'saving_decision');
        $this->assertSame('checking_provider', DB::table('ai_runtime_states')->value('activity_stage'));
    }

    public function test_failed_or_unobserved_work_is_never_described_as_finished(): void
    {
        $state = $this->observedCheckout('needs-attention');
        $state->update(['status' => 'error', 'error_code' => 'INVALID_DECISION']);
        $this->assertSame('attention_required', app(ActivityMonitor::class)->snapshot()['stage']);
        $state->delete();
        $this->assertSame('awaiting_observation', app(ActivityMonitor::class)->snapshot()['stage']);
    }

    public function test_provider_health_exception_is_visible_and_releases_only_shadow_lease(): void
    {
        $this->observedCheckout('health-failed');
        $this->mock(DecisionProvider::class)->shouldReceive('health')->once()->andThrow(new \RuntimeException('synthetic secret error'));
        $this->assertSame('provider_unavailable', app(DecisionOrchestrator::class)->evaluatePass()['status']);
        $snapshot = app(ActivityMonitor::class)->snapshot();
        $this->assertSame('provider_unavailable', $snapshot['stage']);
        $this->assertSame('PROVIDER_NOT_READY', $snapshot['provider_error']);
        $this->assertNull(DB::table('ai_runtime_states')->value('lease_token'));
        $this->assertDatabaseCount('ai_agent_runs', 0);
    }

    public function test_local_real_benchmark_reports_its_own_activity_without_claiming_an_order(): void
    {
        $provider = new class extends \App\Services\Agentic\Providers\CodexSdkProvider
        {
            public array $seen = [];
            public function health(): array { return ['ready' => true]; }
            public function decide(array $input): array
            {
                $this->seen = app(ActivityMonitor::class)->snapshot();

                return ['decision' => [], 'metadata' => []];
            }
        };
        (new \App\Services\Agentic\Providers\EvaluationBudgetProvider($provider))->decide([]);
        $this->assertSame('evaluating_dataset', $provider->seen['stage']);
        $this->assertTrue($provider->seen['working']);
        $this->assertNull($provider->seen['order']);
        $this->assertSame('benchmark_finished', app(ActivityMonitor::class)->snapshot()['last_outcome']);
        $this->assertSame(1, (int) DB::table('ai_runtime_states')->value('attempts'));
        $this->assertDatabaseCount('ai_agent_runs', 0);
    }
}
