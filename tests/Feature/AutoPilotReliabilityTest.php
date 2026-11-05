<?php

namespace Tests\Feature;

use App\Exceptions\BookAdmissionException;
use App\Models\AdminActivityLog;
use App\Models\AiProductionExecution;
use App\Models\AiProvider;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\ContextObserver;
use App\Services\Agentic\DecisionOrchestrator;
use App\Services\Agentic\ExecutionFailureReason;
use App\Services\Agentic\ExecutionMonitor;
use App\Services\Agentic\ProductionExecutor;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\GptImageClient;
use App\Services\CodexScenes\BookPageService;
use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AgenticFixtures;
use Tests\Support\AutoPilotExecutionFixtures;
use Tests\TestCase;

class AutoPilotReliabilityTest extends TestCase
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
        Storage::disk('local')->put('synthetic/photo.png', ob_get_clean());
        imagedestroy($image);
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'Synthetic', 'display_name' => 'Synthetic']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'Synthetic']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['image_editing'], 'estimated_cost_amount' => '.1', 'estimated_cost_currency' => 'USD']);
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-only');
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $this->enableSyntheticExecution();
    }

    public static function unsupportedStages(): array
    {
        return [
            ['preview_uploaded', 'not_started', 'not_ready', 'PRODUCT_ORDER_STAGE_REQUIRES_REVIEW'],
            ['revision_requested', 'not_started', 'not_ready', 'PRODUCT_ORDER_STAGE_REQUIRES_REVIEW'],
            ['generating', 'not_started', 'not_ready', 'PRODUCT_ORDER_STAGE_REQUIRES_REVIEW'],
            ['new_confirmed', 'in_progress', 'not_ready', 'PRODUCT_PRINTING_STAGE_REQUIRES_REVIEW'],
            ['costomer_confirmed', 'not_started', 'ready', 'PRODUCT_SHIPPING_STAGE_REQUIRES_REVIEW'],
        ];
    }

    #[DataProvider('unsupportedStages')]
    public function test_planner_and_native_image_admission_agree_on_unsupported_stages(string $status, string $printing, string $shipping, string $reason): void
    {
        $state = $this->observedCheckout('stage');
        $order = Order::findOrFail($state->representative_id);
        $order->update(['status' => $status, 'printing_status' => $printing, 'shipping_status' => $shipping]);
        $state = app(ContextObserver::class)->observe('stage');
        $target = app(CapabilityRegistry::class)->targets($state->snapshot)[0];
        $this->assertSame('human_required', $target['decision']);
        $this->assertSame($reason, $target['reason_code']);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        try {
            app(PuzzleAutomation::class)->admitForAutoPilot($order->items()->sole(), $target['target_id'], static fn () => null);
            $this->fail('Native admission accepted an unsupported stage.');
        } catch (RuntimeException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function blocked(string $key = 'blocked', string $failure = 'Book inputs changed after approval')
    {
        $state = $this->observedCheckout($key);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->partialMock(PuzzleAutomation::class)->shouldReceive('admitForAutoPilot')->once()->andThrow(new RuntimeException($failure));
        $this->assertSame(0, app(ProductionExecutor::class)->dispatchPass()['admitted']);

        return [$state->fresh(), AiProductionExecution::latest('id')->first()];
    }

    private function operator(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.view_all', 'orders.update', 'orders.automation.control',
            'order_automation.view', 'order_automation.manage', 'order_automation.execute'])->pluck('id'));
        $this->actingAs($user->fresh());

        return $user->fresh();
    }

    private function recheckUrl($state, $receipt): string
    {
        return route('admin.orders.autopilot-executions.recheck', [$state->representative_id, $receipt->id]);
    }

    public function test_preflight_failure_has_safe_cause_and_remains_visible_after_evaluation_is_covered(): void
    {
        [$state, $receipt] = $this->blocked();
        $this->assertSame('covered', $state->status);
        $this->assertSame('BOOK_APPROVAL_INPUTS_CHANGED', $receipt->reason_code);
        $this->assertSame(0, $state->ready_target_count);
        $before = $state->getRawOriginal();
        $monitor = app(ExecutionMonitor::class)->snapshot('blocked');
        $this->assertSame(1, $monitor['intervention_count']);
        $this->assertTrue($monitor['rows'][0]['recheck_available']);
        $this->assertSame($before, $state->fresh()->getRawOriginal());
        $this->operator();
        $this->get(route('admin.agentic.show', $state->representative_id))->assertOk()->assertSee('إعادة فحص الخطوة')->assertSee('تغيّرت بيانات الكتاب');
        $this->assertDatabaseCount('ai_agent_runs', 1);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_explicit_recheck_reopens_only_failed_target_once_without_calling_model_or_executor(): void
    {
        [$state, $receipt] = $this->blocked();
        $this->operator();
        $before = $receipt->getRawOriginal();
        $attempts = DB::table('ai_runtime_states')->value('attempts');
        $url = $this->recheckUrl($state, $receipt);
        $this->post($url, ['context_version' => $state->context_version])->assertRedirect(route('admin.orders.show', $state->representative_id));
        $state->refresh();
        $this->assertSame('partial', $state->status);
        $this->assertSame([], $state->covered_targets);
        $this->assertSame(1, $state->ready_target_count);
        $this->assertSame($attempts, DB::table('ai_runtime_states')->value('attempts'));
        $this->assertSame($before, $receipt->fresh()->getRawOriginal());
        $this->post($url, ['context_version' => $state->context_version])->assertStatus(409);
        $this->assertSame(1, AdminActivityLog::where('action', 'autopilot.execution_recheck_requested')->count());
        $this->assertDatabaseCount('ai_agent_runs', 1);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->app->forgetInstance(PuzzleAutomation::class);
        $this->assertSame(1, app(DecisionOrchestrator::class)->evaluatePass($state->id)['calls']);
        $this->assertSame(1, app(ProductionExecutor::class)->dispatchPass()['admitted']);
        $this->assertDatabaseCount('puzzle_generations', 1);
        $this->assertSame(0, app(ExecutionMonitor::class)->snapshot('blocked')['intervention_count']);
        $this->assertSame($before, $receipt->fresh()->getRawOriginal());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_recheck_cannot_override_pause_input_change_permission_or_wrong_checkout(): void
    {
        [$state, $receipt] = $this->blocked();
        $user = $this->operator();
        $url = $this->recheckUrl($state, $receipt);
        DB::table('order_automation_controls')->insert(['checkout_key' => 'blocked', 'state' => 'paused', 'version' => 2]);
        $this->post($url, ['context_version' => $state->context_version])->assertStatus(409);
        DB::table('order_automation_controls')->where('checkout_key', 'blocked')->update(['state' => 'running']);
        $other = $this->observedCheckout('other');
        $this->post(route('admin.orders.autopilot-executions.recheck', [$other->representative_id, $receipt->id]), ['context_version' => $state->context_version])->assertNotFound();
        $this->post($url, ['context_version' => $state->context_version + 1])->assertStatus(409);
        Order::findOrFail($state->representative_id)->update(['child_name' => 'Changed synthetic brief']);
        $this->post($url, ['context_version' => $state->context_version])->assertStatus(409);
        $user->permissions()->detach(Permission::where('key', 'order_automation.execute')->value('id'));
        $this->actingAs($user->fresh())->post($url, ['context_version' => $state->context_version])->assertForbidden();
        $this->assertSame($state->covered_targets, $state->fresh()->covered_targets);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_unknown_exception_text_is_never_exposed_or_stored_as_a_reason(): void
    {
        [$state, $receipt] = $this->blocked('private', 'secret customer payload https://private.invalid');
        $this->assertSame('EXECUTOR_PREFLIGHT_FAILED', $receipt->reason_code);
        $this->assertStringNotContainsString('secret', json_encode($receipt->toArray()));
        $this->operator();
        $this->get(route('admin.agentic.show', $state->representative_id))->assertOk()->assertDontSee('private.invalid');
        Http::assertNothingSent();
    }

    public function test_rechecking_one_unit_preserves_covered_sibling_and_both_failure_receipts(): void
    {
        $state = $this->observedCheckout('siblings', 2);
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->partialMock(PuzzleAutomation::class)->shouldReceive('admitForAutoPilot')->twice()->andThrow(new RuntimeException('Book pages incomplete'));
        app(ProductionExecutor::class)->dispatchPass();
        $receipts = AiProductionExecution::orderBy('id')->get();
        $this->assertCount(2, $receipts);
        $before = $receipts->map(fn ($r) => $r->getRawOriginal())->all();
        $this->operator();
        $this->post($this->recheckUrl($state, $receipts[0]), ['context_version' => $state->context_version])->assertRedirect();
        $this->assertSame([$receipts[1]->target_id], $state->fresh()->covered_targets);
        $this->assertSame(1, $state->fresh()->ready_target_count);
        $this->assertSame($before, AiProductionExecution::orderBy('id')->get()->map(fn ($r) => $r->getRawOriginal())->all());
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_recheck_does_not_bypass_assignment_activation_or_global_pause(): void
    {
        [$state, $receipt] = $this->blocked();
        $user = $this->operator();
        $url = $this->recheckUrl($state, $receipt);
        $data = ['context_version' => $state->context_version];
        $user->permissions()->detach(Permission::where('key', 'orders.view_all')->value('id'));
        $this->actingAs($user->fresh())->post($url, $data)->assertForbidden();
        $user->permissions()->syncWithoutDetaching(Permission::where('key', 'orders.view_all')->pluck('id'));
        $this->actingAs($user->fresh());
        config(['agentic.execution.enabled' => false]);
        $this->post($url, $data)->assertStatus(409);
        config(['agentic.execution.enabled' => true]);
        DB::table('ai_production_controls')->update(['paused' => true]);
        $this->post($url, $data)->assertStatus(409);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertSame($state->covered_targets, $state->fresh()->covered_targets);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_old_receipt_cannot_reopen_a_later_context_even_if_its_fingerprint_returned_to_same_value(): void
    {
        [$state, $receipt] = $this->blocked();
        $this->operator();
        $state->update(['context_version' => $state->context_version + 1]);
        $this->post($this->recheckUrl($state, $receipt), ['context_version' => $state->context_version])->assertStatus(409);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertSame($state->covered_targets, $state->fresh()->covered_targets);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_recheck_never_replays_admitted_work_or_uncertain_provider_failures(): void
    {
        [$state, $receipt] = $this->blocked();
        $this->operator();
        $url = $this->recheckUrl($state, $receipt);
        foreach (['puzzle_result_uncertain', 'puzzle_provider_rejected', 'puzzle_provider_connection'] as $code) {
            DB::table('ai_production_executions')->where('id', $receipt->id)->update(['reason_code' => $code]);
            $this->post($url, ['context_version' => $state->context_version])->assertStatus(409);
        }
        DB::table('ai_production_executions')->where('id', $receipt->id)->update(['status' => 'admitted',
            'reason_code' => 'EXISTING_EXECUTOR_ACCEPTED', 'executor_type' => 'puzzle', 'executor_id' => '999']);
        $this->post($url, ['context_version' => $state->context_version])->assertStatus(409);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertSame($state->covered_targets, $state->fresh()->covered_targets);
        $this->assertSame(0, AdminActivityLog::where('action', 'autopilot.execution_recheck_requested')->count());
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_assigned_staff_can_recheck_only_their_checkout_under_assigned_visibility(): void
    {
        $user = $this->operator();
        $user->permissions()->detach(Permission::where('key', 'orders.view_all')->value('id'));
        Setting::updateOrCreate(['key' => 'orders_visibility'], ['value' => 'assigned']);
        $state = $this->observedCheckout('blocked');
        DB::table('order_group_assignments')->insert(['checkout_group_key' => 'blocked',
            'assigned_to_user_id' => $user->id, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $state = app(ContextObserver::class)->observe('blocked');
        app(DecisionOrchestrator::class)->evaluatePass($state->id);
        $this->partialMock(PuzzleAutomation::class)->shouldReceive('admitForAutoPilot')->once()->andThrow(new RuntimeException('Book pages incomplete'));
        app(ProductionExecutor::class)->dispatchPass();
        $receipt = AiProductionExecution::sole();
        $this->actingAs($user->fresh())->post($this->recheckUrl($state, $receipt), ['context_version' => $state->context_version])->assertRedirect();
        $this->assertSame([], $state->fresh()->covered_targets);
        $this->assertSame($user->id, AdminActivityLog::where('action', 'autopilot.execution_recheck_requested')->sole()->user_id);
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_product_planner_respects_codex_checkout_ownership_like_the_native_image_executor(): void
    {
        $state = $this->observedCheckout('codex-owner');
        $order = Order::findOrFail($state->representative_id);
        CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'checkout_key' => 'codex-owner',
            'production_kind' => 'story', 'status' => 'running', 'previous_status' => 'new_confirmed', 'active_slot' => 1,
            'fingerprint' => str_repeat('a', 64), 'snapshot' => [], 'scenes' => []]);
        $state = app(ContextObserver::class)->observe('codex-owner');
        $this->assertFalse($state->snapshot['automation_owned']);
        $this->assertTrue($state->snapshot['image_automation_owned']);
        $target = app(CapabilityRegistry::class)->targets($state->snapshot)[0];
        $this->assertSame('wait', $target['decision']);
        $this->assertSame('EXISTING_AUTOMATION_OWNER', $target['reason_code']);
        try {
            app(PuzzleAutomation::class)->admitForAutoPilot($order->items()->sole(), $target['target_id'], static fn () => null);
            $this->fail('Image admission bypassed active Codex work.');
        } catch (RuntimeException $e) {
            $this->assertSame('EXISTING_AUTOMATION_OWNER', $e->getMessage());
        }
        $this->assertDatabaseCount('puzzle_generations', 0);
        Http::assertNothingSent();
    }

    public function test_book_planner_uses_native_stage_eligibility_and_native_failure_retains_specific_safe_cause(): void
    {
        $state = $this->observedCheckout('book-stage');
        $order = Order::findOrFail($state->representative_id);
        $order->update(['printing_status' => 'ready']);
        $books = $this->partialMock(BookPageService::class);
        $books->shouldReceive('workerReady')->andReturn(true);
        config(['product_review.enabled' => true, 'product_review.book_pages_enabled' => true]);
        $context = app(ContextObserver::class)->observe('book-stage')->snapshot;
        $context['payment']['positive_recorded'] = true;
        $context['runtime']['reviews_enabled'] = $context['runtime']['book_pages_enabled'] = true;
        $context['units'][0] = array_replace($context['units'][0], ['capability' => 'book_interior', 'cover_approved' => true,
            'linked_story_complete' => true, 'book_admission_eligible' => $books->statusEligible($order)]);
        $target = app(CapabilityRegistry::class)->targets($context)[0];
        $this->assertSame('human_required', $target['decision']);
        $this->assertSame('BOOK_ORDER_STAGE_REQUIRES_REVIEW', $target['reason_code']);
        try {
            $books->start($order->items()->sole(), null);
            $this->fail('Book admission bypassed its native stage gate.');
        } catch (BookAdmissionException $error) {
            $this->assertSame('Book not eligible', $error->getMessage());
            $this->assertSame($target['reason_code'], ExecutionFailureReason::from($error));
        }
        $this->assertDatabaseCount('codex_scene_runs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }
}
