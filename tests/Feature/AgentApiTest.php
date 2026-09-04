<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Services\AgentApi\AgentCatalogScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_authentication_is_isolated_from_mobile_and_disabled_admin_tokens(): void
    {
        $this->postJson('/api/agent/checkouts/acquire-next')->assertUnauthorized()->assertJsonPath('error', 'UNAUTHORIZED');

        $disabled = $this->agent(false);
        $disabledToken = $disabled->createToken('disabled', $this->abilities())->plainTextToken;
        $this->withToken($disabledToken)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'disabled'])
            ->assertForbidden()->assertJsonPath('error', 'FORBIDDEN');

        $mobile = $this->agent();
        $mobileToken = $mobile->createToken('mobile', ['mobile'])->plainTextToken;
        $this->withToken($mobileToken)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'mobile'])
            ->assertForbidden()->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_post_requests_require_idempotency_and_the_exact_token_ability(): void
    {
        $agent = $this->agent();

        $this->withToken($this->token($agent))->postJson('/api/agent/checkouts/acquire-next')
            ->assertStatus(422)->assertJsonPath('error', 'IDEMPOTENCY_KEY_REQUIRED');

        $limited = $agent->createToken('limited-agent', ['agent', 'agent:orders.read'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($limited)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'limited-acquire'])
            ->assertForbidden()->assertJsonPath('error', 'FORBIDDEN');
    }

    public function test_agent_token_command_enables_the_account_and_can_revoke_the_named_token(): void
    {
        $agent = $this->agent(false);

        $this->artisan('agent:token', [
            'action' => 'issue',
            'email' => $agent->email,
            '--name' => 'automated-production',
            '--expires' => 30,
        ])->assertSuccessful();

        $this->assertTrue($agent->refresh()->agent_api_enabled);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $agent->id,
            'name' => 'automated-production',
        ]);

        $this->artisan('agent:token', [
            'action' => 'revoke',
            'email' => $agent->email,
            '--name' => 'automated-production',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $agent->id,
            'name' => 'automated-production',
        ]);
    }

    public function test_admin_can_issue_and_revoke_a_catalog_scoped_token_from_the_dashboard(): void
    {
        $manager = $this->agent();
        $agent = $this->agent(false);

        $response = $this->actingAs($manager)->post(route('admin.agent-api-tokens.store'), [
            'agent_user_id' => $agent->id,
            'name' => 'products-worker',
            'expires_in_days' => 30,
            'catalog_scope' => AgentCatalogScope::PRODUCTS,
        ])->assertRedirect(route('admin.agent-api-tokens.index'))
            ->assertSessionHas('new_agent_token');

        $plainTextToken = $response->getSession()->get('new_agent_token');
        $token = PersonalAccessToken::query()->where('name', 'products-worker')->firstOrFail();

        $this->assertTrue($agent->refresh()->agent_api_enabled);
        $this->assertContains('agent:catalog.products', $token->abilities);
        $this->assertNotContains('agent:catalog.stories', $token->abilities);
        $this->assertNotSame($plainTextToken, $token->token);

        $this->actingAs($manager)->get(route('admin.agent-api-tokens.index'))
            ->assertOk()
            ->assertSee('المنتجات فقط')
            ->assertSee($plainTextToken);

        $this->actingAs($manager)->get(route('admin.agent-api-tokens.index'))
            ->assertOk()
            ->assertDontSee($plainTextToken);

        $this->actingAs($manager)->delete(route('admin.agent-api-tokens.destroy', $token))
            ->assertRedirect();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);
    }

    public function test_agent_token_management_page_requires_its_sensitive_permission(): void
    {
        $admin = $this->agent();
        $permission = Permission::query()->where('key', 'agent_api.tokens.manage')->firstOrFail();
        $admin->permissions()->detach($permission);

        $this->actingAs($admin)->get(route('admin.agent-api-tokens.index'))->assertForbidden();
    }

    public function test_missing_route_bound_order_uses_the_agent_error_contract(): void
    {
        $agent = $this->agent();

        $this->withToken($this->token($agent))
            ->postJson('/api/agent/orders/999999/attachments', [], ['Idempotency-Key' => 'missing-order'])
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error', 'ORDER_NOT_FOUND');
    }

    public function test_acquire_next_atomically_assigns_complete_checkout_and_is_retry_safe(): void
    {
        $agent = $this->agent();
        $token = $this->token($agent);
        $first = $this->storyOrder('AGENT-ONE', 'HK-AGENT-1');
        $second = $this->storyOrder('AGENT-ONE', 'HK-AGENT-2');
        $later = $this->storyOrder('AGENT-TWO', 'HK-AGENT-3');

        $response = $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'acquire-run-1'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'checkout.orders');

        $reference = $response->json('checkout.reference');
        $this->assertNotEmpty($reference);
        $this->assertSame('generating', $first->refresh()->status);
        $this->assertSame('generating', $second->refresh()->status);
        $this->assertSame('new', $later->refresh()->status);
        $this->assertDatabaseHas('order_group_assignments', ['checkout_group_key' => 'AGENT-ONE', 'assigned_to_user_id' => $agent->id]);

        $retry = $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'acquire-run-1'])
            ->assertOk();
        $this->assertSame($reference, $retry->json('checkout.reference'));
        $this->assertSame(1, OrderGroupAssignment::count());

        $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', ['different' => true], ['Idempotency-Key' => 'acquire-run-1'])
            ->assertStatus(409)->assertJsonPath('error', 'IDEMPOTENCY_KEY_REUSED');

        $this->withToken($token)->postJson("/api/agent/checkouts/{$reference}/complete-production", [], ['Idempotency-Key' => 'acquire-run-1'])
            ->assertStatus(409)->assertJsonPath('error', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_context_upload_and_checkout_completion_cover_every_production_unit(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        $token = $this->token($agent);
        $first = $this->storyOrder('AGENT-CONTEXT', 'HK-CONTEXT-1', true);
        $second = $this->storyOrder('AGENT-CONTEXT', 'HK-CONTEXT-2', true);

        $acquired = $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'context-acquire'])
            ->assertOk();
        $reference = $acquired->json('checkout.reference');

        $context = $this->withToken($token)->getJson("/api/agent/checkouts/{$reference}/production-context")
            ->assertOk()
            ->assertJsonCount(2, 'production_units')
            ->assertJsonMissingPath('checkout.address')
            ->assertJsonMissingPath('checkout.payment');
        $this->assertStringContainsString('child', strtolower($context->json('production_units.0.reference_files.0.name')));
        $this->assertNotEmpty($context->json('production_units.0.production_prompt'));
        $this->assertStringContainsString('/api/agent/orders/', $context->json('production_units.0.production_prompt'));
        $this->assertStringNotContainsString('/orders/'.$first->id.'/production-photos/', $context->json('production_units.0.production_prompt'));

        $this->withToken($token)->postJson("/api/agent/checkouts/{$reference}/complete-production", [], ['Idempotency-Key' => 'complete-before-files'])
            ->assertStatus(422)->assertJsonPath('error', 'PRODUCTION_FILES_MISSING');

        foreach ([$first, $second] as $index => $order) {
            $this->withToken($token)->post("/api/agent/orders/{$order->id}/attachments", [
                'production_unit_key' => 'story:'.$order->id,
                'attachments' => [UploadedFile::fake()->create('production-'.$index.'.pdf', 100, 'application/pdf')],
            ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'file-'.$index])
                ->assertCreated()->assertJsonPath('attachments.0.production_unit_key', 'story:'.$order->id);
        }

        $completed = $this->withToken($token)->postJson("/api/agent/checkouts/{$reference}/complete-production", [], ['Idempotency-Key' => 'complete-after-files'])
            ->assertOk()->assertJsonPath('status', 'ready_preview');
        $this->assertFalse((bool) $completed->json('already_completed'));
        $this->assertSame('ready_preview', $first->refresh()->status);
        $this->assertSame('ready_preview', $second->refresh()->status);

        $this->withToken($token)->postJson("/api/agent/checkouts/{$reference}/complete-production", [], ['Idempotency-Key' => 'complete-repeat'])
            ->assertOk()->assertJsonPath('already_completed', true);
    }

    public function test_repair_command_only_moves_direct_agent_completions_to_ready_preview(): void
    {
        $agent = $this->agent();
        $incorrect = $this->storyOrder('AGENT-REPAIR', 'HK-REPAIR', true);
        $incorrect->update(['status' => 'preview_uploaded']);
        $incorrect->statusLogs()->create([
            'status_type' => 'order',
            'status' => 'preview_uploaded',
            'notes' => 'اكتمل الإنتاج بواسطة Agent API.',
        ]);
        AdminActivityLog::query()->create([
            'user_id' => $agent->id,
            'action' => 'agent.checkout_production_completed',
            'properties' => [
                'checkout_group_key' => 'AGENT-REPAIR',
                'new_status' => 'preview_uploaded',
            ],
        ]);

        $manual = $this->storyOrder('MANUAL-PREVIEW', 'HK-MANUAL-PREVIEW', true);
        $manual->update(['status' => 'preview_uploaded']);
        $manual->statusLogs()->create([
            'status_type' => 'order',
            'status' => 'preview_uploaded',
            'notes' => 'تم إرسال المعاينة للعميل يدويًا.',
        ]);

        $this->artisan('agent:repair-ready-preview', ['--apply' => true])
            ->expectsOutput('Eligible Agent checkouts: 1')
            ->expectsOutput('Eligible order records: 1')
            ->expectsOutput('Updated Agent checkouts: 1')
            ->expectsOutput('Updated order records: 1')
            ->assertSuccessful();

        $this->assertSame('ready_preview', $incorrect->refresh()->status);
        $this->assertSame('preview_uploaded', $manual->refresh()->status);
    }

    public function test_prompt_product_without_story_is_eligible_and_ready_product_is_not(): void
    {
        $agent = $this->agent();
        $token = $this->token($agent);
        $readyOrder = $this->productOrder('READY-ONLY', 'HK-READY', null);
        $productionOrder = $this->productOrder('PRODUCT-PRODUCTION', 'HK-PRODUCT', 'Create {{product_name}} for {{child_full_name}}.');

        $response = $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'product-acquire'])
            ->assertOk();

        $this->assertSame($productionOrder->checkoutReference->short_reference, $response->json('checkout.reference'));
        $this->assertSame('new', $readyOrder->refresh()->status);
        $this->assertSame('generating', $productionOrder->refresh()->status);
    }

    public function test_story_only_token_skips_product_and_mixed_checkouts(): void
    {
        $agent = $this->agent();
        $token = $this->scopedToken($agent, AgentCatalogScope::STORIES);
        $product = $this->productOrder('PRODUCT-FIRST', 'HK-PRODUCT-FIRST', 'Create {{product_name}}.');
        $mixedStory = $this->storyOrder('MIXED-CHECKOUT', 'HK-MIXED-STORY');
        $mixedProduct = $this->productOrder('MIXED-CHECKOUT', 'HK-MIXED-PRODUCT', 'Create {{product_name}}.');
        $story = $this->storyOrder('STORY-LAST', 'HK-STORY-LAST');

        $response = $this->withToken($token)
            ->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'stories-scope'])
            ->assertOk();

        $this->assertSame($story->checkoutReference->short_reference, $response->json('checkout.reference'));
        $this->assertSame('new', $product->refresh()->status);
        $this->assertSame('new', $mixedStory->refresh()->status);
        $this->assertSame('new', $mixedProduct->refresh()->status);
        $this->assertSame('generating', $story->refresh()->status);
    }

    public function test_product_only_token_skips_story_checkouts(): void
    {
        $agent = $this->agent();
        $token = $this->scopedToken($agent, AgentCatalogScope::PRODUCTS);
        $story = $this->storyOrder('STORY-FIRST', 'HK-STORY-FIRST');
        $product = $this->productOrder('PRODUCT-LAST', 'HK-PRODUCT-LAST', 'Create {{product_name}}.');

        $response = $this->withToken($token)
            ->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'products-scope'])
            ->assertOk();

        $this->assertSame($product->checkoutReference->short_reference, $response->json('checkout.reference'));
        $this->assertSame('new', $story->refresh()->status);
        $this->assertSame('generating', $product->refresh()->status);
    }

    public function test_legacy_unscoped_agent_token_keeps_access_to_both_catalog_types(): void
    {
        $agent = $this->agent();
        $story = $this->storyOrder('LEGACY-STORY', 'HK-LEGACY-STORY');

        $this->withToken($this->token($agent))
            ->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'legacy-scope'])
            ->assertOk()
            ->assertJsonPath('checkout.reference', $story->checkoutReference->short_reference);
    }

    public function test_product_preview_upload_is_idempotent_and_does_not_change_status(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        $token = $this->token($agent);
        $order = $this->productOrder('PRODUCT-PREVIEW', 'HK-PREVIEW', 'Create {{product_name}}.');

        $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'preview-acquire'])
            ->assertOk();

        $payload = [
            'type' => 'product_images',
            'preview_files' => [UploadedFile::fake()->image('preview.jpg', 800, 800)],
        ];
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'preview-upload'];

        $first = $this->post("/api/agent/orders/{$order->id}/previews", $payload, $headers)
            ->assertCreated()->assertJsonPath('preview.type', 'product_images');
        $this->assertSame(1, $first->json('preview.images_count'));
        $this->assertSame('generating', $order->refresh()->status);

        $this->post("/api/agent/orders/{$order->id}/previews", $payload, $headers)
            ->assertCreated()->assertJsonPath('preview.images_count', 1);
        $this->assertDatabaseCount('order_previews', 1);
    }

    public function test_attachment_validation_and_protected_reference_downloads(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        $token = $this->token($agent);
        $order = $this->storyOrder('AGENT-REFERENCE', 'HK-REFERENCE', true);

        $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'reference-acquire'])
            ->assertOk();

        $reference = $this->withToken($token)->get('/api/agent/orders/'.$order->id.'/references/child-photos/0')
            ->assertOk();
        $this->assertStringContainsString('no-store', (string) $reference->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $reference->headers->get('Cache-Control'));

        $this->withToken($token)->post("/api/agent/orders/{$order->id}/attachments", [
            'attachments' => [UploadedFile::fake()->create('script.exe', 10, 'application/octet-stream')],
        ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'invalid-file'])
            ->assertStatus(422)->assertJsonPath('error', 'INVALID_ATTACHMENT');
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_a_second_agent_cannot_acquire_the_same_checkout(): void
    {
        $firstAgent = $this->agent();
        $secondAgent = $this->agent();
        $this->storyOrder('ONE-CHECKOUT', 'HK-ONE');

        $this->withToken($this->token($firstAgent))->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'first-agent'])
            ->assertOk()->assertJsonPath('checkout.checkout_group', 'ONE-CHECKOUT');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($secondAgent))->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'second-agent'])
            ->assertOk()->assertJsonPath('checkout', null)->assertJsonPath('reason', 'NO_AVAILABLE_ORDERS');
        $this->assertDatabaseCount('order_group_assignments', 1);
    }

    public function test_another_agent_cannot_read_or_upload_to_acquired_checkout(): void
    {
        $owner = $this->agent();
        $other = $this->agent();
        $order = $this->storyOrder('AGENT-PRIVATE', 'HK-PRIVATE', true);
        $acquired = $this->withToken($this->token($owner))->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'private-acquire']);
        $reference = $acquired->json('checkout.reference');
        $this->assertNotSame($owner->id, $other->id);
        $this->assertDatabaseHas('order_group_assignments', [
            'checkout_group_key' => 'AGENT-PRIVATE',
            'assigned_to_user_id' => $owner->id,
        ]);

        $otherToken = $this->token($other);
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson("/api/agent/checkouts/{$reference}/production-context")
            ->assertForbidden()->assertJsonPath('error', 'ORDER_NOT_ACQUIRED_BY_AGENT');
        $this->withToken($otherToken)->post("/api/agent/orders/{$order->id}/attachments", [
            'attachments' => [UploadedFile::fake()->create('forbidden.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$otherToken, 'Idempotency-Key' => 'forbidden-file'])
            ->assertForbidden()->assertJsonPath('error', 'ORDER_NOT_ACQUIRED_BY_AGENT');
    }

    private function agent(bool $enabled = true): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => $enabled]);
    }

    private function token(User $user): string
    {
        return $user->createToken('agent-test', $this->abilities())->plainTextToken;
    }

    private function scopedToken(User $user, string $scope): string
    {
        return $user->createToken(
            'scoped-agent-test',
            [...$this->abilities(), ...AgentCatalogScope::abilities($scope)],
        )->plainTextToken;
    }

    private function abilities(): array
    {
        return ['agent', 'agent:orders.read', 'agent:orders.acquire', 'agent:orders.update-status', 'agent:orders.upload-attachment', 'agent:orders.upload-preview'];
    }

    private function storyOrder(string $group, string $number, bool $withPhoto = false): Order
    {
        $story = Story::create([
            'title' => 'Agent Story '.$number,
            'slug' => strtolower($number),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);
        $photos = [];
        if ($withPhoto) {
            $path = 'orders/'.$number.'/child.jpg';
            Storage::disk('local')->put($path, 'child-photo');
            $photos[] = $path;
        }

        return Order::create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'story_id' => $story->id,
            'parent_name' => 'Parent',
            'parent_notes' => 'Production note',
            'child_name' => 'Mariam',
            'child_age' => 7,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '201000000000', 'checkout_group' => $group, 'address' => 'private'],
            'uploaded_photos' => $photos,
            'status' => 'new',
        ]);
    }

    private function productOrder(string $group, string $number, ?string $prompt): Order
    {
        $product = Product::create([
            'name_ar' => 'منتج '.$number,
            'slug' => strtolower($number),
            'price_cents' => 10000,
            'is_active' => true,
            'production_prompt_template' => $prompt,
        ]);
        $order = Order::create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'story_id' => null,
            'parent_name' => 'Parent',
            'child_name' => 'Ali',
            'child_age' => 8,
            'child_gender' => 'boy',
            'delivery_details' => ['checkout_group' => $group],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 10000,
            'quantity' => 1,
            'total_price_cents' => 10000,
            'personalization_snapshot' => ['child_name' => 'Ali'],
        ]);

        return $order->fresh(['checkoutReference']);
    }
}
