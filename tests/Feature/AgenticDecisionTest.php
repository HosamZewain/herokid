<?php

namespace Tests\Feature;

use App\Models\AiAgentRun;
use App\Models\AiOrderDecision;
use App\Models\Order;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\DecisionValidator;
use App\Services\Agentic\ExecutionGateway;
use App\Services\Agentic\Providers\CodexSdkProvider;
use App\Services\Agentic\Providers\DecisionProvider;
use App\Services\Agentic\Providers\EvaluationBudgetProvider;
use App\Services\Agentic\Providers\MockProvider;
use App\Services\Agentic\Providers\TransientDecisionFailure;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AgenticDecisionTest extends TestCase
{
    use AgenticFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        config(['agentic.observe' => true, 'agentic.evaluate' => true, 'agentic.provider' => 'mock', 'agentic.debounce_seconds' => 0,
            'agentic.cycles_per_pass' => 3, 'agentic.daily_limit' => 100, 'puzzle_automation.enabled' => true,
            'puzzle_automation.after_event_id' => 999999, 'order_process.enabled' => false]);
    }

    public function test_real_benchmark_adapter_shares_quota_and_cannot_overlap_operational_decisions(): void
    {
        $fake = new class extends CodexSdkProvider
        {
            public int $calls = 0;

            public function health(): array
            {
                return ['ready' => true];
            }

            public function decide(array $input): array
            {
                $this->calls++;

                return ['decision' => [], 'metadata' => []];
            }
        };
        $adapter = new EvaluationBudgetProvider($fake);
        config(['agentic.daily_limit' => 1]);
        $adapter->decide([]);
        $this->assertSame(1, $fake->calls);
        try {
            $adapter->decide([]);
            $this->fail('Quota must refuse a second call');
        } catch (\RuntimeException $e) {
            $this->assertSame('benchmark_quota_exhausted', $e->getMessage());
        }
        config(['agentic.daily_limit' => 100]);
        DB::table('ai_runtime_states')->where('id', 1)->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
        try {
            $adapter->decide([]);
            $this->fail('Lease must refuse overlap');
        } catch (\RuntimeException $e) {
            $this->assertSame('benchmark_busy', $e->getMessage());
        }
        $this->assertSame(1, $fake->calls);
    }

    public function test_bridge_schema_matches_php_actions_and_gateway_has_no_execution_method(): void
    {
        $schema = json_decode(file_get_contents(base_path('ops/agentic/decision-schema.json')), true);
        $this->assertEqualsCanonicalizing(DecisionValidator::FIELDS, $schema['required']);
        $this->assertEqualsCanonicalizing([null, ...CapabilityRegistry::ACTIONS], $schema['properties']['action']['enum']);
        $this->assertSame(['simulate'], array_map(fn ($m) => $m->name, (new \ReflectionClass(ExecutionGateway::class))->getMethods(\ReflectionMethod::IS_PUBLIC)));
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
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_quota_is_configurable_and_resets_at_cairo_midnight(): void
    {
        config(['agentic.daily_limit' => 2]);
        $this->travelTo(Carbon::parse('2026-11-02 23:59:00', 'Africa/Cairo')->utc());
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
        foreach (range(1, 4) as $ignored) {
            app(ContextObserver::class)->markDirty('duplicate');
            app(ContextObserver::class)->observe('duplicate');
        }
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
        $this->assertSame(['execute', 'human_required'], AiOrderDecision::orderBy('id')->get()->pluck('decision.decision')->all());
    }

    public function test_changed_state_during_provider_call_is_stale_and_never_covers_units(): void
    {
        $state = $this->observedCheckout('stale');
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function decide(array $input): array
            {
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
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function decide(array $input): array
            {
                $result = parent::decide($input);
                $result['decision']['decision'] = 'wait';
                $result['decision']['action'] = null;

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
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function health(): array
            {
                return ['ready' => false];
            }
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
        AiAgentRun::create(['id' => (string) Str::uuid(), 'checkout_state_id' => $state->id, 'context_version' => 1,
            'fingerprint' => $state->fingerprint, 'snapshot' => [], 'provider' => 'mock', 'policy_version' => 'test', 'prompt_version' => 'test', 'status' => 'running', 'started_at' => now()->subHour()]);
        DB::table('ai_runtime_states')->where('id', 1)->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->subMinute()]);
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass()['calls']);
        $this->assertSame('WORKER_OUTCOME_UNKNOWN', $state->fresh()->error_code);
    }

    public function test_busy_lease_and_disabled_flags_prevent_calls(): void
    {
        $this->observedCheckout('busy');
        DB::table('ai_runtime_states')->where('id', 1)->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinute()]);
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
        foreach (range(1, 3) as $ignored) {
            app(DecisionOrchestrator::class)->evaluatePass();
        }
        $this->assertSame('covered', $normal->fresh()->status);
    }

    public function test_ready_work_leads_but_third_pass_still_covers_old_blocked_work(): void
    {
        $blocked = $this->observedCheckout('old-blocked');
        Order::where('checkout_group_key', 'old-blocked')->update(['uploaded_photos' => []]);
        app(ContextObserver::class)->observe('old-blocked');
        $ready = [];
        foreach (range(1, 5) as $i) {
            $ready[] = $this->observedCheckout('ready-'.$i);
        }
        app(DecisionOrchestrator::class)->evaluatePass();
        $this->assertSame('covered', $ready[0]->fresh()->status);
        $this->assertSame('pending', $blocked->fresh()->status);
        app(DecisionOrchestrator::class)->evaluatePass();
        app(DecisionOrchestrator::class)->evaluatePass();
        $this->assertSame('covered', $blocked->fresh()->status);
        $this->assertSame('pending', $ready[2]->fresh()->status);
    }

    public function test_ready_counter_tracks_uncovered_siblings_and_preserves_wait_age(): void
    {
        config(['agentic.cycles_per_pass' => 1]);
        $state = $this->observedCheckout('ready-age', 2);
        $since = $state->ready_since->toISOString();
        $this->assertSame(2, $state->ready_target_count);
        $this->travel(2)->minutes();
        app(ContextObserver::class)->observe('ready-age');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, $state->fresh()->ready_target_count);
        $this->assertSame($since, $state->fresh()->ready_since->toISOString());
        $this->travel(1)->minutes();
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(0, $state->fresh()->ready_target_count);
        $this->assertNull($state->fresh()->ready_since);
    }

    public function test_ready_priority_is_rechecked_against_a_new_hold_before_calling_model(): void
    {
        $state = $this->observedCheckout('new-hold');
        DB::table('order_automation_holds')->insert(['checkout_key' => 'new-hold', 'user_id' => 999,
            'fingerprint' => str_repeat('a', 64), 'runs' => '{}', 'confirmations' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        app(DecisionOrchestrator::class)->evaluatePass();
        $this->assertSame('human_required', AiOrderDecision::sole()->decision['decision']);
        $this->assertSame('MANUAL_HOLD', AiOrderDecision::sole()->decision['reason_code']);
        $this->assertSame(0, $state->fresh()->ready_target_count);
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_unchanged_blocked_work_does_not_spend_ai_on_reconciliation_but_new_photos_wake_it(): void
    {
        $state = $this->observedCheckout('waiting-change');
        Order::where('checkout_group_key', 'waiting-change')->update(['uploaded_photos' => []]);
        app(ContextObserver::class)->observe('waiting-change');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        foreach (range(1, 3) as $i) {
            $this->travel(6)->minutes();
            app(ContextObserver::class)->markDirty('waiting-change');
            app(ContextObserver::class)->observeDirty();
            app(ContextObserver::class)->reconcile();
            $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        }
        Order::where('checkout_group_key', 'waiting-change')->update(['uploaded_photos' => ['synthetic/photo.png']]);
        app(ContextObserver::class)->observeDirty();
        // Query-builder writes may bypass events: the periodic sweep is the fallback.
        app(ContextObserver::class)->observe('waiting-change');
        $this->assertSame(1, $state->fresh()->ready_target_count);
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertDatabaseCount('ai_agent_runs', 2);
    }

    public function test_transient_decision_transport_retries_are_delayed_bounded_and_charge_normal_quota(): void
    {
        $state = $this->observedCheckout('transport');
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function decide(array $input): array
            {
                throw new TransientDecisionFailure('DECISION_TRANSPORT_TIMEOUT');
            }
        });
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('pending', $state->fresh()->status);
        $this->assertEqualsWithDelta(300, now()->diffInSeconds($state->fresh()->next_evaluation_at), 1);
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->travel(5)->minutes();
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertEqualsWithDelta(900, now()->diffInSeconds($state->fresh()->next_evaluation_at), 1);
        $this->travel(15)->minutes();
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('error', $state->fresh()->status);
        $this->travel(1)->day();
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame(3, (int) DB::table('ai_runtime_states')->value('attempts'));
        $this->assertDatabaseCount('ai_agent_runs', 3);
        $this->assertDatabaseCount('ai_order_decisions', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_transient_retry_still_needs_health_and_quota_then_can_succeed_once(): void
    {
        config(['agentic.daily_limit' => 1]);
        $state = $this->observedCheckout('recover');
        $provider = new class extends MockProvider
        {
            public bool $ready = true;

            public int $calls = 0;

            public function health(): array
            {
                return ['ready' => $this->ready];
            }

            public function decide(array $input): array
            {
                if (++$this->calls === 1) {
                    throw new TransientDecisionFailure('DECISION_BRIDGE_UNAVAILABLE');
                }

                return parent::decide($input);
            }
        };
        $this->app->instance(DecisionProvider::class, $provider);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->travel(5)->minutes();
        $provider->ready = false;
        $this->assertSame('provider_unavailable', app(DecisionOrchestrator::class)->evaluatePass($state->id)['status']);
        $provider->ready = true;
        $this->assertSame('quota_deferred', app(DecisionOrchestrator::class)->evaluatePass($state->id)['status']);
        config(['agentic.daily_limit' => 2]);
        $this->assertSame('covered', app(DecisionOrchestrator::class)->evaluatePass($state->id)['status']);
        $this->assertSame(2, $provider->calls);
        $this->assertNull($state->fresh()->error_code);
        $this->assertDatabaseCount('ai_order_decisions', 1);
    }

    public function test_uncertain_provider_or_authentication_refusal_is_not_retried(): void
    {
        $state = $this->observedCheckout('uncertain');
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function decide(array $input): array
            {
                throw new \RuntimeException('codex_result_rejected');
            }
        });
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->travel(1)->day();
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame('PROVIDER_RESULT_UNCERTAIN', $state->fresh()->error_code);
        $this->assertDatabaseCount('ai_agent_runs', 1);
    }

    public function test_decision_persistence_failure_is_distinct_and_never_retried(): void
    {
        $state = $this->observedCheckout('save-error');
        AiOrderDecision::creating(fn () => throw new \RuntimeException('synthetic persistence failure'));
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame('DECISION_SAVE_FAILED', $state->fresh()->error_code);
        $this->assertSame('error', $state->fresh()->status);
        $this->assertDatabaseCount('ai_order_decisions', 0);
        $this->travel(1)->day();
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
    }

    public function test_retry_limit_can_be_disabled_without_resetting_coverage_or_history(): void
    {
        config(['agentic.decision_retry_limit' => 0]);
        $state = $this->observedCheckout('no-retry');
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function decide(array $input): array
            {
                throw new TransientDecisionFailure('DECISION_BRIDGE_UNAVAILABLE');
            }
        });
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame('error', $state->fresh()->status);
        $this->assertSame([], $state->fresh()->covered_targets);
        $this->travel(1)->day();
        $this->assertSame(0, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertDatabaseCount('ai_agent_runs', 1);
    }

    public function test_schema_rejects_extra_actions_type_coercion_and_confidence_override(): void
    {
        $valid = ['decision' => 'wait', 'action' => null, 'target_type' => 'checkout', 'target_id' => 'checkout:1',
            'reason_code' => 'WAIT', 'reason' => 'Synthetic', 'confidence' => 1, 'context_version' => 1, 'requires_human' => false];
        foreach ([$valid + ['second_action' => 'pay'], array_replace($valid, ['confidence' => '1']), array_replace($valid, ['context_version' => '1']),
            array_replace($valid, ['action' => 'record_payment']), array_replace($valid, ['requires_human' => true])] as $invalid) {
            try {
                app(DecisionValidator::class)->validate($invalid);
                $this->fail('Invalid decision accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('invalid_decision_schema', $e->getMessage());
            }
        }
    }
}
