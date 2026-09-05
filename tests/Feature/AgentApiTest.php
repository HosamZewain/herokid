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

    public function test_admin_can_explicitly_enable_existing_order_rework_on_a_new_token(): void
    {
        $manager = $this->agent();
        $agent = $this->agent(false);

        $this->actingAs($manager)->post(route('admin.agent-api-tokens.store'), [
            'agent_user_id' => $agent->id,
            'name' => 'rework-worker',
            'expires_in_days' => 30,
            'catalog_scope' => AgentCatalogScope::PRODUCTS,
            'allow_rework' => true,
        ])->assertRedirect(route('admin.agent-api-tokens.index'));

        $token = PersonalAccessToken::query()->where('name', 'rework-worker')->firstOrFail();
        $this->assertContains('agent:orders.rework', $token->abilities);
        $this->assertContains('agent:orders.edit-personalization', $token->abilities);
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

    public function test_agent_can_select_correct_and_rework_a_specific_existing_product_checkout(): void
    {
        Storage::fake('local');
        $agent = $this->agent();
        $token = $this->reworkToken($agent, AgentCatalogScope::PRODUCTS);
        $order = $this->productOrder(
            'PRODUCT-REWORK',
            'HK-PRODUCT-REWORK',
            'Sticker for {{child_full_name}} at {{school_name}} in {{class_name}} ({{name_language}}).',
        );
        $order->update(['status' => 'ready_preview']);
        $reference = $order->checkoutReference->short_reference;
        $item = $order->items()->firstOrFail();
        $queueOrder = $this->productOrder('OTHER-ACTIVE-WORK', 'HK-OTHER-ACTIVE-WORK', 'Create {{product_name}}.');

        $this->withToken($token)
            ->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'other-active-acquire'])
            ->assertOk()
            ->assertJsonPath('checkout.reference', $queueOrder->checkoutReference->short_reference);

        $this->withToken($token)
            ->postJson("/api/agent/checkouts/{$reference}/acquire", [], ['Idempotency-Key' => 'specific-rework-acquire'])
            ->assertOk()
            ->assertJsonPath('checkout.reference', $reference)
            ->assertJsonPath('already_acquired', false);
        $this->assertDatabaseCount('order_group_assignments', 2);

        $this->withToken($token)
            ->patchJson("/api/agent/orders/{$order->id}/personalization", [
                'production_unit_key' => 'product:'.$item->id,
                'personalization' => [
                    'child_name' => 'Adam Ahmed Mohamed',
                    'school_name' => 'School sky light',
                    'class_name' => 'kg2',
                    'language' => 'en',
                ],
                'change_reason' => 'Customer requested corrected sticker data.',
            ], ['Idempotency-Key' => 'specific-rework-edit'])
            ->assertOk()
            ->assertJsonPath('production_unit_key', 'product:'.$item->id)
            ->assertJsonPath('production_unit.language', 'en')
            ->assertJsonPath('production_unit.personalization.0.value', 'Adam Ahmed Mohamed');

        $snapshot = $item->fresh()->personalization_snapshot;
        $this->assertSame('Adam Ahmed Mohamed', $snapshot['child_name']);
        $this->assertSame('School sky light', $snapshot['school_name']);
        $this->assertSame('kg2', $snapshot['class_name']);
        $this->assertSame('en', $order->fresh()->language);

        $context = $this->withToken($token)
            ->getJson("/api/agent/checkouts/{$reference}/production-context")
            ->assertOk();
        $prompt = $context->json('production_units.0.production_prompt');
        $this->assertStringContainsString('Adam Ahmed Mohamed', $prompt);
        $this->assertStringContainsString('School sky light', $prompt);
        $this->assertStringContainsString('kg2', $prompt);

        $this->withToken($token)->post("/api/agent/orders/{$order->id}/attachments", [
            'production_unit_key' => 'product:'.$item->id,
            'attachments' => [UploadedFile::fake()->create('old-production.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'old-production-file'])
            ->assertCreated();

        $this->withToken($token)
            ->postJson("/api/agent/checkouts/{$reference}/start-rework", [], ['Idempotency-Key' => 'specific-rework-start'])
            ->assertOk()
            ->assertJsonPath('status', 'generating')
            ->assertJsonPath('already_started', false);
        $this->assertSame('generating', $order->fresh()->status);

        $this->withToken($token)
            ->postJson("/api/agent/checkouts/{$reference}/complete-production", [], ['Idempotency-Key' => 'rework-complete-before-new-file'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'PRODUCTION_FILES_MISSING');

        $this->withToken($token)->post("/api/agent/orders/{$order->id}/attachments", [
            'production_unit_key' => 'product:'.$item->id,
            'attachments' => [UploadedFile::fake()->create('replacement-production.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'replacement-production-file'])
            ->assertCreated();

        $this->withToken($token)
            ->postJson("/api/agent/checkouts/{$reference}/complete-production", [], ['Idempotency-Key' => 'rework-complete-after-new-file'])
            ->assertOk()
            ->assertJsonPath('status', 'ready_preview');
        $this->assertDatabaseCount('order_attachments', 2);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'agent.order_personalization_updated',
            'subject_id' => $order->id,
        ]);
    }

    public function test_specific_rework_requires_new_token_ability_and_respects_existing_assignment(): void
    {
        $owner = $this->agent();
        $other = $this->agent();
        $order = $this->productOrder('LOCKED-REWORK', 'HK-LOCKED-REWORK', 'Create {{product_name}}.');
        $reference = $order->checkoutReference->short_reference;

        $this->withToken($this->token($owner))
            ->postJson("/api/agent/checkouts/{$reference}/acquire", [], ['Idempotency-Key' => 'missing-rework-ability'])
            ->assertForbidden()
            ->assertJsonPath('error', 'FORBIDDEN');

        $this->app['auth']->forgetGuards();
        $this->withToken($this->reworkToken($owner))
            ->postJson("/api/agent/checkouts/{$reference}/acquire", [], ['Idempotency-Key' => 'owner-specific-acquire'])
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->reworkToken($other))
            ->postJson("/api/agent/checkouts/{$reference}/acquire", [], ['Idempotency-Key' => 'other-specific-acquire'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'ORDER_ALREADY_ACQUIRED');
    }

    public function test_specific_rework_updates_every_production_order_in_a_mixed_checkout(): void
    {
        $agent = $this->agent();
        $token = $this->reworkToken($agent, AgentCatalogScope::ALL);
        $story = $this->storyOrder('MIXED-REWORK', 'HK-MIXED-REWORK-STORY', true);
        $product = $this->productOrder('MIXED-REWORK', 'HK-MIXED-REWORK-PRODUCT', 'Create {{product_name}}.');
        $story->update(['status' => 'ready_preview']);
        $product->update(['status' => 'preview_uploaded']);
        $reference = $story->checkoutReference->short_reference;

        $this->withToken($token)
            ->postJson("/api/agent/checkouts/{$reference}/acquire", [], ['Idempotency-Key' => 'mixed-rework-acquire'])
            ->assertOk()
            ->assertJsonCount(2, 'checkout.orders');
        $this->withToken($token)
            ->postJson("/api/agent/checkouts/{$reference}/start-rework", [], ['Idempotency-Key' => 'mixed-rework-start'])
            ->assertOk();

        $this->assertSame('generating', $story->fresh()->status);
        $this->assertSame('generating', $product->fresh()->status);
    }

    public function test_agent_can_correct_story_personalization_before_starting_rework(): void
    {
        $agent = $this->agent();
        $token = $this->reworkToken($agent, AgentCatalogScope::STORIES);
        $order = $this->storyOrder('STORY-REWORK', 'HK-STORY-REWORK', true);
        $order->update(['status' => 'revision_requested']);
        $reference = $order->checkoutReference->short_reference;

        $this->withToken($token)
            ->postJson("/api/agent/checkouts/{$reference}/acquire", [], ['Idempotency-Key' => 'story-rework-acquire'])
            ->assertOk();

        $this->withToken($token)
            ->patchJson("/api/agent/orders/{$order->id}/personalization", [
                'production_unit_key' => 'story:'.$order->id,
                'personalization' => [
                    'child_name' => 'Mariam Ahmed',
                    'child_age' => 9,
                    'language' => 'en',
                ],
                'change_reason' => 'Customer corrected the story personalization.',
            ], ['Idempotency-Key' => 'story-rework-edit'])
            ->assertOk()
            ->assertJsonPath('production_unit.child.name', 'Mariam Ahmed')
            ->assertJsonPath('production_unit.child.age', 9)
            ->assertJsonPath('production_unit.language', 'en');

        $this->assertSame('Mariam Ahmed', $order->fresh()->child_name);
        $this->assertSame(9, (int) $order->fresh()->child_age);
        $this->assertSame('en', $order->fresh()->language);
    }

    public function test_agent_rework_rejects_cancelled_or_shipment_created_checkout(): void
    {
        $agent = $this->agent();
        $token = $this->reworkToken($agent);
        $cancelled = $this->productOrder('CANCELLED-REWORK', 'HK-CANCELLED-REWORK', 'Create {{product_name}}.');
        $cancelled->update(['status' => 'cancelled']);

        $this->withToken($token)
            ->postJson('/api/agent/checkouts/'.$cancelled->checkoutReference->short_reference.'/acquire', [], ['Idempotency-Key' => 'cancelled-rework'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'CHECKOUT_NOT_REWORKABLE');

        $shipment = $this->productOrder('SHIPPED-REWORK', 'HK-SHIPPED-REWORK', 'Create {{product_name}}.');
        $shipment->update(['shipping_status' => 'shipment_created']);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson('/api/agent/checkouts/'.$shipment->checkoutReference->short_reference.'/acquire', [], ['Idempotency-Key' => 'shipment-rework'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'CHECKOUT_NOT_REWORKABLE');
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

    private function reworkToken(User $user, string $scope = AgentCatalogScope::ALL): string
    {
        return $user->createToken(
            'rework-agent-test',
            [...$this->abilities(), ...AgentCatalogScope::abilities($scope), 'agent:orders.edit-personalization', 'agent:orders.rework'],
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
