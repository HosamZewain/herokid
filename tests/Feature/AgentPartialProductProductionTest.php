<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderProductPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AgentPartialProductProductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_unit_column_is_additive_and_old_previews_remain_visible_for_single_unit_orders(): void
    {
        Storage::fake('local');
        $this->assertTrue(Schema::hasColumn('order_previews', 'production_unit_key'));
        [$order, $allowedItem] = $this->mixedOrder();
        $order->items()->where('id', '!=', $allowedItem->id)->delete();
        app(OrderProductPreviewService::class)->upload(
            $order, [UploadedFile::fake()->image('legacy-preview.jpg')], null, null,
        );

        $agent = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $token = $agent->createToken('read-old-preview', ['agent', 'agent:orders.read', 'agent:catalog.products'])->plainTextToken;
        $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('production_units.0.preview.images_count', 1);
    }

    public function test_restricted_agent_can_read_and_upload_only_its_product_in_a_mixed_order_without_acquiring_it(): void
    {
        Storage::fake('local');
        [$order, $allowedItem, $blockedItem, $agent, $token] = $this->mixedOrder();
        $allowedKey = 'product:'.$allowedItem->id;
        $blockedKey = 'product:'.$blockedItem->id;

        // Pre-existing sibling work must never become visible through the allowed unit.
        app(OrderProductPreviewService::class)->upload(
            $order, [UploadedFile::fake()->image('blocked-preview.jpg')], null, null, $blockedKey,
        );
        $order->attachments()->create([
            'production_unit_key' => $blockedKey,
            'disk' => 'local',
            'path' => 'blocked-private.pdf',
            'original_name' => 'blocked-private.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'expires_at' => now()->addDay(),
        ]);
        Storage::disk('local')->put('blocked-private.pdf', 'private sibling');

        $lookup = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonCount(1, 'production_units')
            ->assertJsonPath('production_units.0.unit_key', $allowedKey)
            ->assertJsonPath('production_units.0.preview.images_count', 0)
            ->assertJsonPath('inventory_visibility.filtered', true);
        $this->assertStringContainsString('Allowed sticker prompt', $lookup->json('production_units.0.production_prompt'));
        $this->assertStringNotContainsString('Blocked coloring book', $lookup->getContent());
        $this->assertStringNotContainsString('blocked-private.pdf', $lookup->getContent());

        $discovery = $this->withToken($token)->getJson('/api/agent/checkouts/partial-product-work')
            ->assertOk()
            ->assertJsonPath('partial_product_work.checkout_count', 1)
            ->assertJsonPath('partial_product_work.checkouts.0.order_number', $order->order_number)
            ->assertJsonPath('partial_product_work.checkouts.0.production_unit_keys.0', $allowedKey);
        $this->assertStringNotContainsString($blockedKey, $discovery->getContent());

        $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'partial-queue'])
            ->assertOk()->assertJsonPath('checkout', null)
            ->assertJsonPath('queue.partial_product_work.checkout_count', 1);

        $upload = $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => $allowedKey,
            'attachments' => [UploadedFile::fake()->create('sticker-output.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'partial-attachment'))
            ->assertCreated()
            ->assertJsonPath('attachments.0.production_unit_key', $allowedKey);
        $this->assertDatabaseCount('order_attachments', 2);
        $this->assertSame($allowedKey, $order->attachments()->findOrFail($upload->json('attachments.0.id'))->production_unit_key);

        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => $allowedKey,
            'attachments' => [UploadedFile::fake()->create('sticker-output.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'partial-attachment'))->assertCreated();
        $this->assertDatabaseCount('order_attachments', 2);

        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => $blockedKey,
            'attachments' => [UploadedFile::fake()->create('blocked-output.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'blocked-attachment'))->assertForbidden();
        $this->assertDatabaseCount('order_attachments', 2);

        $this->withToken($token)->getJson('/api/agent/orders/'.$order->id.'/attachments/'.$upload->json('attachments.0.id'))
            ->assertOk();
        $blockedAttachment = $order->attachments()->where('production_unit_key', $blockedKey)->firstOrFail();
        $this->withToken($token)->getJson('/api/agent/orders/'.$order->id.'/attachments/'.$blockedAttachment->id)
            ->assertForbidden();

        $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonCount(1, 'production_units.0.attachments')
            ->assertJsonPath('production_units.0.attachments.0.production_unit_key', $allowedKey);

        $this->withToken($token)->getJson('/api/agent/checkouts/'.$order->checkoutReference->short_reference.'/production-context')
            ->assertForbidden();
        $this->withToken($token)->postJson('/api/agent/checkouts/'.$order->checkoutReference->short_reference.'/complete-production', [], ['Idempotency-Key' => 'partial-complete'])
            ->assertForbidden();
        $this->assertDatabaseMissing('order_group_assignments', ['checkout_group_key' => $order->checkoutGroupKey()]);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame(2, $order->items()->count());
        $this->assertSame($agent->id, $order->attachments()->findOrFail($upload->json('attachments.0.id'))->uploaded_by_user_id);
    }

    public function test_new_permitted_product_is_discoverable_after_sibling_product_is_finished(): void
    {
        Storage::fake('local');
        [$finishedOrder, $sticker, $otherProduct] = $this->mixedOrder();
        $finishedOrder->update(['status' => 'ready_preview']);

        $newOrder = Order::create([
            'order_number' => 'HK-PARTIAL-NEW-PRODUCT',
            'checkout_group_key' => $finishedOrder->checkoutGroupKey(),
            'status' => 'new',
            'uploaded_photos' => [],
        ]);
        $otherProduct->update(['order_id' => $newOrder->id]);
        // Both product IDs are permitted: it is the mixed status, not catalog scope,
        // that prevents whole-checkout acquisition.
        $agent = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $token = $agent->createToken('both-products', [
            'agent', 'agent:orders.read', 'agent:orders.acquire', 'agent:orders.upload-attachment',
            'agent:catalog.products', 'agent:catalog.product.'.$sticker->product_id,
            'agent:catalog.product.'.$otherProduct->product_id,
        ])->plainTextToken;

        $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'mixed-status-queue'])
            ->assertOk()->assertJsonPath('reason', 'NO_AVAILABLE_ORDERS')
            ->assertJsonPath('queue.mixed_production_status', 1)
            ->assertJsonPath('queue.partial_product_work.checkout_count', 1);

        $discovery = $this->withToken($token)->getJson('/api/agent/checkouts/partial-product-work')
            ->assertOk()->assertJsonPath('partial_product_work.checkout_count', 1)
            ->assertJsonPath('partial_product_work.checkouts.0.order_number', $newOrder->order_number)
            ->assertJsonPath('partial_product_work.checkouts.0.production_unit_keys.0', 'product:'.$otherProduct->id);
        $this->assertCount(1, $discovery->json('partial_product_work.checkouts.0.production_unit_keys'));

        $this->withToken($token)->getJson('/api/agent/studio/orders/'.$newOrder->order_number)
            ->assertOk()->assertJsonPath('production_units.1.unit_key', 'product:'.$otherProduct->id);
        $this->post('/api/agent/orders/'.$newOrder->id.'/attachments', [
            'production_unit_key' => 'product:'.$otherProduct->id,
            'attachments' => [UploadedFile::fake()->create('new-product.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'mixed-status-attachment'))->assertCreated();

        $this->assertSame('ready_preview', $finishedOrder->fresh()->status);
        $this->assertSame('new', $newOrder->fresh()->status);
        $this->assertDatabaseMissing('order_group_assignments', ['checkout_group_key' => $newOrder->checkoutGroupKey()]);
    }

    public function test_restricted_token_with_all_new_products_still_uses_whole_checkout_acquisition(): void
    {
        Storage::fake('local');
        [$order, $first, $second] = $this->mixedOrder();
        $agent = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $token = $agent->createToken('both-new-products', [
            'agent', 'agent:orders.read', 'agent:orders.acquire', 'agent:orders.upload-attachment',
            'agent:catalog.products', 'agent:catalog.product.'.$first->product_id,
            'agent:catalog.product.'.$second->product_id,
        ])->plainTextToken;

        $this->withToken($token)->getJson('/api/agent/checkouts/partial-product-work')
            ->assertOk()->assertJsonPath('partial_product_work.checkout_count', 0);
        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => 'product:'.$first->id,
            'attachments' => [UploadedFile::fake()->create('before-acquisition.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'both-new-before-acquisition'))->assertForbidden();

        $this->withToken($token)->postJson('/api/agent/checkouts/acquire-next', [], ['Idempotency-Key' => 'both-new-acquire'])
            ->assertOk()->assertJsonPath('checkout.orders.0.id', $order->id);
        $this->assertSame('generating', $order->fresh()->status);
    }

    public function test_unrestricted_token_cannot_use_partial_upload_for_mixed_status_checkout(): void
    {
        Storage::fake('local');
        [$finishedOrder, , $newItem] = $this->mixedOrder();
        $newOrder = Order::create([
            'order_number' => 'HK-PARTIAL-UNRESTRICTED',
            'checkout_group_key' => $finishedOrder->checkoutGroupKey(),
            'status' => 'new',
            'uploaded_photos' => [],
        ]);
        $newItem->update(['order_id' => $newOrder->id]);
        $finishedOrder->update(['status' => 'ready_preview']);
        $agent = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $token = $agent->createToken('unrestricted-products', [
            'agent', 'agent:orders.read', 'agent:orders.upload-attachment', 'agent:catalog.products',
        ])->plainTextToken;

        $this->withToken($token)->getJson('/api/agent/checkouts/partial-product-work')
            ->assertOk()->assertJsonPath('partial_product_work.checkout_count', 0);
        $this->post('/api/agent/orders/'.$newOrder->id.'/attachments', [
            'production_unit_key' => 'product:'.$newItem->id,
            'attachments' => [UploadedFile::fake()->create('unrestricted.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'mixed-status-unrestricted'))->assertForbidden();
        $this->assertSame('new', $newOrder->fresh()->status);
    }

    public function test_restricted_preview_upload_is_unit_scoped_idempotent_and_does_not_replace_sibling_images(): void
    {
        Storage::fake('local');
        [$order, $allowedItem, $blockedItem, , $token] = $this->mixedOrder();
        $allowedKey = 'product:'.$allowedItem->id;
        $blockedKey = 'product:'.$blockedItem->id;
        app(OrderProductPreviewService::class)->upload(
            $order, [UploadedFile::fake()->image('blocked-preview.jpg')], null, null, $blockedKey,
        );

        $payload = [
            'type' => 'product_images',
            'preview_files' => [UploadedFile::fake()->image('sticker-preview.jpg')],
        ];
        $this->post('/api/agent/orders/'.$order->id.'/previews', $payload, $this->headers($token, 'partial-preview'))
            ->assertCreated()
            ->assertJsonPath('preview.production_unit_key', $allowedKey)
            ->assertJsonPath('preview.images_count', 1);
        $this->assertSame(1, OrderPreview::where('production_unit_key', $allowedKey)->count());
        $this->assertSame(1, OrderPreview::where('production_unit_key', $blockedKey)->count());

        $this->post('/api/agent/orders/'.$order->id.'/previews', $payload, $this->headers($token, 'partial-preview'))
            ->assertCreated();
        $this->assertDatabaseCount('order_previews', 2);

        $this->post('/api/agent/orders/'.$order->id.'/previews', $payload + ['production_unit_key' => $blockedKey], $this->headers($token, 'blocked-preview'))
            ->assertForbidden();
        $this->assertDatabaseCount('order_previews', 2);

        $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('production_units.0.preview.images_count', 1);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_restricted_product_reference_links_work_but_unscoped_reference_access_remains_forbidden(): void
    {
        Storage::fake('local');
        [$order, $allowedItem, $blockedItem, , $token] = $this->mixedOrder();
        $order->update(['uploaded_photos' => ['orders/partial-child.jpg']]);
        Storage::disk('local')->put('orders/partial-child.jpg', 'safe reference');

        $lookup = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$order->order_number)->assertOk();
        $url = $lookup->json('production_units.0.reference_files.0.url');
        $this->assertStringContainsString('production_unit_key=', $url);
        $this->withToken($token)->get($url)->assertOk();
        $this->withToken($token)->getJson('/api/agent/orders/'.$order->id.'/references/child-photos/0')
            ->assertForbidden();
        $this->withToken($token)->getJson('/api/agent/orders/'.$order->id.'/references/child-photos/0?production_unit_key=product:'.$blockedItem->id)
            ->assertForbidden();
        $this->assertSame('product:'.$allowedItem->id, $lookup->json('production_units.0.unit_key'));
    }

    public function test_unrestricted_agent_still_requires_acquisition_for_attachments(): void
    {
        Storage::fake('local');
        [$order, $allowedItem] = $this->mixedOrder();
        $agent = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $token = $agent->createToken('unrestricted', [
            'agent', 'agent:orders.read', 'agent:orders.upload-attachment',
            'agent:catalog.products',
        ])->plainTextToken;

        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => 'product:'.$allowedItem->id,
            'attachments' => [UploadedFile::fake()->create('file.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'unrestricted-no-acquire'))->assertForbidden();
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_restricted_agent_cannot_upload_production_attachments_after_order_is_finished(): void
    {
        Storage::fake('local');
        [$order, $allowedItem, , , $token] = $this->mixedOrder();
        $order->update(['status' => 'shipped']);

        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => 'product:'.$allowedItem->id,
            'attachments' => [UploadedFile::fake()->create('late.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'late-attachment'))->assertForbidden();
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_restricted_agent_cannot_upload_when_another_agent_owns_the_checkout(): void
    {
        Storage::fake('local');
        [$order, $allowedItem, , , $token] = $this->mixedOrder();
        $other = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        OrderGroupAssignment::create([
            'checkout_group_key' => $order->checkoutGroupKey(),
            'assigned_to_user_id' => $other->id,
            'assigned_by_user_id' => $other->id,
            'assigned_at' => now(),
        ]);

        $this->withToken($token)->getJson('/api/agent/checkouts/partial-product-work')
            ->assertOk()->assertJsonPath('partial_product_work.checkout_count', 0);
        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => 'product:'.$allowedItem->id,
            'attachments' => [UploadedFile::fake()->create('conflict.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'conflicting-attachment'))->assertForbidden();
        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_partial_product_discovery_requires_agent_read_authorization(): void
    {
        $this->getJson('/api/agent/checkouts/partial-product-work')->assertUnauthorized();
        $agent = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $token = $agent->createToken('no-read', ['agent', 'agent:catalog.products'])->plainTextToken;
        $this->withToken($token)->getJson('/api/agent/checkouts/partial-product-work')->assertForbidden();
    }

    public function test_product_scope_filters_sibling_order_rows_and_does_not_echo_a_blocked_order_number(): void
    {
        Storage::fake('local');
        [$order, $allowedItem, $blockedItem, , $token] = $this->mixedOrder();
        $blockedOrder = Order::create([
            'order_number' => 'HK-PARTIAL-BLOCKED-ROW',
            'checkout_group_key' => $order->checkoutGroupKey(),
            'status' => 'new',
            'uploaded_photos' => [],
        ]);
        $blockedItem->update(['order_id' => $blockedOrder->id]);

        $response = $this->withToken($token)->getJson('/api/agent/studio/orders/'.$blockedOrder->order_number)
            ->assertOk()
            ->assertJsonPath('order.order_number', $order->order_number)
            ->assertJsonCount(1, 'production_units')
            ->assertJsonPath('production_units.0.unit_key', 'product:'.$allowedItem->id);
        $this->assertStringNotContainsString($blockedOrder->order_number, $response->getContent());

        $this->post('/api/agent/orders/'.$blockedOrder->id.'/attachments', [
            'production_unit_key' => 'product:'.$blockedItem->id,
            'attachments' => [UploadedFile::fake()->create('blocked.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'blocked-other-row'))->assertForbidden();
        $this->post('/api/agent/orders/'.$order->id.'/attachments', [
            'production_unit_key' => 'product:'.$allowedItem->id,
            'attachments' => [UploadedFile::fake()->create('allowed.pdf', 100, 'application/pdf')],
        ], $this->headers($token, 'allowed-other-row'))->assertCreated();
        $this->assertSame('new', $blockedOrder->fresh()->status);
    }

    /** @return array{Order, OrderItem, OrderItem, User, string} */
    private function mixedOrder(): array
    {
        $allowed = Product::create([
            'name_ar' => 'Allowed sticker', 'slug' => 'allowed-partial-sticker', 'price_cents' => 10000,
            'is_active' => true, 'production_prompt_template' => 'Allowed sticker prompt for {{child_full_name}}.',
        ]);
        $blocked = Product::create([
            'name_ar' => 'Blocked coloring book', 'slug' => 'blocked-partial-coloring', 'price_cents' => 10000,
            'is_active' => true, 'production_prompt_template' => 'Blocked coloring book prompt.',
        ]);
        $order = Order::create([
            'order_number' => 'HK-PARTIAL-MIXED', 'checkout_group_key' => 'PARTIAL-MIXED',
            'parent_name' => 'Parent', 'child_name' => 'Ali', 'status' => 'new', 'uploaded_photos' => [],
        ]);
        $allowedItem = $order->items()->create([
            'item_type' => 'product', 'product_id' => $allowed->id, 'title' => $allowed->name_ar,
            'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000,
            'personalization_snapshot' => ['child_name' => 'Ali'],
        ]);
        $blockedItem = $order->items()->create([
            'item_type' => 'product', 'product_id' => $blocked->id, 'title' => $blocked->name_ar,
            'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000,
            'personalization_snapshot' => ['child_name' => 'Ali'],
        ]);
        $agent = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        $token = $agent->createToken('partial-product', [
            'agent', 'agent:orders.read', 'agent:orders.acquire', 'agent:orders.update-status',
            'agent:orders.upload-attachment', 'agent:orders.upload-preview',
            'agent:catalog.products', 'agent:catalog.product.'.$allowed->id,
        ])->plainTextToken;

        return [$order->fresh(['checkoutReference']), $allowedItem, $blockedItem, $agent, $token];
    }

    private function headers(string $token, string $idempotencyKey): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => $idempotencyKey,
        ];
    }
}
