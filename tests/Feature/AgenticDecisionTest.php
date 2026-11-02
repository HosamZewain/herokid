<?php

namespace Tests\Feature;

use App\Models\{AiAgentRun, AiCheckoutState, AiOrderDecision, Order};
use App\Services\Agentic\{ContextObserver, DecisionOrchestrator, DecisionValidator};
use App\Services\Agentic\Providers\{DecisionProvider, MockProvider};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Queue};
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AgenticDecisionTest extends TestCase
{
    use RefreshDatabase, AgenticFixtures;

    protected function setUp(): void
    {
        parent::setUp(); Http::preventStrayRequests(); Queue::fake();
        config(['agentic.observe' => true, 'agentic.evaluate' => true, 'agentic.provider' => 'mock', 'agentic.debounce_seconds' => 0,
            'agentic.cycles_per_pass' => 3, 'agentic.daily_limit' => 100, 'puzzle_automation.enabled' => true,
            'puzzle_automation.after_event_id' => 999999, 'order_process.enabled' => false]);
    }

    public function test_sequential_passes_cover_independent_siblings_once_without_simulating_effects(): void
    {
        $state = $this->observedCheckout('multi', 5);
        $before = DB::table('orders')->get()->toJson();
        $result = app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(3, $result['calls']);
        $this->assertSame('partial', $result['status']);
        $this->assertCount(3, $state->fresh()->covered_targets);
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->travel(61)->seconds();
        $this->assertSame(2, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('covered', $state->fresh()->status);
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertCount(5, AiOrderDecision::all()->pluck('decision.target_id')->unique());
        $this->assertTrue(AiOrderDecision::all()->every(fn ($d) => $d->simulation['executed'] === false && $d->simulation['reserved'] === false));
        $this->assertSame($before, DB::table('orders')->get()->toJson());
        $this->assertDatabaseCount('puzzle_generations', 0);
        Queue::assertNothingPushed(); Http::assertNothingSent();
    }

    public function test_quota_is_configurable_and_resets_at_cairo_midnight(): void
    {
        config(['agentic.daily_limit' => 2]);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 23:59:00', 'Africa/Cairo')->utc());
        $state = $this->observedCheckout('quota', 3);
        $this->assertSame(2, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('quota_deferred', $state->fresh()->status);
        $this->assertSame(2, (int) DB::table('ai_runtime_states')->value('attempts'));
        $this->travel(2)->minutes();
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('2026-11-03', DB::table('ai_runtime_states')->value('quota_day'));
        $this->assertSame(1, (int) DB::table('ai_runtime_states')->value('attempts'));
    }

    public function test_duplicate_hints_do_not_repeat_unchanged_decisions(): void
    {
        $state = $this->observedCheckout('duplicate');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        foreach (range(1, 4) as $ignored) { app(ContextObserver::class)->markDirty('duplicate'); app(ContextObserver::class)->observe('duplicate'); }
        $this->travel(10)->minutes();
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame(1, $state->fresh()->context_version);
        $this->assertDatabaseCount('ai_agent_runs', 1);
    }

    public function test_raising_limit_resumes_deferred_siblings_without_waiting_a_day(): void
    {
        config(['agentic.daily_limit' => 1]);
        $state = $this->observedCheckout('raise-quota', 3);
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        config(['agentic.daily_limit' => 3]);
        $this->assertSame(2, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('covered', $state->fresh()->status);
    }

    public function test_sql_closed_order_is_reconciled_without_an_event(): void
    {
        $state = $this->observedCheckout('sql-close');
        Order::where('checkout_group_key', 'sql-close')->update(['status' => 'cancelled']);
        app(ContextObserver::class)->reconcile();
        $this->assertSame('inactive', $state->fresh()->status);
        $this->assertTrue($state->fresh()->snapshot['all_complete']);
    }

    public function test_blocked_unit_does_not_hide_an_independent_ready_sibling(): void
    {
        $state = $this->observedCheckout('blocked-sibling', 2);
        Order::where('checkout_group_key', 'blocked-sibling')->orderBy('id')->first()->update(['uploaded_photos' => []]);
        app(ContextObserver::class)->observe('blocked-sibling');
        $this->assertSame(2, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame(['human_required', 'execute'], AiOrderDecision::orderBy('id')->get()->pluck('decision.decision')->all());
    }

    public function test_changed_state_during_provider_call_is_stale_and_never_covers_units(): void
    {
        $state = $this->observedCheckout('stale');
        $this->app->instance(DecisionProvider::class, new class extends MockProvider {
            public function decide(array $input): array {
                $result = parent::decide($input);
                Order::where('checkout_group_key', 'stale')->update(['parent_notes' => 'Changed during the model request']);
                return $result;
            }
        });
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('stale', AiAgentRun::first()->status);
        $this->assertSame('STALE_CONTEXT', AiOrderDecision::first()->policy['reason_code']);
        $this->assertSame([], $state->fresh()->covered_targets);
        $this->assertSame(2, $state->fresh()->context_version);
    }

    public function test_false_waiting_is_rejected_instead_of_hiding_ready_work(): void
    {
        $state = $this->observedCheckout('false-wait', 2);
        $this->app->instance(DecisionProvider::class, new class extends MockProvider {
            public function decide(array $input): array {
                $result = parent::decide($input);
                $result['decision']['decision'] = 'wait'; $result['decision']['action'] = null;
                return $result;
            }
        });
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('FALSE_WAITING', AiOrderDecision::first()->policy['reason_code']);
        $this->assertSame('error', $state->fresh()->status);
        $this->assertSame([], $state->fresh()->covered_targets);
        $this->travel(1)->day();
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
    }

    public function test_provider_outage_does_not_stop_current_operational_observation(): void
    {
        $state = $this->observedCheckout('offline');
        $this->app->instance(DecisionProvider::class, new class extends MockProvider {
            public function health(): array { return ['ready' => false]; }
        });
        $this->assertSame('provider_unavailable', app(DecisionOrchestrator::class)->evaluatePass()['status']);
        Order::where('checkout_group_key', 'offline')->update(['status' => 'generating']);
        app(ContextObserver::class)->observe('offline');
        $this->assertSame('generating', $state->fresh()->snapshot['orders'][0]['status']);
        $this->assertSame(0, (int) DB::table('ai_runtime_states')->value('attempts'));
        $this->assertDatabaseCount('ai_agent_runs', 0);
    }

    public function test_expired_worker_is_not_blindly_retried(): void
    {
        $state = $this->observedCheckout('lost');
        AiAgentRun::create(['id' => (string) \Illuminate\Support\Str::uuid(), 'checkout_state_id' => $state->id, 'context_version' => 1,
            'fingerprint' => $state->fingerprint, 'snapshot' => [], 'provider' => 'mock', 'policy_version' => 'test', 'prompt_version' => 'test', 'status' => 'running', 'started_at' => now()->subHour()]);
        DB::table('ai_runtime_states')->where('id', 1)->update(['lease_token' => (string) \Illuminate\Support\Str::uuid(), 'lease_until' => now()->subMinute()]);
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass()['calls']);
        $this->assertSame('WORKER_OUTCOME_UNKNOWN', $state->fresh()->error_code);
    }

    public function test_busy_lease_and_disabled_flags_prevent_calls(): void
    {
        $this->observedCheckout('busy');
        DB::table('ai_runtime_states')->where('id', 1)->update(['lease_token' => (string) \Illuminate\Support\Str::uuid(), 'lease_until' => now()->addMinute()]);
        $this->assertSame('busy', app(DecisionOrchestrator::class)->evaluatePass()['status']);
        config(['agentic.evaluate' => false]);
        $this->assertSame('disabled', app(DecisionOrchestrator::class)->evaluatePass()['status']);
        $this->assertDatabaseCount('ai_agent_runs', 0);
    }

    public function test_never_evaluated_orders_make_progress_among_blocked_orders(): void
    {
        $normal = $this->observedCheckout('normal');
        foreach (range(1, 5) as $i) {
            $blocked = $this->observedCheckout('blocked-'.$i);
            DB::table('order_automation_holds')->insert(['checkout_key' => 'blocked-'.$i, 'user_id' => 999, 'fingerprint' => str_repeat('a', 64), 'runs' => '{}', 'confirmations' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            app(ContextObserver::class)->observe($blocked->checkout_key);
        }
        foreach (range(1, 3) as $ignored) { app(DecisionOrchestrator::class)->evaluatePass(); }
        $this->assertSame('covered', $normal->fresh()->status);
    }

    public function test_schema_rejects_extra_actions_type_coercion_and_confidence_override(): void
    {
        $valid = ['decision' => 'wait', 'action' => null, 'target_type' => 'checkout', 'target_id' => 'checkout:1',
            'reason_code' => 'WAIT', 'reason' => 'Synthetic', 'confidence' => 1, 'context_version' => 1, 'requires_human' => false];
        foreach ([$valid + ['second_action' => 'pay'], array_replace($valid, ['confidence' => '1']), array_replace($valid, ['context_version' => '1']),
            array_replace($valid, ['action' => 'record_payment']), array_replace($valid, ['requires_human' => true])] as $invalid) {
            try { app(DecisionValidator::class)->validate($invalid); $this->fail('Invalid decision accepted.'); }
            catch (\InvalidArgumentException $e) { $this->assertSame('invalid_decision_schema', $e->getMessage()); }
        }
    }
}
