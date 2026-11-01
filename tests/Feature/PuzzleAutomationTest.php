<?php

namespace Tests\Feature;

use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\GptImageResult;
use App\Models\AdminActivityLog;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderPreview;
use App\Models\OrderProcessEvent;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PuzzleGeneration;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\GptImageClient;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.assign', 'orders.assignment.manage'])->pluck('id'));
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
        $this->get(route('admin.orders.show', $order))->assertOk()->assertSee('أوتوميشن البازل')->assertSee('فتح الصورة المحفوظة للمراجعة');
    }
}
