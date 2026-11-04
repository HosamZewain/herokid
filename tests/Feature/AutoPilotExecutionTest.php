<?php

namespace Tests\Feature;

use App\Models\AiAgentRun;
use App\Models\AiOrderDecision;
use App\Models\AiProductionExecution;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderProcessEvent;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\Agentic\AutoPilotAudit;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\ExecutionMonitor;
use App\Services\Agentic\MemoryService;
use App\Services\Agentic\OrderActivityProjection;
use App\Services\Agentic\ProductionExecutor;
use App\Services\Agentic\Providers\CodexSdkProvider;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\GptImageClient;
use App\Services\Orders\OrderActivityTimelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AgenticFixtures;
use Tests\Support\AutoPilotExecutionFixtures;
use Tests\TestCase;

class AutoPilotExecutionTest extends TestCase
{
    use AgenticFixtures, AutoPilotExecutionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        config(['media.private_disk' => 'local', 'order_process.enabled' => false,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 999999]);
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('synthetic/photo.png', $png);
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'Synthetic', 'display_name' => 'Synthetic']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'Synthetic']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['image_editing'], 'estimated_cost_amount' => '.1', 'estimated_cost_currency' => 'USD']);
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-only');
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $this->enableSyntheticExecution();
    }

    protected function propose(string $key = 'synthetic', int $units = 1)
    {
        $state = $this->observedCheckout($key, $units);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);

        return $state->fresh();
    }

    public function test_admission_is_once_only_and_uses_existing_run_without_calling_provider(): void
    {
        $state = $this->propose();
        $result = app(ProductionExecutor::class)->dispatchPass();
        $this->assertSame(1, $result['admitted']);
        $run = PuzzleGeneration::sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame($state->representative_id, $run->order_id);
        $this->assertSame(1, (int) DB::table('ai_production_controls')->value('admissions'));
        $this->assertSame(1, (int) DB::table('order_automation_controls')->value('version'));
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        $this->assertDatabaseHas('admin_activity_logs', ['subject_id' => $run->order_id, 'action' => 'autopilot.decision']);
        $this->assertDatabaseHas('admin_activity_logs', ['subject_id' => $run->order_id, 'action' => 'autopilot.admission']);
        $run->update(['status' => 'processing']);
        $this->assertDatabaseHas('admin_activity_logs', ['subject_id' => $run->order_id, 'action' => 'autopilot.progress']);
        $this->assertSame('processing', app(ExecutionMonitor::class)->snapshot()['rows'][0]['current_status']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_secondary_audit_failure_never_retries_or_reverts_the_native_executor(): void
    {
        $this->propose();
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        Log::spy();
        $this->mock(AutoPilotAudit::class)->shouldReceive('progress')->once()->andThrow(new \RuntimeException('synthetic audit outage'));
        $run = PuzzleGeneration::sole();
        $run->update(['status' => 'processing']);
        $this->assertSame('processing', $run->fresh()->status);
        $this->assertSame('processing', app(ExecutionMonitor::class)->snapshot()['rows'][0]['current_status']);
        Log::shouldHaveReceived('warning')->with('autopilot_progress_audit_failed', ['executor_type' => 'puzzle', 'executor_id' => $run->id])->once();
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_poster_autopilot_admission_preserves_completed_sibling_and_runs_once(): void
    {
        $state = $this->observedCheckout('poster-siblings', 2);
        $orders = Order::where('checkout_group_key', 'poster-siblings')->orderBy('id')->get();
        $finishedItem = $orders[0]->items()->sole();
        $event = OrderProcessEvent::create(['event_key' => 'synthetic-finished-sibling',
            'checkout_key' => 'poster-siblings', 'order_id' => $orders[0]->id, 'type' => 'status_entered',
            'status' => 'processed', 'data' => ['status' => 'new_confirmed']]);
        $finished = PuzzleGeneration::create(['order_id' => $orders[0]->id, 'order_item_id' => $finishedItem->id,
            'checkout_key' => 'poster-siblings', 'event_id' => $event->id, 'unit_key' => 'product:'.$finishedItem->id, 'status' => 'succeeded']);
        $before = $finished->fresh()->getRawOriginal();
        $poster = Product::create(['slug' => 'bostr-mkhss-bsor-altfl-mkas-a4', 'name_ar' => 'Synthetic poster',
            'is_active' => true, 'price_cents' => 100, 'production_prompt_template' => 'One 20x30 poster for {{child_full_name}}']);
        $posterItem = $orders[1]->items()->sole();
        $posterItem->update(['product_id' => $poster->id, 'variant_snapshot' => ['size' => '20x30']]);
        $state = app(ContextObserver::class)->observe('poster-siblings');
        $unit = collect($state->snapshot['units'])->firstWhere('unit_key', 'product:'.$posterItem->id);
        $this->assertTrue($unit['supported']);
        $this->assertSame('single_image', $unit['capability']);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $run = PuzzleGeneration::where('order_item_id', $posterItem->id)->sole();
        $this->assertSame('queued', $run->status);
        $this->assertSame('1024x1536', $run->context['size']);
        $this->assertStringContainsString('Synthetic child 1', $run->context['prompt']);
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertSame($before, $finished->fresh()->getRawOriginal());
        $this->assertDatabaseCount('puzzle_generations', 2);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_disabled_incomplete_or_mock_activation_cannot_dispatch(): void
    {
        $this->propose();
        foreach (['agentic.execution.enabled' => false, 'agentic.execution.daily_limit' => 0, 'agentic.execution.activation_id' => null,
            'agentic.execution.actions' => ['attach_asset'], 'agentic.execution.activated_at' => 'tomorrow', 'agentic.provider' => 'mock'] as $key => $value) {
            $original = config($key);
            config([$key => $value]);
            $this->assertSame('disabled', app(ProductionExecutor::class)->dispatchPass()['status']);
            config([$key => $original]);
        }
        $this->assertDatabaseCount('ai_production_executions', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_offline_authentication_gate_fails_closed(): void
    {
        $this->propose();
        $this->mock(CodexSdkProvider::class)->shouldReceive('health')->andReturn(['ready' => false]);
        $this->assertSame('provider_unavailable', app(ProductionExecutor::class)->dispatchPass()['status']);
        $this->assertDatabaseCount('ai_production_executions', 0);
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_selected_checkout_scope_cannot_admit_a_different_checkout(): void
    {
        $this->propose();
        config(['agentic.execution.all_checkouts' => false, 'agentic.execution.order_ids' => [999999]]);
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertSame('EXECUTION_SCOPE_EXCLUDED', AiProductionExecution::sole()->reason_code);
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_no_replay_of_shadow_proposals_created_before_activation(): void
    {
        config(['agentic.execution.enabled' => false]);
        $this->propose();
        config(['agentic.execution.enabled' => true]);
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertSame('EXECUTION_ATTESTATION_REQUIRED', AiProductionExecution::sole()->reason_code);
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_order_monitor_is_read_only_and_does_not_request_another_decision(): void
    {
        $state = $this->propose();
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $this->actingAs($user)->getJson(route('admin.orders.autopilot-monitor', $state->representative_id))->assertOk()->assertJsonStructure(['html']);
        $this->assertDatabaseCount('ai_agent_runs', 1);
        $this->assertDatabaseCount('ai_production_executions', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_old_shadow_decision_and_forged_auth_metadata_cannot_admit(): void
    {
        $this->propose();
        $run = AiAgentRun::sole();
        $run->update(['provider_metadata' => ['authentication' => 'api_key', 'tools_used' => 0, 'isolation_digest' => str_repeat('a', 64)]]);
        app(ProductionExecutor::class)->dispatchPass();
        $this->assertSame('EXECUTION_ATTESTATION_REQUIRED', AiProductionExecution::sole()->reason_code);
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_pause_and_separate_execution_quota_preserve_pending_decisions(): void
    {
        $this->propose();
        DB::table('ai_production_controls')->update(['paused' => true]);
        $this->assertSame(['paused'], app(ProductionExecutor::class)->dispatchPass()['results']);
        DB::table('ai_production_controls')->update(['paused' => false, 'quota_day' => now('Africa/Cairo')->toDateString(), 'admissions' => 10]);
        $this->assertSame(['quota_deferred'], app(ProductionExecutor::class)->dispatchPass()['results']);
        $this->assertDatabaseCount('ai_production_executions', 0);
        $this->assertSame(1, (int) DB::table('ai_runtime_states')->value('attempts'));
    }

    public function test_explicit_unlimited_execution_continues_beyond_daily_counts_without_repeating_work(): void
    {
        config(['agentic.execution.unlimited' => true, 'agentic.execution.daily_limit' => 0]);
        foreach ([20, 1000] as $used) {
            $this->propose('unlimited-'.$used);
            DB::table('ai_production_controls')->update(['quota_day' => now('Africa/Cairo')->toDateString(), 'admissions' => $used, 'paused' => true]);
            $this->assertSame(['paused'], app(ProductionExecutor::class)->dispatchPass()['results']);
            DB::table('ai_production_controls')->update(['paused' => false]);
            $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
            $this->assertSame($used + 1, (int) DB::table('ai_production_controls')->value('admissions'));
            $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        }
        $this->assertDatabaseCount('ai_agent_runs', 2);
        $this->assertDatabaseCount('puzzle_generations', 2);
        $execution = app(ExecutionMonitor::class)->snapshot();
        $this->assertTrue($execution['enabled']);
        $html = view('admin.agentic._execution', compact('execution'))->render();
        $this->assertStringContainsString('بدون سقف يومي لخطوات التنفيذ', $html);
        $this->assertStringContainsString('1001', $html);
        $this->assertStringNotContainsString('اكتمل حد خطوات التنفيذ اليوم', $html);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unlimited_execution_requires_explicit_boolean_and_preserves_other_activation_gates(): void
    {
        $this->propose();
        foreach (['true', 'false', 1, 'unlimited'] as $invalid) {
            config(['agentic.execution.unlimited' => $invalid]);
            $this->assertSame('disabled', app(ProductionExecutor::class)->dispatchPass()['status']);
        }
        config(['agentic.execution.unlimited' => true, 'agentic.execution.daily_limit' => 0]);
        foreach (['agentic.execution.enabled' => false, 'agentic.execution.activation_id' => null,
            'agentic.execution.actions' => [], 'agentic.execution.all_checkouts' => false] as $key => $value) {
            $original = config($key);
            config([$key => $value]);
            $this->assertSame('disabled', app(ProductionExecutor::class)->dispatchPass()['status']);
            config([$key => $original]);
        }
        $this->assertDatabaseCount('puzzle_generations', 0);
    }

    public function test_expired_decision_requests_one_fresh_evaluation_then_can_admit(): void
    {
        $state = $this->propose();
        $this->travel(16)->minutes();
        app(ProductionExecutor::class)->dispatchPass();
        $this->assertSame('EXECUTION_DECISION_EXPIRED', AiProductionExecution::sole()->reason_code);
        $this->assertSame([], $state->fresh()->covered_targets);
        $this->assertSame('partial', $state->fresh()->status);
        $this->assertSame([], app(ProductionExecutor::class)->dispatchPass()['results']);
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_changes_holds_and_delivery_after_decision_are_rechecked_inside_executor(): void
    {
        foreach (['input', 'hold', 'delivery', 'memory', 'output', 'pause'] as $change) {
            $state = $this->propose($change);
            $order = Order::findOrFail($state->representative_id);
            match ($change) {
                'input' => $order->update(['child_name' => 'Changed synthetic child']),
                'hold' => DB::table('order_automation_holds')->insert(['checkout_key' => $change, 'user_id' => User::factory()->create()->id, 'fingerprint' => 'changed', 'runs' => '[]', 'confirmations' => '[]', 'created_at' => now()]),
                'delivery' => $order->update(['shipping_status' => 'delivered']),
                'memory' => app(MemoryService::class)->save(null, ['title' => 'Synthetic correction', 'guidance' => 'Keep approvals', 'scope_type' => 'global', 'scope_key' => null, 'status' => 'active', 'change_reason' => 'Synthetic test'], null),
                'output' => OrderAttachment::create(['order_id' => $order->id, 'disk' => 'local', 'path' => 'synthetic/existing.png', 'original_name' => 'existing.png', 'mime_type' => 'image/png', 'size' => 1]),
                'pause' => DB::table('order_automation_controls')->insert(['checkout_key' => $change, 'state' => 'paused', 'version' => 1]),
            };
            app(ProductionExecutor::class)->dispatchPass();
            $this->assertSame('EXECUTION_STALE_CONTEXT', AiProductionExecution::latest('id')->first()->reason_code, $change);
        }
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_independent_siblings_wait_for_existing_owner_then_continue_without_repeating_first_unit(): void
    {
        $state = $this->propose('siblings', 2);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        $first = PuzzleGeneration::sole();
        $first->update(['status' => 'succeeded']);
        $state = app(ContextObserver::class)->observe('siblings');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 2);
        $this->assertSame(2, PuzzleGeneration::distinct()->count('order_item_id'));
        $this->assertSame('succeeded', $first->fresh()->status);
    }

    public function test_monitor_counts_native_outcomes_beyond_recent_twenty_even_without_ai(): void
    {
        foreach (range(1, 22) as $i) {
            $this->propose('outcomes-'.$i);
            $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
            PuzzleGeneration::latest('id')->first()->update(['status' => $i === 1 ? 'processing' : ($i === 2 ? 'failed' : 'succeeded')]);
            // The synthetic activation has a bounded cap; reset only its synthetic counter.
            DB::table('ai_production_controls')->update(['admissions' => 0]);
        }
        config(['agentic.evaluate' => false]);
        $execution = app(ExecutionMonitor::class)->snapshot();
        $this->assertSame(22, $execution['admitted']);
        $this->assertSame(1, $execution['outcomes']['running']);
        $this->assertSame(1, $execution['outcomes']['failed']);
        $this->assertSame(20, $execution['outcomes']['production_completed']);
        $this->assertSame(0, $execution['outcomes']['message_accepted']);
        $this->assertSame(1, $execution['active_count']);
        $scoped = app(ExecutionMonitor::class)->snapshot('outcomes-2');
        $this->assertSame(1, $scoped['admitted']);
        $this->assertSame(1, $scoped['outcomes']['failed']);
        $this->assertSame(0, $scoped['outcomes']['production_completed']);
        $html = view('admin.agentic._execution', compact('execution'))->render();
        $this->assertStringContainsString('قبول إرسال الرسالة لا يثبت وصولها للعميل', $html);
        $this->assertDatabaseCount('ai_agent_runs', 22);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_monitor_deduplicates_native_message_acceptance_and_exposes_missing_receipts(): void
    {
        $state = $this->propose('message-outcomes', 3);
        $event = RoboDeskIntegrationEvent::create(['event_id' => (string) Str::uuid(),
            'direction' => 'outbound', 'event_type' => 'synthetic', 'order_id' => $state->representative_id, 'status' => 'succeeded']);
        foreach (AiOrderDecision::orderBy('id')->get() as $i => $decision) {
            AiProductionExecution::create(['decision_id' => $decision->id, 'idempotency_key' => hash('sha256', 'monitor-'.$i),
                'activation_id' => 'synthetic', 'checkout_key' => 'message-outcomes', 'order_id' => $state->representative_id,
                'target_id' => $decision->decision['target_id'], 'action' => 'send_order_confirmation', 'context_fingerprint' => $state->fingerprint,
                'status' => 'admitted', 'executor_type' => $i === 2 ? 'unsupported' : 'confirmation', 'executor_id' => $event->id]);
        }
        $execution = app(ExecutionMonitor::class)->snapshot('message-outcomes');
        $this->assertSame(3, $execution['admitted']);
        $this->assertSame(1, $execution['outcomes']['message_accepted']);
        $this->assertSame(1, $execution['outcomes']['unknown']);
        $this->assertSame(0, $execution['outcomes']['production_completed']);
        $this->assertCount(3, $execution['rows']);
        DB::table('robodesk_integration_events')->where('id', $event->id)->delete();
        $this->assertSame(2, app(ExecutionMonitor::class)->snapshot()['outcomes']['unknown']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_order_page_projection_and_timeline_have_decisions_waits_and_safe_receipts(): void
    {
        $state = $this->propose();
        app(ProductionExecutor::class)->dispatchPass();
        $order = Order::findOrFail($state->representative_id);
        $pilot = app(OrderActivityProjection::class)->forOrder($order);
        $this->assertCount(1, $pilot['history']);
        $timeline = app(OrderActivityTimelineService::class)->forGroup(['orders' => [$order]]);
        $events = $timeline['events']->where('actor', 'HeroKid Auto Pilot');
        $this->assertCount(2, $events);
        $this->assertStringNotContainsString('synthetic-only', $events->toJson());
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', 'order_automation.view'])->pluck('id'));
        $this->actingAs($user)->get(route('admin.agentic.show', $order))->assertOk()->assertSee('سُلّمت للمسار الحالي')->assertSee('في طابور التنفيذ');
        $this->post(route('admin.agentic.pause-execution'))->assertForbidden();
        $user->permissions()->syncWithoutDetaching(Permission::whereIn('key', ['order_automation.manage', 'order_automation.execute'])->pluck('id'));
        $this->actingAs($user->fresh())->post(route('admin.agentic.pause-execution'))->assertRedirect();
        $this->assertTrue((bool) DB::table('ai_production_controls')->value('paused'));
    }
}
