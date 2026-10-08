<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderSceneTextService;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderQuickEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->order = Order::create([
            'order_number' => 'HK-QUICK-BASE', 'checkout_group_key' => 'CHK-QUICK-BASE',
            'parent_name' => 'ولي الأمر', 'child_name' => 'ليلى', 'child_age' => 7, 'child_gender' => 'girl',
            'status' => 'ready_preview', 'order_source' => 'website', 'paid_amount_cents' => 30000,
            'payment_status' => 'paid_in_full', 'payment_method' => 'انستاباي',
            'delivery_details' => ['phone' => '01012345678', 'alternate_phone' => null, 'address_details' => 'عنوان محفوظ',
                'delivery_fee' => 50, 'total' => 300, 'bosta_district_id' => 'original-district', 'utm_campaign' => 'unchanged'],
            'uploaded_photos' => ['orders/photos/quick/existing.jpg'],
        ]);
        Storage::disk('local')->put('orders/photos/quick/existing.jpg', 'original-photo');
        $this->order->items()->create(['item_type' => 'product', 'title' => 'منتج موجود',
            'quantity' => 1, 'unit_price_cents' => 25000, 'total_price_cents' => 25000]);
        $this->order->refresh();
    }

    public function test_order_page_exposes_compact_scoped_actions_and_reason_near_save(): void
    {
        $this->actingAs($this->admin)->get(route('admin.orders.groups.show', $this->order))
            ->assertOk()->assertSee('data-quick-open="contact"', false)->assertSee('data-quick-open="add"', false)
            ->assertSee('data-quick-dialog', false)->assertSee('quick-change-reason', false);
    }

    public function test_adding_product_preserves_actual_payment_old_items_statuses_and_attachments(): void
    {
        $product = $this->product();
        $oldItem = $this->order->items()->firstOrFail();
        $this->order->attachments()->create(['path' => 'orders/keep.pdf', 'original_name' => 'keep.pdf', 'mime_type' => 'application/pdf', 'size' => 10, 'expires_at' => now()->addYear()]);
        Storage::disk('local')->put('orders/keep.pdf', 'keep');
        $old = $this->order->getAttributes();
        $this->add($product)->assertOk();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($this->order->id);
        $this->assertSame(50000, $group['total_cents']);
        $this->assertSame(30000, $group['paid_amount_cents']);
        $this->assertSame(20000, $group['remaining_amount_cents']);
        $this->assertSame('partially_paid', $group['payment_status']);
        $this->assertSame($oldItem->getAttributes(), $oldItem->fresh()->getAttributes());
        $this->assertSame('ready_preview', $this->order->fresh()->status);
        $this->assertSame($old['child_name'], $this->order->fresh()->child_name);
        $this->assertSame('original-district', data_get($this->order->fresh()->delivery_details, 'bosta_district_id'));
        $this->assertDatabaseCount('order_attachments', 1);
        Storage::disk('local')->assertExists('orders/keep.pdf');
        $event = OrderPaymentEvent::where('source', 'admin_product_added')->firstOrFail();
        $this->assertSame(0, $event->amount_delta_cents);
        $this->assertFalse($event->affects_collection_stats);
        $log = AdminActivityLog::where('action', 'checkout.product_added')->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('طلب العميل إضافة منتج.', $log->properties['reason']);
        $this->assertCount(1, $log->properties['added_items']);
    }

    public function test_duplicate_add_retry_creates_one_item_and_does_not_decrement_stock_twice(): void
    {
        $product = $this->product(['inventory_mode' => 'track_stock', 'stock_quantity' => 5]);
        $key = (string) Str::uuid();
        $this->add($product, ['request_key' => $key])->assertOk();
        $this->add($product, ['request_key' => $key])->assertOk();
        $this->assertSame(1, OrderItem::where('product_id', $product->id)->count());
        $this->assertSame(4, $product->fresh()->stock_quantity);
        $this->add($product, ['request_key' => $key, 'quantity' => 2])->assertStatus(409);
    }

    public function test_reuse_child_copies_exact_data_and_private_photos_without_mutating_source(): void
    {
        $product = $this->personalized();
        $oldPhotos = $this->order->uploaded_photos;
        $this->add($product, ['reuse_child_order_id' => $this->order->id, 'quantity' => 2])->assertOk();
        $items = OrderItem::with('order')->where('product_id', $product->id)->get();
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertSame('ليلى', $item->personalization_snapshot['child_name']);
            $this->assertSame('girl', $item->order->child_gender);
            $this->assertSame($oldPhotos, $item->order->uploaded_photos);
            $this->assertSame(1, $item->quantity);
            $this->assertSame(1, $item->personalization_snapshot['uploaded_photos_count']);
        }
        $this->assertSame($oldPhotos, $this->order->fresh()->uploaded_photos);
    }

    public function test_reused_data_can_be_adjusted_without_editing_source_child(): void
    {
        $product = $this->personalized();
        $this->add($product, ['reuse_child_order_id' => $this->order->id,
            'personalization' => ['child_name' => 'ليلى أحمد', 'school_name' => 'مدرسة الأمل']])->assertOk();
        $item = OrderItem::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('ليلى أحمد', $item->personalization_snapshot['child_name']);
        $this->assertSame('مدرسة الأمل', $item->personalization_snapshot['school_name']);
        $this->assertSame('ليلى', $this->order->fresh()->child_name);
    }

    public function test_reuse_rejects_child_from_another_checkout(): void
    {
        $outside = $this->order->replicate();
        $outside->order_number = 'HK-OUTSIDE';
        $outside->checkout_group_key = 'CHK-OUTSIDE';
        $outside->save();
        $this->add($this->personalized(), ['reuse_child_order_id' => $outside->id])->assertUnprocessable()->assertJsonValidationErrors('reuse_child_order_id');
        $this->assertSame(2, Order::count());
    }

    public function test_new_personalization_validates_required_data_and_stores_new_images(): void
    {
        $product = $this->personalized();
        $this->add($product)->assertUnprocessable();
        $this->add($product, ['personalization' => ['child_name' => 'محمد', 'child_age' => 6, 'child_gender' => 'boy'],
            'photos' => [UploadedFile::fake()->image('new.jpg')]])->assertOk();
        $item = OrderItem::with('order')->where('product_id', $product->id)->firstOrFail();
        $this->assertSame('محمد', $item->order->child_name);
        Storage::disk('local')->assertExists($item->order->uploaded_photos[0]);
        $this->assertCount(1, $item->order->uploaded_photos);
    }

    public function test_new_product_with_multiple_options_requires_a_current_variant_and_rejects_foreign_and_inactive_variants(): void
    {
        $product = $this->product();
        $variant = ProductVariant::create(['product_id' => $product->id, 'name_ar' => 'كبير', 'price_adjustment_cents' => 1000, 'is_active' => true]);
        ProductVariant::create(['product_id' => $product->id, 'name_ar' => 'صغير', 'is_active' => true]);
        $this->add($product)->assertUnprocessable();
        $other = ProductVariant::create(['product_id' => $this->product()->id, 'name_ar' => 'آخر', 'is_active' => true]);
        $this->add($product, ['variant_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('variant_id');
        $variant->update(['is_active' => false]);
        $this->add($product, ['variant_id' => $variant->id])->assertUnprocessable();
        $variant->update(['is_active' => true]);
        $this->add($product, ['variant_id' => $variant->id])->assertOk();
        $item = OrderItem::where('product_id', $product->id)->firstOrFail();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame(21000, $item->unit_price_cents);
    }

    public function test_out_of_stock_add_is_atomic(): void
    {
        $product = $this->product(['inventory_mode' => 'track_stock', 'stock_quantity' => 1]);
        $this->add($product, ['quantity' => 2])->assertUnprocessable();
        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
    }

    public function test_contact_update_does_not_validate_or_rebuild_products_addresses_or_finances(): void
    {
        $sibling = $this->order->replicate();
        $sibling->order_number = 'HK-SIBLING';
        $sibling->save();
        $oldItems = OrderItem::get()->toArray();
        $this->actingAs($this->admin)->patchJson(route('admin.orders.groups.contact', $this->order), [
            'parent_name' => 'اسم مصحح', 'phone' => '01112345678', 'alternate_phone' => '+447911123456', 'change_reason' => 'تصحيح رقم العميل واسمه.',
        ])->assertOk();
        foreach ([$this->order->fresh(), $sibling->fresh()] as $order) {
            $this->assertSame('اسم مصحح', $order->parent_name);
            $this->assertSame('01112345678', $order->delivery_details['phone']);
            $this->assertSame('عنوان محفوظ', $order->delivery_details['address_details']);
            $this->assertSame('original-district', $order->delivery_details['bosta_district_id']);
            $this->assertSame('unchanged', $order->delivery_details['utm_campaign']);
            $this->assertSame(30000, $order->paid_amount_cents);
            $this->assertSame('ready_preview', $order->status);
        }
        $this->assertSame($oldItems, OrderItem::get()->toArray());
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'checkout.contact_updated', 'user_id' => $this->admin->id]);
    }

    public function test_invalid_changed_phone_rejected_but_unchanged_legacy_phone_does_not_block_name_edit(): void
    {
        $delivery = $this->order->delivery_details;
        $delivery['phone'] = '1000111426';
        $this->order->update(['delivery_details' => $delivery]);
        $payload = ['parent_name' => 'اسم جديد', 'phone' => '123', 'change_reason' => 'تصحيح اسم ولي الأمر.'];
        $this->actingAs($this->admin)->patchJson(route('admin.orders.groups.contact', $this->order), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $payload['phone'] = '1000111426';
        $this->actingAs($this->admin)->patchJson(route('admin.orders.groups.contact', $this->order), $payload)->assertOk();
    }

    public function test_scoped_name_edit_does_not_resubmit_or_overwrite_newer_contact_data(): void
    {
        $delivery = $this->order->delivery_details;
        $delivery['phone'] = '01112345678';
        $delivery['alternate_phone'] = '+447911123456';
        $this->order->update(['delivery_details' => $delivery]);
        $this->actingAs($this->admin)->patchJson(route('admin.orders.groups.contact', $this->order), [
            'parent_name' => 'اسم مصحح', 'change_reason' => 'تصحيح الاسم فقط بعد تعديل زميل لرقم الهاتف.',
        ])->assertOk();
        $this->assertSame('اسم مصحح', $this->order->fresh()->parent_name);
        $this->assertSame($delivery, $this->order->delivery_details);
    }

    public function test_item_update_preserves_item_component_ids_other_child_values_and_prices(): void
    {
        $product = $this->personalized();
        $product->productionComponents()->create(['stable_key' => 'card', 'name' => 'بطاقة', 'prompt_template' => 'Card', 'quantity_per_item' => 1, 'is_active' => true]);
        $this->add($product, ['reuse_child_order_id' => $this->order->id])->assertOk();
        $item = OrderItem::with('order')->where('product_id', $product->id)->firstOrFail();
        $componentIds = $item->productionComponents()->pluck('id')->all();
        $this->updateItem($item, ['personalization' => ['school_name' => 'مدرسة جديدة'], 'photos' => [UploadedFile::fake()->image('supplement.jpg')]])->assertOk();
        $item->refresh();
        $this->assertSame($componentIds, $item->productionComponents()->pluck('id')->all());
        $this->assertSame('مدرسة جديدة', $item->personalization_snapshot['school_name']);
        $this->assertSame('ليلى', $item->personalization_snapshot['child_name']);
        $this->assertSame(20000, $item->unit_price_cents);
        $this->assertCount(2, $item->order->uploaded_photos);
        $this->assertSame(2, $item->personalization_snapshot['fields']['photos']['value']);
        $this->assertCount(1, $this->order->fresh()->uploaded_photos);
        $log = AdminActivityLog::where('action', 'order.product_details_updated')->firstOrFail();
        $this->assertSame('مدرسة جديدة', $log->properties['changes']['school_name']['new']);
        $this->assertSame(['supplement.jpg'], $log->properties['file_names']);
    }

    public function test_item_edit_uses_purchased_schema_not_later_product_requirements(): void
    {
        $product = $this->personalized();
        $this->add($product, ['reuse_child_order_id' => $this->order->id])->assertOk();
        $item = OrderItem::where('product_id', $product->id)->firstOrFail();
        $product->update(['personalization_fields' => ['class_name' => ['enabled' => true, 'required' => true]]]);
        $this->updateItem($item, ['personalization' => ['child_name' => 'اسم صحيح']])->assertOk();
        $this->assertSame('اسم صحيح', $item->fresh()->personalization_snapshot['child_name']);
    }

    public function test_item_edit_rejects_cross_checkout_item_and_unsupported_fields(): void
    {
        $product = $this->personalized();
        $this->add($product, ['reuse_child_order_id' => $this->order->id])->assertOk();
        $item = OrderItem::where('product_id', $product->id)->firstOrFail();
        $this->updateItem($item, ['personalization' => ['admin_secret' => 'invalid']])->assertUnprocessable();
        $outside = $this->order->replicate();
        $outside->order_number = 'HK-OUTSIDE';
        $outside->checkout_group_key = 'CHK-OUTSIDE';
        $outside->save();
        $this->actingAs($this->admin)->patchJson(route('admin.orders.groups.products.update', [$outside, $item]),
            ['personalization' => ['child_name' => 'Wrong'], 'change_reason' => 'لا يجب السماح بهذا التعديل.'])->assertNotFound();
        $this->assertSame('ليلى', $item->fresh()->personalization_snapshot['child_name']);
    }

    public function test_invalid_photo_rolls_back_item_changes_and_keeps_existing_photos(): void
    {
        $product = $this->personalized();
        $this->add($product, ['reuse_child_order_id' => $this->order->id])->assertOk();
        $item = OrderItem::where('product_id', $product->id)->firstOrFail();
        $this->updateItem($item, ['personalization' => ['child_name' => 'Should rollback'], 'photos' => [UploadedFile::fake()->create('not-image.pdf', 1, 'application/pdf')]])->assertUnprocessable();
        $this->assertSame('ليلى', $item->fresh()->personalization_snapshot['child_name']);
        Storage::disk('local')->assertExists('orders/photos/quick/existing.jpg');
        $this->assertCount(1, $item->order->uploaded_photos);
    }

    public function test_update_permission_and_photo_permission_remain_enforced(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $this->actingAs($limited)->getJson(route('admin.orders.groups.quick-edit-options', $this->order))->assertForbidden();
        $limited->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update'])->pluck('id'));
        $limited->unsetRelation('permissions');
        $options = $this->actingAs($limited)->getJson(route('admin.orders.groups.quick-edit-options', $this->order))->assertOk();
        $options->assertJsonPath('children.0.photos', []);
        $this->actingAs($limited)->postJson(route('admin.orders.groups.products.store', $this->order), [
            'request_key' => (string) Str::uuid(), 'product_id' => $this->personalized()->id, 'quantity' => 1,
            'reuse_child_order_id' => $this->order->id, 'change_reason' => 'إعادة استخدام صور الطفل.',
        ])->assertForbidden();
    }

    public function test_lookup_is_read_only_and_does_not_leak_raw_storage_paths(): void
    {
        $before = $this->order->getAttributes();
        $response = $this->actingAs($this->admin)->getJson(route('admin.orders.groups.quick-edit-options', $this->order))->assertOk();
        $response->assertDontSee('orders/photos/quick/existing.jpg')->assertSee('thumbnail');
        $this->assertSame($before, $this->order->fresh()->getAttributes());
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_reason_required_for_each_scoped_write(): void
    {
        $this->add($this->product(), ['change_reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->actingAs($this->admin)->patchJson(route('admin.orders.groups.contact', $this->order), ['parent_name' => 'Test', 'phone' => '01012345678'])
            ->assertUnprocessable()->assertJsonValidationErrors('change_reason');
    }

    public function test_add_on_requires_a_story_in_same_checkout_and_inherits_child(): void
    {
        $product = $this->product(['personalization_mode' => 'inherit_from_linked_story', 'purchase_mode' => 'add_on_only']);
        $this->add($product, ['linked_order_id' => $this->order->id])->assertUnprocessable();
        $story = Story::create(['title' => 'قصة اختبار', 'slug' => 'quick-story', 'language' => 'ar', 'gender' => 'both', 'active' => true, 'price' => 100]);
        $this->order->update(['story_id' => $story->id]);
        $this->order->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => $story->title, 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        $this->add($product, ['linked_order_id' => $this->order->id])->assertOk();
        $item = OrderItem::where('product_id', $product->id)->firstOrFail();
        $this->assertSame('product_add_on', $item->item_type);
        $this->assertSame($this->order->id, $item->order_id);
        $this->assertSame('ليلى', $item->personalization_snapshot['child_name']);
        $this->updateItem($item, ['personalization' => ['child_name' => 'Other']])->assertNotFound();
    }

    public function test_existing_discount_and_unpaid_balance_are_preserved_when_adding(): void
    {
        $this->order->update(['discount_cents' => 5000, 'discount_reason' => 'خصم موجود', 'paid_amount_cents' => 0, 'payment_status' => 'unpaid']);
        $this->add($this->product())->assertOk();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($this->order->id);
        $this->assertSame(5000, $group['discount_cents']);
        $this->assertSame(45000, $group['total_cents']);
        $this->assertSame(0, $group['paid_amount_cents']);
        $this->assertSame('unpaid', $group['payment_status']);
    }

    public function test_story_quick_edit_syncs_scenes_and_keeps_other_checkout_units_and_money(): void
    {
        $story = Story::create(['title' => 'قصة سريعة', 'slug' => 'quick-story-edit', 'language' => 'ar', 'active' => true, 'price' => 100]);
        $story->sceneTemplates()->create(['scene_number' => 1, 'text_template' => 'ابتسم {{child_name}}',
            'alternate_text_template' => 'ابتسمت {{child_name}}', 'english_female_text_template' => 'She smiled, {{child_name}}.']);
        $this->order->update(['story_id' => $story->id, 'language' => 'ar']);
        $this->order->items()->where('item_type', 'product')->delete();
        $item = $this->order->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => $story->title,
            'quantity' => 1, 'unit_price_cents' => 25000, 'total_price_cents' => 25000]);
        app(OrderSceneTextService::class)->snapshotForOrder($this->order, $story);
        $sceneId = $this->order->sceneTextSnapshots()->firstOrFail()->id;
        $this->add($this->product())->assertOk();
        $productOrder = Order::whereKeyNot($this->order->id)->firstOrFail();
        $otherBefore = $productOrder->getAttributes();
        $this->actingAs($this->admin)->patchJson(route('admin.orders.quick-story-details', $this->order), [
            'child_name' => 'ليلى أحمد', 'language' => 'en', 'gift_note' => 'إهداء جديد',
            'photos' => [UploadedFile::fake()->image('story-more.jpg')], 'change_reason' => 'طلب العميل تغيير اللغة والإهداء.',
        ])->assertOk();
        $scene = $this->order->sceneTextSnapshots()->firstOrFail();
        $this->assertSame($sceneId, $scene->id);
        $this->assertSame('She smiled, ليلى أحمد.', $scene->rendered_text);
        $this->assertSame('en', $this->order->fresh()->language);
        $this->assertSame('إهداء جديد', $this->order->fresh()->gift_note);
        $this->assertSame('ready_preview', $this->order->fresh()->status);
        $this->assertSame($otherBefore, $productOrder->fresh()->getAttributes());
        $this->assertSame(2, $item->fresh()->personalization_snapshot['uploaded_photos_count']);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'order.details_updated', 'subject_id' => $this->order->id]);
    }

    public function test_failure_after_storing_photo_rolls_back_database_stock_and_cleans_only_new_files(): void
    {
        $product = $this->personalized();
        $product->update(['inventory_mode' => 'track_stock', 'stock_quantity' => 5]);
        AdminActivityLog::creating(function (AdminActivityLog $log): void {
            if ($log->action === 'checkout.product_added') {
                throw new \RuntimeException('Simulated late transaction failure');
            }
        });
        $this->add($product, ['reuse_child_order_id' => $this->order->id, 'photos' => [UploadedFile::fake()->image('new.jpg')]])->assertStatus(500);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
        $this->assertSame(['orders/photos/quick/existing.jpg'], Storage::disk('local')->allFiles());
    }

    public function test_personalized_add_respects_twenty_item_limit_without_partial_insert(): void
    {
        $product = $this->personalized();
        $this->add($product, ['quantity' => 20, 'reuse_child_order_id' => $this->order->id])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('order_items', 1);
        $this->add($product, ['quantity' => 19, 'reuse_child_order_id' => $this->order->id])->assertOk();
        $this->assertDatabaseCount('order_items', 20);
        $this->add($this->product())->assertUnprocessable();
    }

    public function test_no_raw_photo_path_from_request_can_be_injected_into_reused_child(): void
    {
        $product = $this->personalized();
        $this->add($product, ['reuse_child_order_id' => $this->order->id, 'reused_photos' => ['orders/another-customer.jpg']])->assertOk();
        $item = OrderItem::with('order')->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(['orders/photos/quick/existing.jpg'], $item->order->uploaded_photos);
    }

    public function test_shared_legacy_carrier_is_not_mutated_by_single_product_edit(): void
    {
        $product = $this->personalized();
        $item = $this->order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar,
            'personalization_mode' => 'collect_child_details', 'quantity' => 1, 'unit_price_cents' => 20000, 'total_price_cents' => 20000]);
        $this->order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => 'منتج طفل آخر',
            'personalization_mode' => 'collect_child_details', 'quantity' => 1, 'unit_price_cents' => 20000, 'total_price_cents' => 20000]);
        $this->updateItem($item, ['personalization' => ['child_name' => 'Different child']])->assertUnprocessable();
        $this->assertSame('ليلى', $this->order->fresh()->child_name);
    }

    public function test_unrepresented_legacy_price_cannot_be_silently_lost_when_adding_product(): void
    {
        $this->order->items()->delete();
        $delivery = $this->order->delivery_details;
        $delivery['item_price'] = 250;
        $this->order->update(['delivery_details' => $delivery]);
        $this->add($this->product())->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 0);
    }

    private function product(array $attributes = []): Product
    {
        return Product::create($attributes + ['name_ar' => 'منتج جديد', 'slug' => 'quick-'.Str::random(8), 'price_cents' => 20000,
            'is_active' => true, 'personalization_mode' => 'none', 'purchase_mode' => 'standalone']);
    }

    private function personalized(): Product
    {
        return $this->product(['personalization_mode' => 'collect_child_details', 'personalization_fields' => ProductPersonalizationSchema::normalize(['fields' => [
            'child_name' => ['enabled' => true, 'required' => true], 'child_age' => ['enabled' => true, 'required' => true],
            'child_gender' => ['enabled' => true, 'required' => true], 'school_name' => ['enabled' => true, 'required' => false],
            'photos' => ['enabled' => true, 'required' => true, 'min_files' => 1, 'max_files' => 3],
        ]])]);
    }

    private function add(Product $product, array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson(route('admin.orders.groups.products.store', $this->order),
            $overrides + ['request_key' => (string) Str::uuid(), 'product_id' => $product->id, 'quantity' => 1, 'change_reason' => 'طلب العميل إضافة منتج.']);
    }

    private function updateItem(OrderItem $item, array $overrides)
    {
        return $this->actingAs($this->admin)->patchJson(route('admin.orders.groups.products.update', [$this->order, $item]),
            $overrides + ['change_reason' => 'تصحيح بيانات المنتج وصور الطفل.']);
    }
}
