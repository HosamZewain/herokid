<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductionProject;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\AdminOrderQuickEditService;
use App\Services\Orders\ProductProductionComponentSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderQuickRemovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    private OrderItem $item;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->order = Order::create([
            'order_number' => 'HK-REMOVAL', 'checkout_group_key' => 'CHK-REMOVAL',
            'parent_name' => 'ولي الأمر', 'child_name' => 'ليلى', 'child_age' => 7, 'child_gender' => 'girl',
            'status' => 'ready_preview', 'order_source' => 'website', 'paid_amount_cents' => 30000,
            'payment_status' => 'partially_paid', 'payment_method' => 'انستاباي', 'discount_cents' => 2000,
            'discount_reason' => 'خصم محفوظ', 'preview_approved_at' => now()->subDay(),
            'delivery_details' => ['phone' => '01012345678', 'delivery_fee' => 50, 'total' => 430,
                'address_details' => 'عنوان محفوظ', 'utm_campaign' => 'original'],
            'uploaded_photos' => ['orders/photos/removal/child.jpg'],
        ]);
        Storage::disk('local')->put('orders/photos/removal/child.jpg', 'original-child-photo');
        $this->product = Product::create(['name_ar' => 'ستيكر الطفل', 'slug' => 'removal-sticker', 'price_cents' => 10000,
            'inventory_mode' => 'track_stock', 'stock_quantity' => 5, 'is_active' => true]);
        $this->item = $this->order->items()->create(['item_type' => 'product', 'product_id' => $this->product->id,
            'title' => 'ستيكر الطفل', 'quantity' => 2, 'unit_price_cents' => 10000, 'total_price_cents' => 20000,
            'personalization_snapshot' => ['child_name' => 'ليلى', 'school_name' => 'مدرسة الأمل']]);
        $this->order->items()->create(['item_type' => 'product', 'title' => 'منتج باقٍ', 'quantity' => 1,
            'unit_price_cents' => 20000, 'total_price_cents' => 20000]);
        $this->order->refresh();
    }

    private function payload(?OrderItem $item = null): array
    {
        $item ??= $this->item;
        $option = collect(app(AdminOrderQuickEditService::class)->removalOptions($this->order)['removals'])->firstWhere('id', $item->id);

        return ['request_key' => (string) Str::uuid(), 'confirmed' => '1',
            'removal_fingerprint' => $option['removal_fingerprint'], 'change_reason' => 'طلب العميل حذف العنصر.'];
    }

    private function remove(array $values = [], ?OrderItem $item = null)
    {
        return $this->actingAs($this->admin)->deleteJson(route('admin.orders.groups.items.destroy', [$this->order, $item ?? $this->item]),
            array_replace($this->payload($item), $values));
    }

    private function archived(): Order
    {
        return Order::onlyTrashed()->where('checkout_group_key', $this->order->checkoutGroupKey())->whereHas('items', fn ($query) => $query->whereKey($this->item->id))->firstOrFail();
    }

    private function group(): array
    {
        return app(AdminOrderGroupService::class)->findByRepresentative($this->order->id);
    }

    public function test_removal_requires_explicit_confirmation_reason_and_uuid(): void
    {
        $this->remove(['confirmed' => null])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->remove(['change_reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->remove(['request_key' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->assertSame($this->order->id, $this->item->fresh()->order_id);
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
        $this->assertSame(0, Order::onlyTrashed()->count());
    }

    public function test_delete_permission_is_required_and_update_permission_alone_cannot_delete(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $employee->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update'])->pluck('id'));
        $payload = $this->payload();
        $this->actingAs($employee)->getJson(route('admin.orders.groups.removal-options', $this->order))->assertForbidden();
        $this->actingAs($employee)->deleteJson(route('admin.orders.groups.items.destroy', [$this->order, $this->item]), $payload)->assertForbidden();
        $employee->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.delete'])->pluck('id'));
        $employee->unsetRelation('permissions');
        $this->actingAs($employee)->get(route('admin.orders.groups.show', $this->order))->assertOk()->assertSee('data-quick-open="remove"', false)->assertSee('data-quick-dialog', false);
        $this->actingAs($employee)->getJson(route('admin.orders.groups.removal-options', $this->order))->assertOk()->assertJsonPath('removals.0.child_name', 'ليلى')->assertDontSee('orders/photos/removal');
        $this->actingAs($employee)->deleteJson(route('admin.orders.groups.items.destroy', [$this->order, $this->item]), $payload)->assertOk();
    }

    public function test_removal_archives_exact_line_preserves_cash_photos_other_items_approval_and_records_reason(): void
    {
        $remaining = $this->order->items()->where('id', '!=', $this->item->id)->first();
        $original = $remaining->getAttributes();
        $created = $this->order->created_at;
        $this->order->attachments()->create(['path' => 'orders/keep.pdf', 'original_name' => 'keep.pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        $this->remove()->assertOk();
        $archived = $this->archived();
        $this->assertSame($this->admin->id, $archived->deleted_by_user_id);
        $this->assertSame('طلب العميل حذف العنصر.', $archived->deletion_reason);
        $this->assertSame(['child_name' => 'ليلى', 'school_name' => 'مدرسة الأمل'], $this->item->fresh()->personalization_snapshot);
        $this->assertSame($original, $remaining->fresh()->getAttributes());
        $this->assertSame(7, $this->product->fresh()->stock_quantity);
        $this->assertSame('original-child-photo', Storage::disk('local')->get($archived->uploaded_photos[0]));
        Storage::disk('local')->assertExists($this->order->uploaded_photos);
        $this->assertSame(23000, $this->group()['total_cents']);
        $this->assertSame(30000, $this->group()['paid_amount_cents']);
        $this->assertSame(0, $this->group()['remaining_amount_cents']);
        $this->assertSame(2000, $this->group()['discount_cents']);
        $this->assertSame('ready_preview', $this->order->fresh()->status);
        $this->assertEquals($created, $this->group()['created_at']);
        $this->assertNotNull($this->order->fresh()->preview_approved_at);
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertDatabaseCount('order_group_assignments', 0);
        $event = OrderPaymentEvent::where('source', 'admin_item_removed')->firstOrFail();
        $this->assertSame(0, $event->amount_delta_cents);
        $this->assertFalse($event->affects_collection_stats);
        $log = AdminActivityLog::where('action', 'checkout.item_removed')->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($this->item->id, $log->properties['removed_items'][0]['id']);
        $this->assertSame(2, $log->properties['removed_items'][0]['quantity']);
        $this->actingAs($this->admin)->get(route('admin.orders.groups.show', $this->order))->assertOk()->assertSee('استعادة العنصر')->assertSee('طلب العميل حذف العنصر.');
    }

    public function test_repeated_operation_does_not_duplicate_archive_release_stock_or_adjust_payment_again(): void
    {
        $payload = $this->payload();
        $url = route('admin.orders.groups.items.destroy', [$this->order, $this->item]);
        $this->actingAs($this->admin)->deleteJson($url, $payload)->assertOk();
        $this->deleteJson($url, $payload)->assertOk();
        $this->deleteJson($url, [...$payload, 'change_reason' => 'سبب مختلف لنفس الطلب.'])->assertConflict();
        $this->assertSame(1, Order::onlyTrashed()->count());
        $this->assertSame(7, $this->product->fresh()->stock_quantity);
        $this->assertSame(1, OrderPaymentEvent::where('source', 'admin_item_removed')->count());
    }

    public function test_legacy_paid_checkout_without_initial_ledger_entry_never_creates_a_new_collection(): void
    {
        OrderPaymentEvent::query()->delete();
        $this->remove()->assertOk();
        $this->assertSame(30000, $this->group()['paid_amount_cents']);
        $this->assertSame(0, OrderPaymentEvent::where('affects_collection_stats', true)->count());
        $this->assertSame(1, OrderPaymentEvent::count());
        $this->assertSame(0, OrderPaymentEvent::firstOrFail()->amount_delta_cents);
    }

    public function test_stale_preview_and_foreign_item_cannot_be_removed(): void
    {
        $payload = $this->payload();
        $this->item->update(['quantity' => 3]);
        $this->actingAs($this->admin)->deleteJson(route('admin.orders.groups.items.destroy', [$this->order, $this->item]), $payload)->assertConflict();
        $other = $this->order->replicate();
        $other->order_number = 'HK-OTHER-REMOVE';
        $other->checkout_group_key = 'CHK-OTHER-REMOVE';
        $other->save();
        $this->deleteJson(route('admin.orders.groups.items.destroy', [$other, $this->item]), $this->payload())->assertNotFound();
        $this->assertSame(0, Order::onlyTrashed()->count());
    }

    public function test_last_product_line_cannot_leave_an_empty_checkout(): void
    {
        $this->order->items()->where('id', '!=', $this->item->id)->delete();
        $this->remove()->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->assertFalse($this->order->fresh()->trashed());
    }

    private function story(): OrderItem
    {
        $story = Story::create(['title' => 'قصة ليلى', 'slug' => 'removal-story', 'price' => 100, 'active' => true]);
        $this->order->update(['story_id' => $story->id]);

        return $this->order->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => 'قصة ليلى', 'quantity' => 1,
            'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
    }

    public function test_story_removal_includes_linked_addons_and_keeps_independent_products_and_original_child(): void
    {
        $story = $this->story();
        $addon = $this->order->items()->create(['item_type' => 'product_add_on', 'product_id' => $this->product->id,
            'linked_order_item_id' => $story->id, 'title' => 'إضافة للقصة', 'quantity' => 1, 'unit_price_cents' => 5000, 'total_price_cents' => 5000]);
        $payload = $this->payload($story);
        $url = route('admin.orders.groups.items.destroy', [$this->order, $story]);
        $this->actingAs($this->admin)->deleteJson($url, $payload)->assertOk()->assertJsonPath('redirect_url', route('admin.orders.groups.show', Order::first()));
        $this->deleteJson($url, $payload)->assertOk();
        $this->assertTrue(Order::withTrashed()->find($this->order->id)->trashed());
        $this->assertSame($this->order->id, $addon->fresh()->order_id);
        $this->assertSame($this->order->id, $story->fresh()->order_id);
        $active = Order::firstOrFail();
        $this->assertNull($active->story_id);
        $this->assertSame($active->id, $this->item->fresh()->order_id);
        $this->assertSame('ليلى', $active->child_name);
        $this->assertSame('original-child-photo', Storage::disk('local')->get($active->uploaded_photos[0]));
        $this->assertSame(6, $this->product->fresh()->stock_quantity);
        $this->assertSame(43000, $this->group()['total_cents']);
        $this->assertSame(30000, $this->group()['paid_amount_cents']);
        $payload = $this->payload($this->item);
        $payload['removal_fingerprint'] = str_repeat('a', 64);
        // A deleted representative is accepted for safe retries, but another target still gets its own stale guard.
        $this->actingAs($this->admin)->deleteJson(route('admin.orders.groups.items.destroy', [$this->order, $this->item]), $payload)->assertConflict();
    }

    public function test_story_and_its_only_addon_cannot_be_removed_as_the_last_purchase(): void
    {
        $this->order->items()->delete();
        $story = $this->story();
        $this->order->items()->create(['item_type' => 'product_add_on', 'linked_order_item_id' => $story->id,
            'title' => 'إضافة', 'quantity' => 1, 'unit_price_cents' => 5000, 'total_price_cents' => 5000]);
        $this->remove([], $story)->assertUnprocessable();
        $this->assertSame(2, $this->order->items()->count());
    }

    public function test_restoring_product_reserves_stock_and_reconciles_money_without_new_collection(): void
    {
        $this->remove()->assertOk();
        $archived = $this->archived();
        $this->actingAs($this->admin)->post(route('admin.orders.restore', $archived->id))->assertRedirect();
        $this->assertSame(43000, $this->group()['total_cents']);
        $this->assertSame(30000, $this->group()['paid_amount_cents']);
        $this->assertSame(13000, $this->group()['remaining_amount_cents']);
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
        $this->assertNull($archived->fresh()->deleted_at);
        $this->assertNull(data_get($archived->fresh()->delivery_details, 'quick_removal'));
        $this->assertSame(0, OrderPaymentEvent::where('source', 'admin_item_restored')->firstOrFail()->amount_delta_cents);
    }

    public function test_addon_detaches_from_active_story_and_restoration_reconnects_the_exact_parent(): void
    {
        $story = $this->story();
        $addon = $this->order->items()->create(['item_type' => 'product_add_on', 'linked_order_item_id' => $story->id,
            'title' => 'إضافة', 'quantity' => 1, 'unit_price_cents' => 5000, 'total_price_cents' => 5000]);
        $this->remove([], $addon)->assertOk();
        $this->assertNull($addon->fresh()->linked_order_item_id);
        $archived = Order::onlyTrashed()->findOrFail($addon->fresh()->order_id);
        $this->actingAs($this->admin)->post(route('admin.orders.restore', $archived->id))->assertRedirect();
        $this->assertSame($story->id, $addon->fresh()->linked_order_item_id);
        $this->assertSame(58000, $this->group()['total_cents']);
    }

    public function test_discounts_larger_than_remaining_goods_clamp_total_to_zero_and_keep_real_cash(): void
    {
        $this->order->update(['discount_cents' => 50000]);
        $this->remove()->assertOk();
        $this->assertSame(0, $this->group()['total_cents']);
        $this->assertSame(30000, $this->group()['paid_amount_cents']);
        $this->assertSame(50000, $this->group()['discount_cents']);
    }

    public function test_missing_source_photo_rolls_back_removal_and_stock(): void
    {
        Storage::disk('local')->delete($this->order->uploaded_photos);
        $this->remove()->assertUnprocessable();
        $this->assertSame($this->order->id, $this->item->fresh()->order_id);
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
        $this->assertSame(0, Order::onlyTrashed()->count());
    }

    public function test_late_audit_failure_rolls_back_database_and_removes_only_new_photo_copies(): void
    {
        AdminActivityLog::creating(function (AdminActivityLog $log): void {
            if ($log->action === 'checkout.item_removed') {
                throw new \RuntimeException('Injected audit failure');
            }
        });
        try {
            $this->remove()->assertStatus(500);
            $this->assertSame($this->order->id, $this->item->fresh()->order_id);
            $this->assertSame(5, $this->product->fresh()->stock_quantity);
            $this->assertSame(0, Order::onlyTrashed()->count());
            $this->assertSame(['orders/photos/removal/child.jpg'], Storage::disk('local')->allFiles());
        } finally {
            AdminActivityLog::flushEventListeners();
        }
    }

    public function test_whole_checkout_restore_does_not_resurrect_a_previously_removed_product(): void
    {
        $this->remove()->assertOk();
        $archived = $this->archived();
        $this->actingAs($this->admin)->delete(route('admin.orders.groups.destroy', $this->order), [
            'deletion_reason' => 'حذف عملية الشراء كاملة.', 'confirmation' => $this->group()['short_reference'] ?: $this->order->checkoutGroupKey(),
        ])->assertRedirect();
        $this->post(route('admin.orders.groups.restore', $this->order->id))->assertRedirect();
        $this->assertTrue(Order::withTrashed()->find($archived->id)->trashed());
        $this->assertSame(23000, $this->group()['total_cents']);
    }

    public function test_variant_stock_is_released_and_reserved_without_affecting_base_stock_or_component_ids(): void
    {
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'name_ar' => 'كبير', 'stock_quantity' => 3, 'is_active' => true]);
        $this->item->update(['product_variant_id' => $variant->id]);
        $this->product->productionComponents()->create(['stable_key' => 'cover', 'name' => 'غلاف', 'prompt_template' => 'Cover', 'quantity_per_item' => 1, 'is_active' => true]);
        $this->item->unsetRelation('product');
        app(ProductProductionComponentSnapshotService::class)->captureForItem($this->item);
        $ids = $this->item->productionComponents()->pluck('id')->all();
        $this->assertNotEmpty($ids);
        $this->remove()->assertOk();
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(5, $this->product->fresh()->stock_quantity);
        $this->assertSame($ids, $this->item->productionComponents()->pluck('id')->all());
        $this->post(route('admin.orders.restore', $this->archived()->id))->assertRedirect();
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertSame($ids, $this->item->productionComponents()->pluck('id')->all());
    }

    public function test_story_removal_cancels_its_production_but_keeps_the_project_and_files(): void
    {
        $story = $this->story();
        $project = ProductionProject::create(['order_id' => $this->order->id, 'status' => 'in_progress', 'current_stage' => 'illustration', 'created_by_user_id' => $this->admin->id]);
        $this->order->attachments()->create(['path' => 'orders/retained-production.pdf', 'original_name' => 'production.pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        Storage::disk('local')->put('orders/retained-production.pdf', 'production');
        $this->remove([], $story)->assertOk();
        $this->assertSame('cancelled', $project->fresh()->status);
        $this->assertDatabaseHas('production_projects', ['id' => $project->id]);
        $this->assertDatabaseCount('order_attachments', 1);
        Storage::disk('local')->assertExists('orders/retained-production.pdf');
    }

    public function test_standalone_removed_representative_redirects_and_retries_safely(): void
    {
        $sibling = $this->order->replicate();
        $sibling->order_number = 'HK-REMOVAL-SIBLING';
        $sibling->save();
        $keep = $this->order->items()->where('id', '!=', $this->item->id)->firstOrFail();
        $keep->update(['order_id' => $sibling->id]);
        $payload = $this->payload();
        $url = route('admin.orders.groups.items.destroy', [$this->order, $this->item]);
        $this->actingAs($this->admin)->deleteJson($url, $payload)->assertOk()->assertJsonPath('redirect_url', route('admin.orders.groups.show', $sibling));
        $this->deleteJson($url, $payload)->assertOk();
        $this->assertSame($this->order->id, $this->archived()->id);
        $this->assertSame(23000, $this->group()['total_cents']);
        $this->assertSame(7, $this->product->fresh()->stock_quantity);
        $this->assertSame(1, AdminActivityLog::where('action', 'checkout.item_removed')->count());
    }

    public function test_restoring_addon_while_its_story_is_removed_is_rejected_without_stock_or_money_changes(): void
    {
        $story = $this->story();
        $addon = $this->order->items()->create(['item_type' => 'product_add_on', 'product_id' => $this->product->id,
            'linked_order_item_id' => $story->id, 'title' => 'إضافة', 'quantity' => 1, 'unit_price_cents' => 5000, 'total_price_cents' => 5000]);
        $this->remove([], $addon)->assertOk();
        $archived = Order::onlyTrashed()->findOrFail($addon->fresh()->order_id);
        $this->remove([], $story)->assertOk();
        $total = $this->group()['total_cents'];
        $this->postJson(route('admin.orders.restore', $archived->id))->assertUnprocessable()->assertJsonValidationErrors('restore');
        $this->assertSame(6, $this->product->fresh()->stock_quantity);
        $this->assertSame($total, $this->group()['total_cents']);
        $this->assertTrue(Order::withTrashed()->find($archived->id)->trashed());
    }
}
