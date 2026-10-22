<?php

namespace Tests\Feature;

use App\Jobs\GenerateStudioCover;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\StudioCoverGeneration;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\RemoteStudio\StudioCoverService;
use App\Services\RemoteStudio\StudioSessionService;
use App\Services\RemoteStudio\StudioSettingsSchema;
use App\Services\RemoteStudio\StudioSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RemoteStudioCoverTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private string $token;

    private int $revision;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.connections.database.retry_after' => 360]);
        Storage::fake('studio_private');
        Queue::fake();
        Http::preventStrayRequests();
        $this->employee = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true, 'password' => 'isolated-test-password']);
        $this->employee->permissions()->sync(Permission::whereIn('key', ['studio.access', 'studio.settings.read', 'studio.covers.generate', 'orders.view', 'orders.photos.view'])->pluck('id'));
        $source = $this->employee->createToken('Existing policy', ['agent', 'agent:orders.read', 'agent:catalog.stories'], now()->addDays(2));
        DB::table('studio_employee_grants')->insert(['user_id' => $this->employee->id, 'agent_token_id' => $source->accessToken->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->token = $this->postJson('/api/studio/auth/login', ['login' => $this->employee->email, 'password' => 'isolated-test-password'])->assertOk()->json('data.token');
        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        $provider->update(['is_active' => true, 'default_max_retries' => 5]);
        app(AiProviderCredentialService::class)->save($provider, 'sk-isolated-fake-key-never-live');
        $provider->models()->where('code', 'gpt-image-2')->update(['is_active' => true]);
        $settings = StudioSettingsSchema::defaults();
        $settings['openai_cover']['enabled'] = true;
        $this->revision = app(StudioSettingsService::class)->publish($settings, 0, $this->employee, 'Cover test')->id;
        $story = Story::create(['title' => 'قصة النجوم', 'slug' => 'studio-cover-stars', 'language' => 'ar', 'gender' => 'both', 'price' => 350, 'active' => true]);
        $this->order = Order::create(['order_number' => 'HK-STUDIO-COVER-TEST', 'checkout_group_key' => 'STUDIO-COVER-TEST', 'story_id' => $story->id, 'child_name' => 'طفل الاختبار', 'child_age' => 7, 'child_gender' => 'boy', 'status' => 'new', 'language' => 'ar', 'uploaded_photos' => []]);
    }

    private function api(string $method, string $url, array $body = [], ?string $token = null): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withToken($token ?? $this->token);

        return $this->json($method, $url, $body);
    }

    private function payload(): array
    {
        return ['request_id' => (string) Str::uuid(), 'settings_revision' => $this->revision, 'order_number' => $this->order->order_number,
            'production_unit_key' => 'story:'.$this->order->id, 'kind' => 'story', 'confirmed_billable' => true,
            'reference_image' => UploadedFile::fake()->image('child.png', 20, 20), 'context' => ['title' => 'قصة النجوم', 'dedication' => 'إلى طفل الاختبار']];
    }

    public function test_employee_can_load_existing_scoped_order_without_acquiring_or_changing_it(): void
    {
        $this->api('GET', '/api/agent/studio/orders/'.$this->order->order_number)->assertOk()->assertJsonPath('production_stories.0.child.name', 'طفل الاختبار');
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertSame('new', $this->order->fresh()->status);
    }

    private function useDirectEmployeeSession(): void
    {
        // Test-only removal: no production grant is migrated or deleted.
        DB::table('studio_employee_grants')->where('user_id', $this->employee->id)->delete();
        $this->employee->update(['agent_api_enabled' => false]);
        $this->token = app(StudioSessionService::class)->issue($this->employee->fresh())['token'];
    }

    public function test_unlinked_employee_can_read_story_and_product_without_agent_switch(): void
    {
        $this->useDirectEmployeeSession();
        Storage::fake('local');
        Storage::disk('local')->put('orders/studio/child.jpg', 'isolated-child-photo');
        $this->order->update(['uploaded_photos' => ['orders/studio/child.jpg']]);
        $product = Product::create(['name_ar' => 'منتج اختبار', 'slug' => 'direct-studio-product', 'price_cents' => 10000, 'is_active' => true, 'personalization_mode' => 'none', 'production_prompt_template' => 'Produce {{product_name}}']);
        $this->order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000, 'personalization_mode' => 'none']);
        $this->api('GET', '/api/agent/studio/connection')->assertOk();
        $this->api('GET', '/api/agent/studio/orders/'.$this->order->order_number)->assertOk()
            ->assertJsonPath('production_stories.0.child.name', 'طفل الاختبار')->assertJsonCount(2, 'production_units');
        $this->api('GET', '/api/agent/checkouts/'.$this->order->checkoutReference->short_reference.'/production-context')->assertOk();
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertSame('new', $this->order->fresh()->status);
        $this->assertFalse($this->employee->fresh()->agent_api_enabled);
    }

    public function test_direct_employee_cover_job_honors_current_permissions_without_agent_switch(): void
    {
        $this->useDirectEmployeeSession();
        $image = UploadedFile::fake()->image('result.png', 24, 36);
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode(file_get_contents($image->getRealPath()))]]], 200)]);
        $id = $this->api('POST', '/api/studio/covers', $this->payload())->assertAccepted()->json('data.id');
        (new GenerateStudioCover($id))->handle(app(StudioCoverService::class));
        $this->api('GET', '/api/studio/covers/'.$id)->assertOk()->assertJsonPath('data.status', 'completed');
        Http::assertSentCount(1);
        $this->employee->permissions()->detach(Permission::where('key', 'orders.photos.view')->value('id'));
        $this->api('POST', '/api/studio/covers', $this->payload())->assertForbidden();
    }

    public function test_generation_is_idempotent_private_scoped_and_never_attaches_to_or_updates_orders(): void
    {
        $payload = $this->payload();
        $response = $this->api('POST', '/api/studio/covers', $payload)->assertAccepted()->assertJsonPath('data.status', 'queued');
        $id = $response->json('data.id');
        $this->api('POST', '/api/studio/covers', $payload)->assertAccepted()->assertJsonPath('data.id', $id);
        Queue::assertPushed(GenerateStudioCover::class, 1);
        $changed = $payload;
        $changed['context']['title'] = 'Changed';
        $this->api('POST', '/api/studio/covers', $changed)->assertConflict();
        $this->api('POST', '/api/studio/covers', $this->payload())->assertConflict();
        $generation = StudioCoverGeneration::findOrFail($id);
        $this->assertTrue(Storage::disk('studio_private')->exists($generation->reference_path));
        $this->assertStringNotContainsString('reference_path', $response->getContent());
        $this->assertStringNotContainsString('child.png', $response->getContent());
        $this->assertDatabaseCount('order_attachments', 0);
        $this->assertSame('new', $this->order->fresh()->status);
    }

    public function test_story_only_employee_cannot_access_product_units_or_generate_product_covers(): void
    {
        $product = Product::create(['name_ar' => 'كتاب تلوين', 'slug' => 'cover-coloring-test', 'price_cents' => 10000, 'is_active' => true, 'personalization_mode' => 'none', 'production_prompt_template' => 'Produce {{product_name}}']);
        $productOrder = Order::create(['order_number' => 'HK-STUDIO-PRODUCT', 'checkout_group_key' => 'STUDIO-PRODUCT', 'status' => 'new', 'uploaded_photos' => []]);
        $item = $productOrder->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000, 'personalization_mode' => 'none']);
        $this->api('GET', '/api/agent/studio/orders/'.$productOrder->order_number)->assertForbidden();
        $payload = $this->payload();
        $payload['order_number'] = $productOrder->order_number;
        $payload['production_unit_key'] = 'product:'.$item->id;
        $payload['kind'] = 'coloring_book';
        $this->api('POST', '/api/studio/covers', $payload)->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_cover_request_requires_consent_reference_current_revision_and_permission(): void
    {
        $payload = $this->payload();
        unset($payload['confirmed_billable']);
        $this->api('POST', '/api/studio/covers', $payload)->assertUnprocessable();
        $payload = $this->payload();
        unset($payload['reference_image']);
        $this->api('POST', '/api/studio/covers', $payload)->assertUnprocessable();
        $payload = $this->payload();
        $payload['settings_revision'] = 999;
        $this->api('POST', '/api/studio/covers', $payload)->assertConflict();
        $this->employee->permissions()->detach(Permission::where('key', 'studio.covers.generate')->value('id'));
        $this->api('POST', '/api/studio/covers', $this->payload())->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_worker_revalidates_revocation_before_any_paid_call(): void
    {
        $id = $this->api('POST', '/api/studio/covers', $this->payload())->assertAccepted()->json('data.id');
        $this->employee->update(['is_active' => false]);
        (new GenerateStudioCover($id))->handle(app(StudioCoverService::class));
        $generation = StudioCoverGeneration::findOrFail($id);
        $this->assertSame('failed', $generation->status);
        $this->assertNull($generation->reference_path);
        $this->assertEmpty(Storage::disk('studio_private')->allFiles());
        Http::assertNothingSent();
    }

    public function test_fake_provider_cover_result_download_checksum_no_secret_and_no_automatic_retry(): void
    {
        $image = UploadedFile::fake()->image('result.png', 24, 36);
        $bytes = file_get_contents($image->getRealPath());
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]], 200)]);
        $id = $this->api('POST', '/api/studio/covers', $this->payload())->assertAccepted()->json('data.id');
        $job = new GenerateStudioCover($id);
        $job->handle(app(StudioCoverService::class));
        $job->handle(app(StudioCoverService::class));
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.openai.com/v1/images/edits' && str_contains($r->body(), 'gpt-image-2'));
        $response = $this->api('GET', '/api/studio/covers/'.$id)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.result.sha256', hash('sha256', $bytes));
        $this->assertStringNotContainsString('sk-isolated', $response->getContent());
        $this->assertStringNotContainsString('output_path', $response->getContent());
        $download = $this->api('GET', '/api/studio/covers/'.$id.'/image')->assertOk();
        $this->assertSame($bytes, $download->streamedContent());
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $source = $other->createToken('Other employee policy', ['agent', 'agent:orders.read', 'agent:catalog.stories']);
        DB::table('studio_employee_grants')->insert(['user_id' => $other->id, 'agent_token_id' => $source->accessToken->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $otherToken = app(StudioSessionService::class)->issue($other)['token'];
        $this->api('GET', '/api/studio/covers/'.$id, [], $otherToken)->assertNotFound();
        $this->travel(25)->hours();
        // Re-login after session expiry to test result lifetime separately.
        $this->token = app(StudioSessionService::class)->issue($this->employee->fresh())['token'];
        $this->api('GET', '/api/studio/covers/'.$id.'/image')->assertStatus(410);
        $this->artisan('studio:purge-cover-media')->assertExitCode(0);
        $this->assertEmpty(Storage::disk('studio_private')->allFiles());
    }

    public function test_text_only_generation_uses_generations_without_a_child_photo(): void
    {
        $settings = app(StudioSettingsService::class)->current()->settings;
        $settings['openai_cover']['use_identity_image'] = false;
        $this->revision = app(StudioSettingsService::class)->publish($settings, $this->revision, $this->employee, 'No child references')->id;
        $image = UploadedFile::fake()->image('result.png');
        $bytes = file_get_contents($image->getRealPath());
        Http::fake(['api.openai.com/v1/images/generations' => Http::response(['data' => [['b64_json' => base64_encode($bytes)]]], 200)]);
        $payload = $this->payload();
        $this->api('POST', '/api/studio/covers', $payload)->assertUnprocessable();
        unset($payload['reference_image']);
        $id = $this->api('POST', '/api/studio/covers', $payload)->assertAccepted()->json('data.id');
        (new GenerateStudioCover($id))->handle(app(StudioCoverService::class));
        $this->api('GET', '/api/studio/covers/'.$id)->assertOk()->assertJsonPath('data.result.used_identity_image', false);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.openai.com/v1/images/generations' && ! $r->hasFile('image[]'));
    }

    public function test_provider_failure_is_sanitized_and_same_request_never_replays_billing(): void
    {
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['error' => ['message' => 'Sensitive upstream details sk-isolated-secret', 'code' => 'internal_error']], 500)]);
        $payload = $this->payload();
        $id = $this->api('POST', '/api/studio/covers', $payload)->assertAccepted()->json('data.id');
        (new GenerateStudioCover($id))->handle(app(StudioCoverService::class));
        $this->api('POST', '/api/studio/covers', $payload)->assertAccepted()->assertJsonPath('data.status', 'failed');
        $response = $this->api('GET', '/api/studio/covers/'.$id)->assertOk()->assertJsonPath('data.error_code', 'GENERATION_FAILED');
        $this->assertStringNotContainsString('Sensitive upstream', $response->getContent());
        Http::assertSentCount(1);
        Queue::assertPushed(GenerateStudioCover::class, 1);
        $this->assertEmpty(Storage::disk('studio_private')->allFiles());
    }

    public function test_generation_admission_is_bounded_to_six_per_employee_per_hour(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $id = $this->api('POST', '/api/studio/covers', $this->payload())->assertAccepted()->json('data.id');
            StudioCoverGeneration::whereKey($id)->update(['status' => 'failed']);
        }
        $this->api('POST', '/api/studio/covers', $this->payload())->assertStatus(429);
        Queue::assertPushed(GenerateStudioCover::class, 6);
        Http::assertNothingSent();
    }

    public function test_specific_product_scope_is_preserved_for_employee_sessions(): void
    {
        $allowed = Product::create(['name_ar' => 'مسموح', 'slug' => 'allowed-studio-product', 'price_cents' => 10000, 'is_active' => true, 'personalization_mode' => 'none', 'production_prompt_template' => 'Produce {{product_name}}']);
        $denied = Product::create(['name_ar' => 'غير مسموح', 'slug' => 'denied-studio-product', 'price_cents' => 10000, 'is_active' => true, 'personalization_mode' => 'none', 'production_prompt_template' => 'Produce {{product_name}}']);
        $order = Order::create(['order_number' => 'HK-SCOPED-PRODUCTS', 'checkout_group_key' => 'SCOPED-PRODUCTS', 'status' => 'new', 'uploaded_photos' => []]);
        foreach ([$allowed, $denied] as $product) {
            $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000, 'personalization_mode' => 'none']);
        }
        $source = $this->employee->tokens()->where('name', 'Existing policy')->firstOrFail();
        $source->update(['abilities' => ['agent', 'agent:orders.read', 'agent:catalog.products', 'agent:catalog.product.'.$allowed->id]]);
        $this->token = app(StudioSessionService::class)->issue($this->employee->fresh())['token'];
        $response = $this->api('GET', '/api/agent/studio/orders/'.$order->order_number)->assertOk()->assertJsonCount(1, 'production_units')->assertJsonPath('production_units.0.product_id', $allowed->id);
        $this->assertStringNotContainsString($denied->name_ar, $response->getContent());
        $this->api('GET', '/api/agent/studio/orders/'.$this->order->order_number)->assertForbidden();
        $item = $order->items()->where('product_id', $allowed->id)->firstOrFail();
        $payload = $this->payload();
        $payload['order_number'] = $order->order_number;
        $payload['production_unit_key'] = 'product:'.$item->id;
        $payload['kind'] = 'coloring_book';
        $this->api('POST', '/api/studio/covers', $payload)->assertAccepted();
    }

    public function test_admission_and_database_queue_job_are_atomic_and_duplicate_safe(): void
    {
        Queue::fake()->except([GenerateStudioCover::class]);
        $payload = $this->payload();
        $this->api('POST', '/api/studio/covers', $payload)->assertAccepted();
        $this->assertDatabaseCount('studio_cover_generations', 1);
        $this->assertDatabaseCount('jobs', 1);
        $this->api('POST', '/api/studio/covers', $payload)->assertAccepted();
        $this->assertDatabaseCount('jobs', 1);
        Http::assertNothingSent();
    }

    public function test_database_queue_failure_rolls_back_admission_audit_and_private_photo(): void
    {
        Queue::fake()->except([GenerateStudioCover::class]);
        config(['queue.connections.database.table' => 'missing_studio_jobs']);
        $this->api('POST', '/api/studio/covers', $this->payload())->assertStatus(500);
        $this->assertDatabaseCount('studio_cover_generations', 0);
        $this->assertDatabaseMissing('admin_activity_logs', ['action' => 'studio.cover.requested']);
        $this->assertEmpty(Storage::disk('studio_private')->allFiles());
        Http::assertNothingSent();
    }
}
