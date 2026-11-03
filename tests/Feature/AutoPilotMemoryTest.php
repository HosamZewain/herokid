<?php

namespace Tests\Feature;

use App\Models\AiAgentRun;
use App\Models\AiMemory;
use App\Models\AiMemoryVersion;
use App\Models\AiOrderDecision;
use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\MemoryService;
use App\Services\Agentic\OrderContextBuilder;
use App\Services\Agentic\Providers\DecisionProvider;
use App\Services\Agentic\Providers\MockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Support\AgenticFixtures;
use Tests\TestCase;

class AutoPilotMemoryTest extends TestCase
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

    private function guidance(array $overrides = []): array
    {
        return $overrides + ['title' => 'قاعدة تجريبية', 'guidance' => 'راجع المخرجات الموجودة قبل اقتراح إنتاج جديد.',
            'scope_type' => 'global', 'scope_key' => '', 'status' => 'active', 'change_reason' => 'تصحيح بشري تجريبي'];
    }

    private function staff(bool $manage = false): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', 'order_automation.view', ...($manage ? ['order_automation.manage'] : [])])->pluck('id'));

        return $user->fresh();
    }

    public function test_correction_reopens_only_affected_context_without_changing_orders_or_calling_ai(): void
    {
        $one = $this->observedCheckout('one');
        $two = $this->observedCheckout('two');
        app(DecisionOrchestrator::class)->evaluatePass($one->id);
        app(DecisionOrchestrator::class)->evaluatePass($two->id);
        $orders = DB::table('orders')->get()->toJson();
        $runs = AiAgentRun::count();
        $memory = app(MemoryService::class)->save(null, $this->guidance(['scope_type' => 'checkout', 'scope_key' => 'one']), $this->staff(true));
        $this->assertNotNull($one->fresh()->dirty_at);
        $newOne = app(ContextObserver::class)->observe('one');
        $newTwo = app(ContextObserver::class)->observe('two');
        $this->assertSame(2, $newOne->context_version);
        $this->assertSame('pending', $newOne->status);
        $this->assertSame([], $newOne->covered_targets);
        $this->assertSame(1, $newTwo->context_version);
        $this->assertSame('covered', $newTwo->status);
        $this->assertSame([$memory->id], array_column($newOne->snapshot['memory']['entries'], 'id'));
        $this->assertSame([], $newTwo->snapshot['memory']['entries']);
        $this->assertSame($runs, AiAgentRun::count());
        $this->assertSame($orders, DB::table('orders')->get()->toJson());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_drafts_and_archived_rules_are_excluded_and_capability_scope_is_respected(): void
    {
        $this->observedCheckout('scope');
        $service = app(MemoryService::class);
        $service->save(null, $this->guidance(['status' => 'draft']), null);
        $service->save(null, $this->guidance(['status' => 'archived']), null);
        $service->save(null, $this->guidance(['scope_type' => 'capability', 'scope_key' => 'story_scenes']), null);
        $matching = $service->save(null, $this->guidance(['scope_type' => 'capability', 'scope_key' => 'single_image']), null);
        $context = app(OrderContextBuilder::class)->build('scope');
        $this->assertSame([$matching->id], array_column($context['memory']['entries'], 'id'));
        $before = $context['fingerprint'];
        $service->save($matching, $this->guidance(['status' => 'archived']), null, 1);
        $context = app(OrderContextBuilder::class)->build('scope');
        $this->assertSame([], $context['memory']['entries']);
        $this->assertNotSame($before, $context['fingerprint']);
    }

    public function test_versions_are_encrypted_immutable_and_restored_as_a_new_revision(): void
    {
        $service = app(MemoryService::class);
        $actor = $this->staff(true);
        $memory = $service->save(null, $this->guidance(), $actor);
        $service->save($memory, $this->guidance(['guidance' => 'تصحيح أحدث']), $actor, 1);
        $restored = $service->restore($memory->fresh(), 1, 2, $actor);
        $this->assertSame(3, $restored->revision);
        $this->assertSame($this->guidance()['guidance'], $restored->guidance);
        $this->assertSame([1, 2, 3], $restored->versions()->orderBy('revision')->pluck('revision')->all());
        $this->assertSame($actor->id, $restored->versions()->first()->actor_id);
        $this->assertStringNotContainsString('راجع المخرجات', DB::table('ai_memories')->value('guidance'));
        $this->assertStringNotContainsString('راجع المخرجات', DB::table('ai_memory_versions')->value('snapshot'));
        $this->expectException(\LogicException::class);
        $restored->versions()->first()->update(['revision' => 99]);
    }

    public function test_stale_editor_cannot_overwrite_a_newer_correction(): void
    {
        $service = app(MemoryService::class);
        $memory = $service->save(null, $this->guidance(), null);
        $service->save($memory, $this->guidance(['guidance' => 'تعديل حديث']), null, 1);
        try {
            $service->save($memory, $this->guidance(['guidance' => 'تعديل قديم']), null, 1);
            $this->fail('Stale edit must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('revision', $e->errors());
        }
        $this->assertSame('تعديل حديث', $memory->fresh()->guidance);
        $this->assertDatabaseCount('ai_memory_versions', 2);
    }

    public function test_invalid_scope_or_contact_details_cannot_enter_active_context(): void
    {
        foreach ([['scope_type' => 'checkout', 'scope_key' => 'not-an-order'], ['scope_type' => 'capability', 'scope_key' => 'shell'],
            ['guidance' => 'Visit https://example.test'], ['guidance' => 'Email someone@example.test'], ['guidance' => 'Call 01000000001']] as $invalid) {
            try {
                app(MemoryService::class)->save(null, $this->guidance($invalid), null);
                $this->fail('Invalid active guidance must be refused.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertDatabaseCount('ai_memories', 0);
    }

    public function test_owner_review_import_is_repeatable_and_never_overwrites_staff_edits(): void
    {
        $service = app(MemoryService::class);
        $this->assertSame(6, $service->importOwnerReview());
        $memory = AiMemory::first();
        $service->save($memory, $this->guidance(['guidance' => 'تصحيح لاحق من المسؤول', 'status' => 'archived']), null, 1);
        $this->assertSame(0, $service->importOwnerReview());
        $this->assertSame('تصحيح لاحق من المسؤول', $memory->fresh()->guidance);
        $this->assertSame('archived', $memory->fresh()->status);
        $this->assertDatabaseCount('ai_memories', 6);
        $this->assertDatabaseCount('ai_memory_versions', 7);
    }

    public function test_memory_change_while_provider_is_running_rejects_stale_proposal(): void
    {
        $state = $this->observedCheckout('changed-memory');
        $this->app->instance(DecisionProvider::class, new class extends MockProvider
        {
            public function decide(array $input): array
            {
                $response = parent::decide($input);
                app(MemoryService::class)->importOwnerReview();

                return $response;
            }
        });
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame('stale', AiAgentRun::first()->status);
        $this->assertSame('STALE_CONTEXT', AiOrderDecision::first()->policy['reason_code']);
        $this->assertSame([], $state->fresh()->covered_targets);
        $this->assertFalse(AiOrderDecision::first()->simulation['executed']);
        $this->assertSame([], AiAgentRun::first()->snapshot['memory']['entries']);
        $this->assertCount(6, $state->fresh()->snapshot['memory']['entries']);
    }

    public function test_overflow_is_visible_and_does_not_spend_a_model_call(): void
    {
        $state = $this->observedCheckout('memory-limit');
        config(['agentic.memory_limit' => 1]);
        app(MemoryService::class)->importOwnerReview();
        $this->mock(DecisionProvider::class)->shouldReceive('health')->once()->andReturn(['ready' => true])->getMock()->shouldNotReceive('decide');
        $result = app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(0, $result['calls']);
        $this->assertSame('MEMORY_CONTEXT_LIMIT', $state->fresh()->error_code);
        $this->assertDatabaseCount('ai_agent_runs', 0);
    }

    public function test_decision_history_keeps_the_exact_memory_revision_used_before_a_correction(): void
    {
        $state = $this->observedCheckout('memory-history');
        $service = app(MemoryService::class);
        $memory = $service->save(null, $this->guidance(), null);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $first = AiAgentRun::firstOrFail();
        $service->save($memory, $this->guidance(['guidance' => 'تصحيح لاحق']), null, 1);
        app(ContextObserver::class)->observe('memory-history');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $latest = AiAgentRun::findOrFail($state->fresh()->last_run_id);
        $this->assertSame(1, $first->fresh()->snapshot['memory']['entries'][0]['revision']);
        $this->assertSame($this->guidance()['guidance'], $first->fresh()->snapshot['memory']['entries'][0]['guidance']);
        $this->assertSame(2, $latest->snapshot['memory']['entries'][0]['revision']);
        $this->assertSame('تصحيح لاحق', $latest->snapshot['memory']['entries'][0]['guidance']);
        $this->assertDatabaseCount('ai_agent_runs', 2);
    }

    public function test_readers_cannot_change_memory_and_correction_form_links_the_source_run(): void
    {
        $state = $this->observedCheckout('source');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $run = AiAgentRun::first();
        $this->get(route('admin.agentic.memory.index'))->assertRedirect(route('login'));
        $reader = $this->staff();
        $this->actingAs($reader)->get(route('admin.agentic.memory.index'))->assertOk()->assertSee('HeroKid Auto Pilot');
        $this->post(route('admin.agentic.memory.store'), [])->assertForbidden();
        $this->post(route('admin.agentic.memory.import'))->assertForbidden();
        $this->get(route('admin.agentic.memory.create'))->assertForbidden();
        $manager = $this->staff(true);
        $this->actingAs($manager)->get(route('admin.agentic.memory.create', ['run' => $run->id]))->assertOk()->assertSee('الاقتراح الذي تصحّحه');
        $this->post(route('admin.agentic.memory.store'), $this->guidance(['status' => 'draft']) + ['scope' => 'checkout', 'run_id' => $run->id])->assertSessionHasNoErrors()->assertRedirect();
        $memory = AiMemory::firstOrFail();
        $this->assertSame('source', $memory->scope_key);
        $this->assertSame($run->id, $memory->source_run_id);
        $this->assertSame('draft', $memory->status);
        $this->actingAs($reader)->put(route('admin.agentic.memory.update', $memory), [])->assertForbidden();
        $this->post(route('admin.agentic.memory.restore', $memory), [])->assertForbidden();
        $this->get(route('admin.agentic.memory.show', $memory))->assertOk()->assertSee('سجل النسخ');
        $this->assertSame(1, $memory->fresh()->revision);
    }
}
