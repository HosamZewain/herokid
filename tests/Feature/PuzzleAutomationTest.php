<?php

namespace Tests\Feature;

use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\GptImageResult;
use App\Exceptions\GptImageException;
use App\Jobs\TickPuzzleAutomation;
use App\Models\AdminActivityLog;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderPreview;
use App\Models\OrderProcessEvent;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\GptImageClient;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use App\Services\ProductAutomation\PuzzleAutomation;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PuzzleAutomationTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        Storage::fake('local');
        Storage::fake('public');
        config(['media.private_disk' => 'local', 'order_process.enabled' => false, 'robodesk.enabled' => false,
            'puzzle_automation.enabled' => true, 'puzzle_automation.after_event_id' => 0]);
        $image = imagecreatetruecolor(1536, 1024);
        ob_start();
        imagepng($image);
        $this->png = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('child-photos/a.png', $this->png);
        Storage::disk('local')->put('child-photos/b.png', $this->png.'different');
        $provider = AiProvider::firstOrCreate(['driver' => 'openai'], ['name' => 'OpenAI', 'display_name' => 'OpenAI']);
        $provider->update(['is_active' => true]);
        $model = $provider->models()->firstOrCreate(['code' => 'gpt-image-2'], ['display_name' => 'Synthetic']);
        $model->update(['is_active' => true, 'generation_capabilities_json' => ['image_editing'], 'estimated_cost_amount' => '.165', 'estimated_cost_currency' => 'USD']);
        app(AiProviderCredentialService::class)->save($provider, 'synthetic-key');
        $this->product = Product::create(['slug' => 'personalized-child-adventure-hdf-puzzle', 'name_ar' => 'بازل اختبار', 'price_cents' => 10000, 'is_active' => true,
            'production_prompt_template' => 'One puzzle for {{child_full_name}}, {{child_age}}. {{child_image_references}}']);
    }

    public function test_finished_uncertain_retry_requires_explicit_ack_and_preserves_receipt_history(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(PuzzleAutomation::class);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('private payload'));
        $service->tick();
        $previous = PuzzleGeneration::sole();
        $oldContext = $previous->context;
        $oldFinished = $previous->finished_at;
        $url = route('admin.orders.products.regenerate', [$order, $order->items()->first()]);
        $payload = ['previous_run_id' => $previous->id, 'confirm' => 1, 'authorize_uncertain_retry' => 1];
        // A stale/live automation owner cannot be bypassed by the new checkbox.
        $this->post($url, $payload)->assertSessionHasErrors('product_generation');
        $this->handoff($order, 'take');
        $this->post($url, ['previous_run_id' => $previous->id, 'confirm' => 1])->assertSessionHasErrors('product_generation');
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $next = PuzzleGeneration::latest('id')->first();
        $this->assertSame('queued', $next->status);
        $this->assertSame($previous->id, $next->previous_run_id);
        $this->assertSame($actor->id, $next->requested_by);
        $previous->refresh();
        $this->assertSame('retry_authorized', $previous->status);
        $this->assertNull($previous->active_item_id);
        $this->assertSame('unknown', $previous->billing_status);
        $this->assertSame('puzzle_result_uncertain', $previous->error_code);
        $this->assertTrue($previous->finished_at->equalTo($oldFinished));
        $this->assertSame($oldContext, array_diff_key($previous->context, ['retry_authorization' => true]));
        $this->assertSame($actor->id, $previous->context['retry_authorization']['actor_id']);
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('puzzle_generations', 2);
        $this->fakeImage();
        $service->tick();
        $this->assertSame('succeeded', $next->fresh()->status);
        $this->handoff($order, 'resume');
        $this->assertDatabaseCount('puzzle_generations', 2);
        $audit = AdminActivityLog::where('action', 'order.product_regeneration_requested')->latest('id')->first();
        $this->assertTrue($audit->properties['uncertain_retry_authorized']);
        Http::assertNothingSent();
    }

    public function test_uncertain_retry_rejects_unfinished_leased_saved_and_cancelled_attempts(): void
    {
        $this->staff();
        $order = $this->order();
        $service = app(PuzzleAutomation::class);
        $service->enroll();
        $previous = PuzzleGeneration::sole();
        $previous->update(['status' => 'uncertain', 'finished_at' => now(), 'lease_until' => null, 'billing_status' => 'unknown']);
        $this->handoff($order, 'take');
        $url = route('admin.orders.products.regenerate', [$order, $order->items()->first()]);
        $payload = ['previous_run_id' => $previous->id, 'confirm' => 1, 'authorize_uncertain_retry' => 1];
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        foreach ([['finished_at' => null], ['lease_until' => now()->subHour()], ['output_path' => 'retained.png'], ['preview_id' => 123]] as $state) {
            $previous->update(array_merge(['finished_at' => now(), 'lease_until' => null, 'output_path' => null, 'preview_id' => null], $state));
            $this->post($url, $payload)->assertSessionHasErrors('product_generation');
            $this->assertSame('uncertain', $previous->fresh()->status);
        }
        $previous->update(['preview_id' => null]);
        // A file written before a failed DB receipt must not be silently discarded.
        $path = 'order-attachments/'.$order->id.'/puzzle-'.$previous->id.'.png';
        Storage::disk('local')->put($path, $this->png);
        $this->post($url, $payload)->assertSessionHasErrors('product_generation');
        $this->assertSame($this->png, Storage::disk('local')->get($path));
        Storage::disk('local')->delete($path);
        $order->updateQuietly(['status' => 'cancelled']);
        $this->post($url, $payload)->assertSessionHasErrors('product_generation');
        $this->assertDatabaseCount('puzzle_generations', 1);
        $this->assertSame('uncertain', $previous->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_uncertain_retry_preflight_failure_does_not_release_old_fence(): void
    {
        $this->staff();
        $order = $this->order();
        app(PuzzleAutomation::class)->enroll();
        $previous = PuzzleGeneration::sole();
        $previous->update(['status' => 'uncertain', 'finished_at' => now(), 'lease_until' => null]);
        $this->handoff($order, 'take');
        $order->updateQuietly(['uploaded_photos' => []]);
        $this->post(route('admin.orders.products.regenerate', [$order, $order->items()->first()]),
            ['previous_run_id' => $previous->id, 'confirm' => 1, 'authorize_uncertain_retry' => 1])->assertSessionHasErrors();
        $this->assertSame('uncertain', $previous->fresh()->status);
        $this->assertSame($previous->order_item_id, $previous->fresh()->active_item_id);
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_product_provider_failure_retains_only_structured_diagnostics_without_retry(): void
    {
        $order = $this->order();
        $response = new \Illuminate\Http\Client\Response(new Response(429, [], '{"error":{"message":"private customer body"}}'));
        $error = new GptImageException('private exception text', 'insufficient_quota', 'req_synthetic', true,
            new RequestException($response));
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow($error);
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        $run = PuzzleGeneration::sole();
        $this->assertSame('uncertain', $run->status);
        $this->assertSame('unknown', $run->billing_status);
        $this->assertSame('puzzle_provider_billing', $run->error_code);
        $this->assertSame('req_synthetic', $run->provider_request_id);
        $this->assertSame(429, $run->context['failure']['http_status']);
        $this->assertStringNotContainsString('private', json_encode($run->context['failure']));
        Http::assertNothingSent();
    }

    public function test_real_client_connection_loss_is_classified_and_never_retried(): void
    {
        $this->order();
        Http::fake(fn () => throw new ConnectionException('private request payload'));
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        $run = PuzzleGeneration::sole();
        $this->assertSame('puzzle_provider_connection', $run->error_code);
        $this->assertSame('uncertain', $run->status);
        $this->assertSame('unknown', $run->billing_status);
        $this->assertStringNotContainsString('private', json_encode($run->context['failure']));
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_real_client_valid_image_still_publishes_once(): void
    {
        $this->order();
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode($this->png)]]], 200, ['x-request-id' => 'req_success'])]);
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        $run = PuzzleGeneration::sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('req_success', $run->provider_request_id);
        Storage::disk('local')->assertExists($run->output_path);
        $this->assertDatabaseCount('order_attachments', 1);
        Http::assertSentCount(1);
    }

    public function test_real_client_invalid_image_retains_provider_receipt(): void
    {
        $this->order();
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('invalid private output')]]], 200, ['x-request-id' => 'req_output'])]);
        app(PuzzleAutomation::class)->tick();
        $run = PuzzleGeneration::sole();
        $this->assertSame('puzzle_provider_output', $run->error_code);
        $this->assertSame('req_output', $run->provider_request_id);
        $this->assertSame('unknown', $run->billing_status);
        $this->assertNull($run->output_path);
        Http::assertSentCount(1);
    }

    public function test_regeneration_prompt_override_is_scoped_to_attempt_and_uses_current_product_photos(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $this->fakeImage();
        $service = app(PuzzleAutomation::class);
        $service->tick();
        $previous = PuzzleGeneration::sole();
        $original = $previous->getAttributes();
        $this->handoff($order, 'take');
        $item = $order->items()->first();
        $template = $item->product->production_prompt_template;
        $custom = 'CUSTOM RED SPACE ROCKET FOR THIS ATTEMPT ONLY';
        $this->post(route('admin.orders.products.regenerate', [$order, $item]), ['previous_run_id' => $previous->id, 'confirm' => 1, 'prompt' => $custom])->assertSessionHasNoErrors();
        $run = PuzzleGeneration::latest('id')->first();
        $this->fakeImage(1, function (GptImageRequest $request) use ($custom) {
            $this->assertStringContainsString($custom, $request->prompt);

            return true;
        });
        $service->generate($run->id);
        $this->assertSame('succeeded', $run->fresh()->status);
        $this->assertSame($custom, $run->context['prompt_override']);
        $this->assertSame($template, $item->product->fresh()->production_prompt_template);
        $this->assertSame($original, $previous->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_regenerating_one_of_three_products_uses_updated_inputs_and_preserves_all_history(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $two = $this->order('مارية', 'b', $order->checkoutGroupKey());
        $three = $this->order('ثالث', 'a', $order->checkoutGroupKey());
        $seen = [];
        $this->fakeImage(4, function (GptImageRequest $request) use (&$seen) {
            $seen[] = $request;

            return true;
        });
        $service = app(PuzzleAutomation::class);
        $service->tick();
        $service->tick();
        $service->tick();
        $oldRuns = PuzzleGeneration::orderBy('id')->get()->map->getAttributes()->all();
        $oldAttachments = OrderAttachment::orderBy('id')->get()->map->getAttributes()->all();
        $oldPreviews = OrderPreview::orderBy('id')->get()->map->getAttributes()->all();
        $this->handoff($order, 'take');
        $item = $order->items()->first();
        $item->update(['personalization_snapshot' => ['child_name' => 'رقية', 'parent_notes' => 'NEW blue butterflies'], 'variant_snapshot' => ['size' => '40x30']]);
        $order->updateQuietly(['uploaded_photos' => ['child-photos/b.png']]);
        $previous = PuzzleGeneration::where('order_item_id', $item->id)->sole();
        $url = route('admin.orders.products.regenerate', [$order, $item]);
        $this->post($url, ['previous_run_id' => $previous->id, 'confirm' => 1])->assertSessionHasNoErrors();
        $child = PuzzleGeneration::latest('id')->first();
        $this->assertSame(2, $child->attempt_number);
        $this->assertSame($actor->id, $child->requested_by);
        $this->assertTrue(app(AutomationAssignment::class)->owns($order));
        $this->post($url, ['previous_run_id' => $previous->id, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('puzzle_generations', 4);
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'resume'])->assertSessionHasErrors('assignment');
        $service->tick();
        $service->tick();
        $this->assertSame('succeeded', $child->fresh()->status);
        $this->assertNull($child->fresh()->active_item_id);
        $this->assertSame(4, count($seen));
        $this->assertStringContainsString('NEW blue butterflies', $seen[3]->prompt);
        $this->assertStringContainsString('40x30', $seen[3]->prompt);
        $this->assertSame(['data:image/png;base64,'.base64_encode($this->png.'different')], $seen[3]->inputImages);
        $this->assertSame($oldRuns, PuzzleGeneration::whereKey(array_column($oldRuns, 'id'))->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($oldAttachments, OrderAttachment::whereKey(array_column($oldAttachments, 'id'))->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($oldPreviews, OrderPreview::whereKey(array_column($oldPreviews, 'id'))->orderBy('id')->get()->map->getAttributes()->all());
        foreach (OrderAttachment::all() as $attachment) {
            $this->assertSame($this->png, Storage::disk('local')->get($attachment->path));
        }
        $this->assertSame($order->id, OrderPreview::find($child->fresh()->preview_id)->order_id);
        $this->assertSame($child->unit_key, OrderAttachment::find($child->fresh()->attachment_id)->production_unit_key);
        $this->assertSame(4, OrderPreview::count());
        $this->assertTrue(AutomationHandoff::held($order->checkoutGroupKey()));
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
        $this->assertSame('new_confirmed', $order->fresh()->status);
        $this->assertSame(0, $order->fresh()->paid_amount_cents);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'order.product_regeneration_requested', 'user_id' => $actor->id]);
        $this->assertSame(3, AdminActivityLog::where('action', 'automation.puzzle_progress')->get()->filter(fn ($log) => ($log->properties['run_id'] ?? null) === $child->id && $log->user_id === null)->count());
        // Replaying an old successful form is still a no-op after completion.
        $this->post($url, ['previous_run_id' => $previous->id, 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('puzzle_generations', 4);
        // Only the latest attempt's snapshot participates in a later manual return.
        $this->handoff($order, 'resume');
        $service->tick();
        $this->assertDatabaseCount('puzzle_generations', 4);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    private function regenerationFixture(): array
    {
        $actor = $this->staff();
        $order = $this->order();
        $this->fakeImage();
        $service = app(PuzzleAutomation::class);
        $service->tick();
        $previous = PuzzleGeneration::sole();
        $this->handoff($order, 'take');
        $run = $service->regenerate($order, $order->items()->first(), $actor, $previous->id);

        return [$order, $run, $service, $actor];
    }

    public function test_changes_after_regeneration_is_queued_block_without_provider_and_allow_explicit_fresh_attempt(): void
    {
        [$order, $run, $service, $actor] = $this->regenerationFixture();
        $item = $order->items()->first();
        $item->update(['title' => 'Changed while queued']);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $service->tick();
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame('puzzle_inputs_changed', $run->fresh()->error_code);
        $this->assertNull($run->fresh()->active_item_id);
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'resume'])->assertSessionHasErrors('assignment');
        $this->assertSame('blocked', $run->fresh()->status);
        $fresh = $service->regenerate($order, $item, $actor, $run->id);
        $this->assertSame(3, $fresh->attempt_number);
        $this->fakeImage();
        $service->tick();
        $this->assertSame('succeeded', $fresh->fresh()->status);
        $this->assertDatabaseCount('order_previews', 2);
    }

    public function test_late_input_change_retains_regeneration_output_for_review_without_replacing_preview(): void
    {
        [$order, $run, $service] = $this->regenerationFixture();
        $this->fakeImage(1, function () use ($order) {
            $order->items()->first()->update(['title' => 'Changed during provider call']);

            return true;
        });
        $service->tick();
        $service->tick();
        $this->assertSame('review', $run->fresh()->status);
        $this->assertSame('puzzle_inputs_changed', $run->fresh()->error_code);
        Storage::disk('local')->assertExists($run->fresh()->output_path);
        $this->assertDatabaseCount('order_previews', 1);
        $this->assertDatabaseCount('order_attachments', 1);
    }

    public function test_output_or_handoff_change_blocks_regeneration_before_provider(): void
    {
        [$order, $run, $service] = $this->regenerationFixture();
        OrderPreview::first()->update(['file_path' => 'manual-replacement.png']);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $service->tick();
        $this->assertSame('puzzle_existing_output_changed', $run->fresh()->error_code);
        $run->refresh()->update(['status' => 'queued']);
        DB::table('order_automation_holds')->where('checkout_key', $order->checkoutGroupKey())->update(['fingerprint' => 'changed-hold']);
        $service->tick();
        $this->assertSame('puzzle_manual_handoff_changed', $run->fresh()->error_code);
        $this->assertDatabaseCount('order_previews', 1);
    }

    public function test_regeneration_timeout_is_not_retried_and_preserves_old_picture(): void
    {
        [$order, $run, $service, $actor] = $this->regenerationFixture();
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('uncertain provider result'));
        $service->tick();
        $service->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        $this->assertNotNull($run->fresh()->active_item_id);
        $this->assertDatabaseCount('order_previews', 1);
        // The existing explicit manual hold resumes ownership when this worker finishes.
        // Unknown billing still prevents regeneration; the old preview remains untouched.
        $this->assertTrue(AutomationHandoff::held($order->checkoutGroupKey()));
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
        $this->post(route('admin.orders.products.regenerate', [$order, $order->items()->first()]), ['previous_run_id' => $run->id, 'confirm' => 1])->assertSessionHasErrors('product_generation');
        $this->assertDatabaseCount('puzzle_generations', 2);
    }

    public function test_regeneration_endpoint_enforces_permissions_item_scope_confirmation_and_shipping_stage(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $this->fakeImage();
        app(PuzzleAutomation::class)->tick();
        $item = $order->items()->first();
        $previous = PuzzleGeneration::sole();
        $url = route('admin.orders.products.regenerate', [$order, $item]);
        $this->post($url, ['previous_run_id' => $previous->id])->assertSessionHasErrors('confirm');
        $other = $this->order();
        $this->post(route('admin.orders.products.regenerate', [$other, $item]), ['previous_run_id' => $previous->id, 'confirm' => 1])->assertNotFound();
        $this->post($url, ['previous_run_id' => 999999, 'confirm' => 1])->assertStatus(422);
        $actor->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $actor->unsetRelation('permissions');
        $this->post($url, ['previous_run_id' => $previous->id, 'confirm' => 1])->assertForbidden();
        $this->staff();
        $order->updateQuietly(['shipping_status' => 'shipped']);
        $this->post($url, ['previous_run_id' => $previous->id, 'confirm' => 1])->assertSessionHasErrors('product_generation');
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_product_regeneration_control_shows_exact_item_and_current_attempt(): void
    {
        $this->staff();
        $order = $this->order();
        $this->fakeImage();
        app(PuzzleAutomation::class)->tick();
        $item = $order->items()->first();
        $this->get(route('admin.orders.products.production', [$order, $item]))->assertOk()
            ->assertSee('إعادة التوليد')->assertSee(route('admin.orders.products.regenerate', [$order, $item]), false)
            ->assertSee('name="previous_run_id" value="'.PuzzleGeneration::sole()->id.'"', false);
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.assign', 'orders.assignment.manage', 'orders.automation.control', 'orders.view_all', 'orders.production_prompt.manage', 'orders.photos.view'])->pluck('id'));
        $this->actingAs($user);

        return $user;
    }

    private function handoff(Order $order, string $action): void
    {
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => $action])->assertSessionHasNoErrors();
    }

    public function test_edited_confirmed_product_checkout_returns_with_new_mug_and_no_old_confirmation_replay(): void
    {
        $this->staff();
        $puzzle = $this->order();
        // Confirmation happened manually during the hold; no status event was emitted.
        OrderProcessEvent::query()->delete();
        $puzzle->updateQuietly(['status' => 'new']);
        $event = RoboDeskIntegrationEvent::create(['event_id' => (string) Str::uuid(), 'deduplication_key' => 'test-return',
            'direction' => 'outbound', 'event_type' => 'order.pending_confirmation', 'checkout_group_key' => $puzzle->checkoutGroupKey(),
            'order_id' => $puzzle->id, 'status' => 'succeeded', 'attempts' => 1, 'payload' => ['confirmation' => ['eligible_at_creation' => true]],
            'response_payload' => ['message_id' => 'synthetic-wamid']]);
        $this->handoff($puzzle, 'take');
        $puzzle->updateQuietly(['status' => 'new_confirmed']);
        $mug = Product::create(['slug' => 'personalized-child-family-photo-mug', 'name_ar' => 'مج اختبار', 'price_cents' => 10000,
            'production_prompt_template' => 'MUG ART for {{child_full_name}} {{child_image_references}}']);
        $other = $this->order('مارية', 'b', $puzzle->checkoutGroupKey());
        $other->items()->first()->update(['product_id' => $mug->id, 'title' => 'مج مارية']);
        OrderProcessEvent::query()->delete();
        $this->handoff($puzzle, 'resume');
        $this->assertFalse(AutomationHandoff::held($puzzle->checkoutGroupKey()));
        $this->assertSame('cancelled', $event->fresh()->status);
        $this->assertSame(1, $event->fresh()->attempts);
        $this->assertSame('synthetic-wamid', $event->fresh()->response_payload['message_id']);
        $this->assertSame('automation_resumed', OrderProcessEvent::sole()->type);
        $this->assertTrue(app(AutomationAssignment::class)->owns($puzzle));
        $seen = [];
        $this->fakeImage(2, function (GptImageRequest $request) use (&$seen) {
            $seen[] = $request;

            return true;
        });
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        $this->assertDatabaseCount('puzzle_generations', 2);
        $this->assertSame(2, PuzzleGeneration::where('status', 'succeeded')->count());
        $this->assertStringContainsString('MUG ART for مارية', $seen[1]->prompt);
        $this->assertNotSame($seen[0]->inputImages, $seen[1]->inputImages);
        foreach (PuzzleGeneration::all() as $run) {
            $this->assertSame($run->order_id, OrderPreview::findOrFail($run->preview_id)->order_id);
            $this->assertSame($run->unit_key, OrderAttachment::findOrFail($run->attachment_id)->production_unit_key);
        }
        $this->assertSame(0, $puzzle->fresh()->paid_amount_cents);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'automation.products_reevaluated', 'user_id' => null]);
        $this->post(route('admin.orders.groups.assignment.automation', $puzzle), ['action' => 'resume'])->assertStatus(422);
        $this->assertDatabaseCount('order_process_events', 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_explicit_return_refreshes_only_unstarted_product_inputs(): void
    {
        $this->staff();
        $order = $this->order();
        $service = app(PuzzleAutomation::class);
        $service->enroll();
        $before = PuzzleGeneration::sole()->context['fingerprint'];
        $this->handoff($order, 'take');
        $order->updateQuietly(['uploaded_photos' => ['child-photos/b.png']]);
        $this->handoff($order, 'resume');
        $this->assertNotSame($before, PuzzleGeneration::sole()->context['fingerprint']);
        $this->fakeImage(1, function (GptImageRequest $request) {
            $this->assertSame(['data:image/png;base64,'.base64_encode($this->png.'different')], $request->inputImages);

            return true;
        });
        $service->tick();
        $service->tick();
        $this->assertDatabaseCount('puzzle_generations', 1);
        $this->assertSame('succeeded', PuzzleGeneration::sole()->status);
    }

    public function test_return_after_addition_preserves_completed_product_output(): void
    {
        $this->staff();
        $one = $this->order();
        $this->fakeImage(2);
        $service = app(PuzzleAutomation::class);
        $service->tick();
        $old = PuzzleGeneration::sole()->getAttributes();
        $this->handoff($one, 'take');
        $this->order('مارية', 'b', $one->checkoutGroupKey());
        $this->handoff($one, 'resume');
        $service->tick();
        $service->tick();
        $this->assertSame($old, PuzzleGeneration::find($old['id'])->getAttributes());
        $this->assertSame(2, PuzzleGeneration::where('status', 'succeeded')->count());
    }

    public function test_changed_completed_product_keeps_hold_and_never_retries_provider(): void
    {
        $this->staff();
        $order = $this->order();
        $this->fakeImage();
        app(PuzzleAutomation::class)->tick();
        $before = PuzzleGeneration::sole()->getAttributes();
        $this->handoff($order, 'take');
        $order->updateQuietly(['uploaded_photos' => ['child-photos/b.png']]);
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'resume'])->assertSessionHasErrors('assignment');
        $this->assertTrue(AutomationHandoff::held($order->checkoutGroupKey()));
        $this->assertSame($before, PuzzleGeneration::sole()->getAttributes());
        app(PuzzleAutomation::class)->tick();
    }

    public function test_scheduler_only_dispatches_one_job_and_worker_generates_without_blocking_scheduler(): void
    {
        $this->order();
        $this->fakeImage();
        $this->artisan('puzzle-automation:tick')->assertSuccessful();
        $this->artisan('puzzle-automation:tick')->assertSuccessful();
        Queue::assertPushed(TickPuzzleAutomation::class, 1);
        $this->assertDatabaseCount('puzzle_generations', 0);
        $job = Queue::pushed(TickPuzzleAutomation::class)->first();
        $this->assertSame('order-process-ai', $job->connection);
        $this->assertSame('order-process-ai', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertLessThan(300, $job->timeout);
        $job->handle(app(PuzzleAutomation::class));
        $job->handle(app(PuzzleAutomation::class));
        $this->assertSame('succeeded', PuzzleGeneration::sole()->status);
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'puzzle-automation:tick'));
        $this->assertNotNull($event);
        $this->assertFalse($event->runInBackground);
    }

    public function test_disabled_puzzle_scheduler_dispatches_nothing(): void
    {
        config(['puzzle_automation.enabled' => false]);
        $this->artisan('puzzle-automation:tick')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    private function order(string $name = 'رقية', string $photo = 'a', ?string $key = null): Order
    {
        $order = Order::create(['order_number' => 'TEST-'.Str::random(8), 'checkout_group_key' => $key ?: (string) Str::uuid(),
            'parent_name' => 'اختبار', 'child_name' => $name, 'child_age' => 7, 'child_gender' => 'girl', 'status' => 'new_confirmed',
            'uploaded_photos' => ['child-photos/'.$photo.'.png'], 'delivery_details' => []]);
        $order->items()->create(['item_type' => 'product', 'product_id' => $this->product->id, 'title' => 'بازل '.$name, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000,
            'variant_snapshot' => ['size' => '30x20'], 'personalization_snapshot' => ['child_name' => $name, 'parent_notes' => 'forest']]);
        OrderProcessEvent::create(['event_key' => (string) Str::uuid(), 'type' => 'status_entered', 'order_id' => $order->id, 'checkout_key' => $order->checkoutGroupKey(),
            'data' => ['from' => 'new', 'status' => 'new_confirmed'], 'status' => 'processed']);

        return $order;
    }

    private function fakeImage(int $times = 1, ?callable $check = null): void
    {
        $mock = $this->mock(GptImageClient::class)->shouldReceive('generate')->times($times);
        if ($check) {
            $mock->withArgs($check);
        }
        $mock->andReturn(new GptImageResult($this->png, 'png', 'image/png', 'synthetic-request', [], []));
    }

    public function test_future_confirmation_generates_once_with_private_photos_and_independent_preview(): void
    {
        $order = $this->order();
        $this->fakeImage(1, function (GptImageRequest $request) {
            $this->assertSame(1, $request->maxAttempts);
            $this->assertSame('high', $request->quality);
            $this->assertStringContainsString('رقية', $request->prompt);
            $this->assertStringContainsString('30x20', $request->prompt);
            $this->assertStringNotContainsString('signature=', $request->prompt);
            $this->assertSame(['data:image/png;base64,'.base64_encode($this->png)], $request->inputImages);

            return true;
        });
        $service = app(PuzzleAutomation::class);
        $service->tick();
        $service->tick();
        $service->generate(PuzzleGeneration::first()->id);
        $run = PuzzleGeneration::first();
        $this->assertSame('succeeded', $run->status);
        $attachment = OrderAttachment::findOrFail($run->attachment_id);
        $preview = OrderPreview::findOrFail($run->preview_id);
        $this->assertSame($order->id, $attachment->order_id);
        $this->assertSame($run->unit_key, $preview->production_unit_key);
        $this->assertNotSame($attachment->path, $preview->file_path);
        $this->assertSame($this->png, Storage::disk('local')->get($attachment->path));
        $this->assertSame($this->png, Storage::disk('local')->get($preview->file_path));
        $this->assertSame('new_confirmed', $order->fresh()->status);
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
        $logs = AdminActivityLog::where('action', 'automation.puzzle_progress')->get();
        $this->assertCount(3, $logs);
        $this->assertTrue($logs->every(fn ($l) => $l->user_id === null && $l->properties['actor_name'] === 'Automation'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_multi_child_checkout_keeps_each_unit_and_photo_separate(): void
    {
        $one = $this->order();
        $two = $this->order('مارية', 'b', $one->checkoutGroupKey());
        $seen = [];
        $this->fakeImage(2, function (GptImageRequest $request) use (&$seen) {
            $seen[] = $request;

            return true;
        });
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        $this->assertDatabaseCount('puzzle_generations', 2);
        $this->assertStringContainsString('رقية', $seen[0]->prompt);
        $this->assertStringContainsString('مارية', $seen[1]->prompt);
        $this->assertNotSame($seen[0]->inputImages, $seen[1]->inputImages);
        foreach (PuzzleGeneration::all() as $run) {
            $this->assertSame($run->order_id, OrderPreview::find($run->preview_id)->order_id);
        }
    }

    public function test_boundary_excludes_old_confirmations_and_disabled_calls_do_nothing(): void
    {
        $old = $this->order();
        config(['puzzle_automation.after_event_id' => OrderProcessEvent::max('id')]);
        $new = $this->order();
        $this->fakeImage();
        app(PuzzleAutomation::class)->tick();
        $this->assertSame($new->id, PuzzleGeneration::sole()->order_id);
        config(['puzzle_automation.enabled' => false]);
        $this->order();
        app(PuzzleAutomation::class)->tick();
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_uncertain_call_is_never_retried_and_keeps_automation_ownership(): void
    {
        $order = $this->order();
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('secret provider payload'));
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        $run = PuzzleGeneration::sole();
        $this->assertSame('uncertain', $run->status);
        $this->assertSame('unknown', $run->billing_status);
        $this->assertSame('puzzle_result_uncertain', $run->error_code);
        $this->assertTrue(app(AutomationAssignment::class)->owns($order));
        $this->assertDatabaseCount('order_previews', 0);
    }

    public function test_finished_uncertain_product_can_be_taken_and_cancelled_without_retry_or_history_changes(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        $service = app(PuzzleAutomation::class);
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andThrow(new \RuntimeException('uncertain result'));
        $service->tick();
        $run = PuzzleGeneration::sole();
        $original = $run->getAttributes();
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->lease_until);
        $this->assertTrue(app(AutomationAssignment::class)->owns($order));

        $this->handoff($order, 'take');
        $this->assertTrue(AutomationHandoff::held($order->checkoutGroupKey()));
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
        $this->assertDatabaseCount('order_group_assignments', 0);
        $audit = AdminActivityLog::where('action', 'order.automation_taken_over')->sole();
        $this->assertSame([$run->id], $audit->properties['uncertain_product_run_ids']);
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'resume'])->assertSessionHasErrors('assignment');
        $this->assertTrue(AutomationHandoff::held($order->checkoutGroupKey()));
        $this->post(route('admin.orders.products.regenerate', [$order, $order->items()->first()]), ['previous_run_id' => $run->id, 'confirm' => 1])->assertSessionHasErrors();
        // Clear the previous redirect's flashed validation errors before testing cancellation.
        session()->forget('errors');
        $this->patch(route('admin.orders.groups.workflow-statuses', $order), [
            'status' => 'cancelled', 'payment_status' => 'unpaid',
            'printing_status' => 'not_started', 'shipping_status' => 'not_ready',
            'admin_notes' => 'Customer requested cancellation',
        ])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertEquals(0, $order->fresh()->paid_amount_cents);
        $service->tick();
        $service->generate($run->id);
        $this->assertSame($original, $run->fresh()->getAttributes());
        $this->assertDatabaseCount('puzzle_generations', 1);
        $this->assertDatabaseCount('order_previews', 0);
        Http::assertNothingSent();
    }

    public function test_explicit_take_releases_finished_uncertain_manual_generation_only(): void
    {
        $actor = $this->staff();
        $order = $this->order();
        app(PuzzleAutomation::class)->enroll();
        $this->handoff($order, 'take');
        $run = PuzzleGeneration::sole();
        $run->update(['requested_by' => $actor->id, 'status' => 'processing']);
        $this->assertTrue(app(AutomationAssignment::class)->owns($order));
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'take'])->assertSessionHasErrors('assignment');
        $run->update(['status' => 'uncertain', 'finished_at' => now(), 'lease_until' => null, 'billing_status' => 'unknown']);
        $original = $run->fresh()->getAttributes();
        session()->forget('errors');
        $this->handoff($order, 'take');
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
        $this->assertSame($original, $run->fresh()->getAttributes());
    }

    public function test_uncertain_product_without_worker_completion_still_blocks_takeover(): void
    {
        $this->staff();
        $order = $this->order();
        app(PuzzleAutomation::class)->enroll();
        $run = PuzzleGeneration::sole();
        foreach ([
            ['finished_at' => null, 'lease_until' => null],
            ['finished_at' => now(), 'lease_until' => now()->addMinute()],
            ['finished_at' => now(), 'lease_until' => now()->subMinute()],
        ] as $workerState) {
            $run->update(['status' => 'uncertain'] + $workerState);
            $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'take'])->assertSessionHasErrors('assignment');
            $this->assertFalse(AutomationHandoff::held($order->checkoutGroupKey()));
            $this->assertTrue(app(AutomationAssignment::class)->owns($order));
        }
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_finished_uncertainty_does_not_allow_takeover_of_another_active_product_in_checkout(): void
    {
        $this->staff();
        $order = $this->order();
        $other = $this->order('مارية', 'b', $order->checkoutGroupKey());
        app(PuzzleAutomation::class)->enroll();
        PuzzleGeneration::where('order_id', $order->id)->first()->update(['status' => 'uncertain', 'finished_at' => now(), 'lease_until' => null]);
        PuzzleGeneration::where('order_id', $other->id)->first()->update(['status' => 'processing']);
        $this->post(route('admin.orders.groups.assignment.automation', $order), ['action' => 'take'])->assertSessionHasErrors('assignment');
        $this->assertFalse(AutomationHandoff::held($order->checkoutGroupKey()));
    }

    public function test_changed_inputs_and_missing_photos_block_without_provider_call(): void
    {
        $order = $this->order();
        $service = app(PuzzleAutomation::class);
        $service->enroll();
        $order->items()->first()->update(['variant_snapshot' => ['size' => '15x10']]);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $service->tick();
        $this->assertSame('puzzle_inputs_changed', PuzzleGeneration::sole()->error_code);
        $other = $this->order();
        $other->update(['uploaded_photos' => []]);
        $service->tick();
        $this->assertSame('puzzle_photos_required', PuzzleGeneration::latest('id')->first()->error_code);
    }

    public function test_manual_hold_pauses_queued_run_and_changed_inputs_do_not_resume(): void
    {
        $order = $this->order();
        $service = app(PuzzleAutomation::class);
        $service->enroll();
        DB::table('order_automation_holds')->insert(['checkout_key' => $order->checkoutGroupKey(), 'user_id' => User::factory()->create()->id, 'fingerprint' => 'synthetic', 'runs' => '{}', 'confirmations' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        $service->tick();
        $this->assertSame('queued', PuzzleGeneration::sole()->status);
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
        DB::table('order_automation_holds')->delete();
        $order->update(['uploaded_photos' => ['child-photos/b.png']]);
        $service->tick();
        $this->assertSame('blocked', PuzzleGeneration::sole()->status);
    }

    public function test_low_resolution_is_retained_for_review_without_publishing_or_retry(): void
    {
        $this->order();
        $i = imagecreatetruecolor(3, 2);
        ob_start();
        imagepng($i);
        $tiny = ob_get_clean();
        $this->mock(GptImageClient::class)->shouldReceive('generate')->once()->andReturn(new GptImageResult($tiny, 'png', 'image/png', 'synthetic', [], []));
        app(PuzzleAutomation::class)->tick();
        app(PuzzleAutomation::class)->tick();
        $run = PuzzleGeneration::sole();
        $this->assertSame('review', $run->status);
        $this->assertSame('puzzle_output_quality', $run->error_code);
        Storage::disk('local')->assertExists($run->output_path);
        $this->assertDatabaseCount('order_previews', 0);
    }

    public function test_existing_output_and_cancellation_are_not_regenerated(): void
    {
        $order = $this->order();
        OrderPreview::create(['order_id' => $order->id, 'file_path' => 'prior.png', 'disk' => 'local']);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        app(PuzzleAutomation::class)->tick();
        $this->assertSame('puzzle_existing_output', PuzzleGeneration::sole()->error_code);
        $cancelled = $this->order();
        $cancelled->update(['status' => 'cancelled']);
        app(PuzzleAutomation::class)->tick();
        $this->assertDatabaseCount('puzzle_generations', 1);
    }

    public function test_processed_story_event_and_later_story_status_do_not_lose_addon(): void
    {
        $order = $this->order();
        $order->items()->first()->update(['item_type' => 'product_add_on']);
        $order->update(['status' => 'waiting_customer_identity']);
        $this->fakeImage();
        app(PuzzleAutomation::class)->tick();
        $this->assertSame('succeeded', PuzzleGeneration::sole()->status);
        $this->assertSame('waiting_customer_identity', $order->fresh()->status);
    }

    public function test_expired_processing_is_marked_uncertain_without_provider_retry(): void
    {
        $this->order();
        app(PuzzleAutomation::class)->enroll();
        PuzzleGeneration::sole()->update(['status' => 'processing', 'lease_until' => now()->subMinute()]);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        app(PuzzleAutomation::class)->tick();
        $this->assertSame('uncertain', PuzzleGeneration::sole()->status);
    }

    public function test_reentrant_worker_cannot_submit_same_unit_twice_and_late_input_change_is_not_published(): void
    {
        $order = $this->order();
        $this->fakeImage(1, function () use ($order) {
            app(PuzzleAutomation::class)->generate(PuzzleGeneration::sole()->id);
            $order->items()->first()->update(['title' => 'Changed after submission']);

            return true;
        });
        app(PuzzleAutomation::class)->tick();
        $this->assertSame('review', PuzzleGeneration::sole()->status);
        $this->assertSame('puzzle_inputs_changed', PuzzleGeneration::sole()->error_code);
        $this->assertDatabaseCount('order_previews', 0);
    }

    public function test_open_confirmation_is_owned_before_enrollment_and_other_products_are_excluded(): void
    {
        $order = $this->order();
        $this->assertTrue(app(AutomationAssignment::class)->owns($order));
        $this->product->update(['slug' => 'puzzle-book-level-1']);
        $this->mock(GptImageClient::class)->shouldNotReceive('generate');
        app(PuzzleAutomation::class)->tick();
        $this->assertDatabaseCount('puzzle_generations', 0);
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
    }

    public function test_inflight_puzzle_blocks_takeover_but_queued_puzzle_can_be_taken(): void
    {
        $order = $this->order();
        app(PuzzleAutomation::class)->enroll();
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.assign', 'orders.assignment.manage', 'orders.automation.control', 'orders.view_all', 'orders.production_prompt.manage', 'orders.photos.view'])->pluck('id'));
        $this->actingAs($actor);
        $run = PuzzleGeneration::sole();
        $run->update(['status' => 'processing']);
        try {
            app(AutomationHandoff::class)->take($order, $actor, request());
            $this->fail('An in-flight puzzle must prevent takeover');
        } catch (ValidationException) {
            $this->assertFalse(AutomationHandoff::held($order->checkoutGroupKey()));
        }
        $run->update(['status' => 'queued']);
        app(AutomationHandoff::class)->take($order, $actor, request());
        $this->assertTrue(AutomationHandoff::held($order->checkoutGroupKey()));
        $this->assertFalse(app(AutomationAssignment::class)->owns($order));
    }

    public function test_private_review_image_requires_permission_and_matching_order(): void
    {
        $order = $this->order();
        $other = $this->order();
        app(PuzzleAutomation::class)->enroll();
        $run = PuzzleGeneration::first();
        $run->update(['status' => 'review', 'disk' => 'local', 'output_path' => 'child-photos/a.png']);
        $url = route('admin.orders.puzzle-image', [$order, $run]);
        $this->get($url)->assertRedirect();
        $actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $actor->permissions()->detach();
        $this->actingAs($actor)->get($url)->assertForbidden();
        $actor->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $actor->unsetRelation('permissions');
        $this->actingAs($actor)->get($url)->assertOk();
        $this->get(route('admin.orders.puzzle-image', [$other, $run]))->assertNotFound();
        $this->get(route('admin.orders.show', $order))->assertOk()->assertSee('أوتوميشن صور المنتجات')->assertSee('فتح الصورة المحفوظة للمراجعة');
    }
}
