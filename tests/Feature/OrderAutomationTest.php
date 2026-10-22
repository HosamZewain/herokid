<?php

namespace Tests\Feature;

use App\Jobs\AdvanceOrderAutomation;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRun;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\OrderAutomation\WorkflowDefinition;
use App\Services\OrderAutomation\WorkflowImageGateway;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\OrderAutomation\WorkflowRunner;
use App\Services\OrderAutomation\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Order $order;

    private OrderAutomation $workflow;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.private_disk' => 'local', 'order_automation.enabled' => false]);
        $this->employee = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->employee->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.photos.view', 'order_automation.view', 'order_automation.manage', 'order_automation.activate', 'order_automation.execute'])->pluck('id'));
        $story = Story::create(['title' => 'قصة تجريبية', 'slug' => 'workflow-story', 'language' => 'ar', 'gender' => 'both', 'price' => 350, 'active' => true]);
        Storage::disk('local')->put('child-photos/test.png', $this->png());
        $this->order = Order::create(['order_number' => 'WORKFLOW-TEST', 'story_id' => $story->id, 'child_name' => 'طفل اختبار', 'child_age' => 5,
            'status' => 'pending', 'uploaded_photos' => ['child-photos/test.png'], 'delivery_details' => ['phone' => '201000000001']]);
        $this->order->refresh();
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'OpenAI', 'display_name' => 'OpenAI']);
        $provider->update(['is_active' => true]);
        $this->model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'Test image']);
        $this->model->update(['is_active' => true, 'generation_capabilities_json' => ['character_sheet'], 'estimated_cost_amount' => '0.1', 'estimated_cost_currency' => 'USD', 'configuration_json' => ['supports_multiple_references' => true]]);
        $this->workflow = OrderAutomation::firstOrFail();
        $definition = WorkflowDefinition::defaults();
        $definition['model_id'] = $this->model->id;
        // Most tests exercise all optional gates; the shipping default is save-only.
        $definition['steps'] = ['prepare', 'generate', 'save', 'review', 'whatsapp', 'wait'];
        $this->workflow->update(['draft' => $definition]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        $this->workflow->refresh();
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(2, 2));

        return ob_get_clean();
    }

    private function start(bool $dry = false): OrderAutomationRun
    {
        return app(WorkflowService::class)->start($this->workflow, $this->order, $dry, true, $this->employee, (string) Str::uuid());
    }

    private function advance(OrderAutomationRun $run, int $steps = 1): OrderAutomationRun
    {
        for ($i = 0; $i < $steps; $i++) {
            app(WorkflowRunner::class)->advance($run->id);
        }

        return $run->refresh();
    }

    private function gateway(bool $async = false): void
    {
        $bytes = $this->png();
        $model = $this->model;
        $fake = new class($bytes, $model, $async) extends WorkflowImageGateway
        {
            public int $submissions = 0;

            public function __construct(private string $bytes, private AiModel $fakeModel, private bool $async) {}

            public function model(OrderAutomationRun $run): AiModel
            {
                return $this->fakeModel;
            }

            public function submit(OrderAutomationRun $run): array
            {
                $this->submissions++;

                return $this->async ? ['provider_request_id' => 'fal-qa', 'status_url' => 'https://queue.fal.run/qa/status', 'response_url' => 'https://queue.fal.run/qa'] : ['provider_request_id' => 'qa', 'output' => $this->store($run, $this->bytes)];
            }

            public function poll(OrderAutomationRun $run): ?array
            {
                return $this->store($run, $this->bytes);
            }
        };
        $this->app->instance(WorkflowImageGateway::class, $fake);
    }

    public function test_default_disabled_and_activation_is_fail_closed(): void
    {
        $this->assertFalse($this->workflow->enabled);
        $this->assertSame(0, app(WorkflowService::class)->tick());
        $this->assertDatabaseCount('order_automation_runs', 0);
        $this->actingAs($this->employee)->postJson(route('admin.order-automation.activate', $this->workflow), ['confirmed' => true, 'enabled' => true])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_save_only_default_completes_without_whatsapp_and_send_can_be_added_later(): void
    {
        $definition = WorkflowDefinition::defaults();
        $this->assertSame(['prepare', 'generate', 'save', 'wait'], $definition['steps']);
        $definition['model_id'] = $this->model->id;
        $this->workflow->update(['draft' => $definition]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        $this->gateway();
        $run = $this->advance($this->start(), 4);
        $this->assertSame('preview_ready', $run->status);
        $this->assertNull($run->error_code);
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertSame(1, app(WorkflowImageGateway::class)->submissions);
        Http::assertNothingSent();
        config(['order_automation.enabled' => true]);
        app(WorkflowService::class)->activate($this->workflow, true, $this->employee);
        $this->assertTrue($this->workflow->fresh()->enabled);
        $definition['steps'] = ['prepare', 'generate', 'save', 'whatsapp', 'wait'];
        $this->workflow->update(['draft' => $definition]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        $this->assertFalse($this->workflow->fresh()->enabled);
        $this->assertNotContains('whatsapp', $run->fresh()->revision->definition['steps']);
        $this->assertContains('whatsapp', $this->workflow->fresh()->revision->definition['steps']);
        $this->actingAs($this->employee)->postJson(route('admin.order-automation.activate', $this->workflow), ['confirmed' => true, 'enabled' => true])->assertUnprocessable();
    }

    public function test_permissions_and_customer_access(): void
    {
        $this->get(route('admin.order-automation.index'))->assertRedirect();
        $customer = User::factory()->create();
        $this->actingAs($customer)->get(route('admin.order-automation.index'))->assertForbidden();
        $viewer = User::factory()->create(['role' => 'admin']);
        $viewer->permissions()->sync(Permission::where('key', 'order_automation.view')->pluck('id'));
        $this->actingAs($viewer)->get(route('admin.order-automation.index'))->assertOk()->assertDontSee('WORKFLOW-TEST');
        $this->postJson(route('admin.order-automation.create'), ['name' => 'Denied'])->assertForbidden();
        $this->postJson(route('admin.order-automation.trial', $this->workflow), [])->assertForbidden();
    }

    public function test_admin_pages_render_and_reject_stale_edit_and_invalid_steps(): void
    {
        $this->actingAs($this->employee)->get(route('admin.order-automation.edit', $this->workflow))->assertOk()->assertSee('خطوات المسار');
        $url = route('admin.order-automation.save', $this->workflow);
        $this->putJson($url, ['name' => 'Test', 'draft_version' => 99, 'definition' => $this->workflow->draft])->assertConflict();
        $bad = $this->workflow->draft;
        $bad['steps'] = ['whatsapp', 'prepare', 'generate', 'save'];
        $this->putJson($url, ['name' => 'Test', 'draft_version' => 1, 'definition' => $bad])->assertUnprocessable();
        $bad = $this->workflow->draft;
        $bad['prompt'] = '{{api_key}}';
        $this->putJson($url, ['name' => 'Test', 'draft_version' => 1, 'definition' => $bad])->assertUnprocessable();
    }

    public function test_simulation_never_generates_or_attaches_or_changes_payment(): void
    {
        $before = $this->order->getRawOriginal();
        $run = $this->advance($this->start(true));
        $this->assertSame('simulated', $run->status);
        $this->assertCount(6, $run->steps);
        $this->assertSame(0, (int) $run->reserved_microusd);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        $this->assertDatabaseCount('order_attachments', 0);
        $this->assertStringNotContainsString('https://', $run->context['prompt']);
        $this->assertStringNotContainsString('طفل اختبار', DB::table('order_automation_runs')->value('context'));
        Http::assertNothingSent();
    }

    public function test_paid_trial_requires_both_cost_and_processing_confirmation(): void
    {
        $data = ['order_id' => $this->order->id, 'mode' => 'trial', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
        $this->actingAs($this->employee)->postJson(route('admin.order-automation.trial', $this->workflow), $data)->assertUnprocessable();
        $this->assertDatabaseCount('order_automation_runs', 0);
    }

    public function test_live_key_prevents_duplicate_generation_across_workflows(): void
    {
        $one = $this->start();
        $two = $this->start();
        $this->assertSame($one->id, $two->id);
        $other = OrderAutomation::create(['name' => 'Other', 'draft' => $this->workflow->draft]);
        app(WorkflowService::class)->publish($other, 1, $this->employee);
        $three = app(WorkflowService::class)->start($other, $this->order, false, true, $this->employee, (string) Str::uuid());
        $this->assertSame($one->id, $three->id);
        $this->assertDatabaseCount('order_automation_runs', 1);
    }

    public function test_success_attaches_private_preview_without_approval_or_order_status_changes(): void
    {
        $this->gateway();
        $before = $this->order->getRawOriginal();
        $payments = $this->order->paymentEvents()->get()->toArray();
        $run = $this->advance($this->start(), 4);
        $this->assertSame('review', $run->status);
        $this->assertDatabaseCount('order_attachments', 1);
        $attachment = OrderAttachment::sole();
        $this->assertSame('story:'.$this->order->id, $attachment->production_unit_key);
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        $this->assertSame($payments, $this->order->paymentEvents()->get()->toArray());
        $this->advance($run, 3);
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertSame(1, app(WorkflowImageGateway::class)->submissions);
        $this->actingAs($this->employee)->get(route('admin.order-automation.runs.show', $run))->assertOk();
        $this->get(route('admin.order-automation.runs.image', $run))->assertOk()->assertHeader('Content-Type', 'image/png');
        Http::assertNothingSent();
    }

    public function test_changed_child_data_blocks_late_result_before_attachment(): void
    {
        $this->gateway();
        $run = $this->advance($this->start(), 2);
        $this->order->update(['child_name' => 'طفل آخر']);
        $this->advance($run);
        $this->assertSame('inputs_changed', $run->error_code);
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_missing_photos_wait_without_cost_then_resume_when_ready(): void
    {
        $this->order->update(['uploaded_photos' => []]);
        $run = $this->advance($this->start());
        $this->assertSame('waiting_inputs', $run->status);
        $this->assertSame(0, (int) $run->reserved_microusd);
        $this->order->update(['uploaded_photos' => ['child-photos/test.png']]);
        $this->advance($run);
        $this->assertSame(1, $run->step_index);
    }

    public function test_cancelled_order_does_not_generate(): void
    {
        $this->gateway();
        $run = $this->advance($this->start());
        $this->order->update(['status' => 'cancelled']);
        $this->advance($run);
        $this->assertSame('order_ineligible', $run->error_code);
        $this->assertSame(0, app(WorkflowImageGateway::class)->submissions);
    }

    public function test_unknown_submission_is_never_resubmitted(): void
    {
        $this->gateway();
        $run = $this->advance($this->start());
        $run->update(['status' => 'generating', 'lease_until' => now()->subMinute(), 'reserved_microusd' => 1000000]);
        $this->advance($run, 3);
        $this->assertSame('uncertain', $run->status);
        $this->assertSame(0, app(WorkflowImageGateway::class)->submissions);
        $this->actingAs($this->employee)->postJson(route('admin.order-automation.runs.action', $run), ['action' => 'resume', 'reason' => 'Do not rebill', 'confirmed' => true])->assertUnprocessable();
    }

    public function test_async_provider_poll_continues_without_second_submission(): void
    {
        $this->gateway(true);
        $run = $this->advance($this->start(), 2);
        $this->assertSame('polling', $run->status);
        $this->advance($run, 3);
        $this->assertSame('review', $run->status);
        $this->assertSame(1, app(WorkflowImageGateway::class)->submissions);
    }

    public function test_duplicate_worker_lease_is_noop(): void
    {
        $this->gateway();
        $run = $this->start();
        $run->update(['lease_token' => (string) Str::uuid(), 'lease_until' => now()->addMinutes(10)]);
        $this->advance($run);
        $this->assertSame(0, $run->step_index);
        $this->assertSame(0, app(WorkflowImageGateway::class)->submissions);
    }

    public function test_daily_budget_is_reserved_and_blocks_other_runs(): void
    {
        $this->gateway();
        $def = $this->workflow->draft;
        $def['daily_budget_usd'] = 1;
        $this->workflow->update(['draft' => $def]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        $one = $this->advance($this->start(), 2);
        $other = $this->order->replicate();
        $other->order_number = 'WORKFLOW-OTHER';
        $other->save();
        $two = app(WorkflowService::class)->start($this->workflow, $other, false, true, $this->employee, (string) Str::uuid());
        $this->advance($two, 2);
        $this->assertSame('budget_limit', $two->error_code);
        $this->assertSame(1, app(WorkflowImageGateway::class)->submissions);
    }

    public function test_snapshot_is_immutable_after_draft_edit(): void
    {
        $run = $this->start(true);
        $old = $run->revision->definition;
        $def = $this->workflow->draft;
        $def['prompt'] = 'Different prompt';
        $this->workflow->update(['draft' => $def]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        $this->assertSame($old, $run->fresh()->revision->definition);
    }

    public function test_only_orders_created_after_enable_are_admitted_and_global_disable_stops_new_calls(): void
    {
        config(['order_automation.enabled' => true]);
        $def = $this->workflow->draft;
        $def['steps'] = ['prepare', 'generate', 'save', 'review', 'wait'];
        $this->workflow->update(['draft' => $def]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        app(WorkflowService::class)->activate($this->workflow, true, $this->employee);
        $this->assertSame(0, app(WorkflowService::class)->tick());
        $new = $this->order->replicate();
        $new->order_number = 'NEW-WORKFLOW';
        $new->save();
        $this->assertSame(1, app(WorkflowService::class)->tick());
        $this->assertSame(0, app(WorkflowService::class)->tick());
        $run = OrderAutomationRun::sole();
        config(['order_automation.enabled' => false]);
        $this->advance($run);
        $this->assertSame(0, $run->step_index);
        Http::assertNothingSent();
    }

    public function test_whatsapp_is_blocked_and_retry_does_not_repeat_generation(): void
    {
        $this->gateway();
        $run = $this->advance($this->start(), 4);
        config(['order_automation.enabled' => true]);
        $this->workflow->update(['enabled' => true]);
        $run->update(['trial' => false]);
        $this->actingAs($this->employee)->post(route('admin.order-automation.runs.action', $run), ['action' => 'review', 'reason' => 'Preview checked', 'confirmed' => true])->assertRedirect();
        $this->advance($run, 2);
        $this->assertSame('delivery_not_configured', $run->error_code);
        $this->post(route('admin.order-automation.runs.action', $run), ['action' => 'resume', 'reason' => 'Retry send only', 'confirmed' => true])->assertRedirect();
        $this->advance($run);
        $this->assertSame(1, app(WorkflowImageGateway::class)->submissions);
        Http::assertNothingSent();
    }

    public function test_trial_cannot_send_even_after_employee_review(): void
    {
        $this->gateway();
        $run = $this->advance($this->start(), 4);
        $this->actingAs($this->employee)->post(route('admin.order-automation.runs.action', $run), ['action' => 'review', 'reason' => 'Preview checked', 'confirmed' => true])->assertRedirect();
        $this->advance($run, 2);
        $this->assertSame('trial_delivery_prohibited', $run->error_code);
        $this->postJson(route('admin.order-automation.runs.action', $run), ['action' => 'resume', 'reason' => 'Cannot send', 'confirmed' => true])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_recovery_of_accepted_request_polls_without_resubmission(): void
    {
        $this->gateway(true);
        $run = $this->advance($this->start(), 2);
        $run->update(['status' => 'blocked', 'error_code' => 'provider_unavailable']);
        $this->actingAs($this->employee)->post(route('admin.order-automation.runs.action', $run), ['action' => 'resume', 'reason' => 'Provider restored', 'confirmed' => true])->assertRedirect();
        $this->advance($run, 2);
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertSame(1, app(WorkflowImageGateway::class)->submissions);
    }

    public function test_queue_reservation_exceeds_worker_timeout_and_no_photo_permission_denies_preview(): void
    {
        $job = new AdvanceOrderAutomation(1);
        $this->assertSame('order-automation', $job->connection);
        $this->assertGreaterThan($job->timeout, config('queue.connections.order-automation.retry_after'));
        $this->gateway();
        $run = $this->advance($this->start(), 3);
        $this->employee->permissions()->detach(Permission::where('key', 'orders.photos.view')->value('id'));
        $this->actingAs($this->employee->fresh())->get(route('admin.order-automation.runs.image', $run))->assertForbidden();
    }

    public function test_real_openai_adapter_uses_private_photo_bytes_one_attempt_and_configured_parameters(): void
    {
        app(AiProviderCredentialService::class)->save($this->model->provider, 'qa-secret-never-live');
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($this->png())]]], 200, ['x-request-id' => 'qa-id'])]);
        $run = $this->advance($this->start(), 3);
        $this->assertNull($run->error_code);
        $this->assertDatabaseCount('order_attachments', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/images/edits'
            && str_contains($request->body(), '1536x1024') && str_contains($request->body(), 'image[]')
            && ! str_contains($request->body(), 'signature='));
        $this->assertStringNotContainsString('qa-secret', json_encode($run->context));
    }

    public function test_provider_http_failure_never_automatically_reposts(): void
    {
        app(AiProviderCredentialService::class)->save($this->model->provider, 'qa-secret-never-live');
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'timeout']], 500)]);
        $run = $this->advance($this->start(), 5);
        $this->assertSame('uncertain', $run->status);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_fal_adapter_submits_once_and_retrieves_validated_cdn_output(): void
    {
        $provider = AiProvider::firstOrCreate(['driver' => 'fal'], ['name' => 'Fal', 'display_name' => 'Fal']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'fal-ai/flux-kontext/dev'], ['display_name' => 'Fal QA']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['character_sheet'], 'estimated_cost_amount' => 0.03, 'estimated_cost_currency' => 'USD', 'configuration_json' => ['supports_multiple_references' => false, 'requires_image_url' => true]]);
        app(AiProviderCredentialService::class)->save($provider, 'qa-fal-secret');
        $def = $this->workflow->draft;
        $def['model_id'] = $model->id;
        $def['photo_limit'] = 1;
        $this->workflow->update(['draft' => $def]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        Http::fake([
            'https://queue.fal.run/fal-ai/flux-kontext/dev' => Http::response(['request_id' => 'qa', 'status_url' => 'https://queue.fal.run/qa/status', 'response_url' => 'https://queue.fal.run/qa/result']),
            'https://queue.fal.run/qa/status' => Http::response(['status' => 'COMPLETED']),
            'https://queue.fal.run/qa/result' => Http::response(['images' => [['url' => 'https://v3.fal.media/qa.png']]]),
            'https://v3.fal.media/qa.png' => Http::response($this->png(), 200, ['Content-Type' => 'image/png']),
        ]);
        $run = $this->advance($this->start(), 4);
        $this->assertNull($run->error_code);
        $this->assertDatabaseCount('order_attachments', 1);
        Http::assertSentCount(4);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['resolution_mode'] === '3:2' && str_starts_with($request['image_url'], 'data:image/png;base64,'));
    }

    public function test_model_does_not_silently_drop_multiple_reference_photos(): void
    {
        $this->model->update(['configuration_json' => ['supports_multiple_references' => false]]);
        $this->actingAs($this->employee)->postJson(route('admin.order-automation.publish', $this->workflow), ['draft_version' => 1, 'confirmed' => true])->assertUnprocessable();
    }

    public function test_publishing_disables_active_workflow_and_excludes_arbitrary_model_metadata(): void
    {
        $this->workflow->update(['enabled' => true]);
        $this->model->update(['configuration_json' => ['supports_multiple_references' => true, 'api_key' => 'must-not-snapshot']]);
        app(WorkflowService::class)->publish($this->workflow, 1, $this->employee);
        $this->assertFalse($this->workflow->fresh()->enabled);
        $this->assertArrayNotHasKey('api_key', $this->workflow->fresh()->revision->definition['model_snapshot']['configuration_json']);
    }

    public function test_order_specific_identity_prompt_is_preserved_without_managed_photo_urls(): void
    {
        $this->order->childIdentityPromptOverride()->create(['prompt_text' => 'KEEP THIS ORDER IDENTITY BRIEF', 'created_by' => $this->employee->id]);
        $run = $this->advance($this->start(true));
        $this->assertStringContainsString('KEEP THIS ORDER IDENTITY BRIEF', $run->context['prompt']);
        $this->assertStringContainsString('طفل اختبار', $run->context['prompt']);
        $this->assertStringNotContainsString('signature=', $run->context['prompt']);
        Http::assertNothingSent();
    }

    public function test_changed_provider_driver_cannot_silently_change_published_execution(): void
    {
        $this->model->provider->update(['driver' => 'unknown-provider']);
        $run = $this->advance($this->start(), 2);
        $this->assertSame('provider_unavailable', $run->error_code);
        $this->assertEquals(0, $run->reserved_microusd);
        Http::assertNothingSent();
    }

    public function test_approved_identity_and_other_story_do_not_match(): void
    {
        $this->order->child_identity_approved_attempt_id = 123;
        $this->assertFalse(app(WorkflowInputs::class)->eligible($this->order, $this->workflow->draft));
        $this->order->child_identity_approved_attempt_id = null;
        $def = $this->workflow->draft;
        $def['story_ids'] = [999];
        $this->assertFalse(app(WorkflowInputs::class)->eligible($this->order, $def));
    }

    public function test_photo_byte_change_is_detected_and_revoked_actor_cannot_generate(): void
    {
        $this->gateway();
        $run = $this->advance($this->start());
        ob_start();
        imagepng(imagecreatetruecolor(3, 3));
        $changed = ob_get_clean();
        Storage::disk('local')->put('child-photos/test.png', $changed);
        $this->advance($run);
        $this->assertSame('inputs_changed', $run->error_code);
        $this->assertSame(0, app(WorkflowImageGateway::class)->submissions);
        $this->employee->update(['is_active' => false]);
        $run->update(['status' => 'queued']);
        $this->advance($run);
        $this->assertSame('actor_revoked', $run->error_code);
    }

    public function test_cancel_during_submission_prevents_late_result_attachment(): void
    {
        $model = $this->model;
        $fake = new class($model) extends WorkflowImageGateway
        {
            public function __construct(private AiModel $fakeModel) {}

            public function model(OrderAutomationRun $run): AiModel
            {
                return $this->fakeModel;
            }

            public function submit(OrderAutomationRun $run): array
            {
                $run->fresh()->update(['status' => 'cancelled', 'lease_token' => null, 'lease_until' => null]);

                return ['provider_request_id' => 'qa', 'output' => ['path' => 'should-not-attach']];
            }
        };
        $this->app->instance(WorkflowImageGateway::class, $fake);
        $run = $this->advance($this->start(), 4);
        $this->assertSame('cancelled', $run->status);
        $this->assertDatabaseCount('order_attachments', 0);
    }
}
