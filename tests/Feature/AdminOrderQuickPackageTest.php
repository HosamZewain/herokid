<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderQuickPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    private Story $story;

    private Product $product;

    private PricingPackage $package;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['photo_uploads.min_files' => 2, 'photo_uploads.max_files' => 3]);
        Setting::updateOrCreate(['key' => 'story_global_price_enabled'], ['value' => '0']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->order = Order::create([
            'order_number' => 'HK-PACK-BASE', 'checkout_group_key' => 'CHK-PACK-BASE',
            'parent_name' => 'ولي الأمر', 'child_name' => 'ليلى', 'child_age' => 7, 'child_gender' => 'girl',
            'status' => 'ready_preview', 'order_source' => 'website', 'paid_amount_cents' => 30000,
            'payment_status' => 'paid_in_full', 'payment_method' => 'انستاباي', 'preview_approved_at' => now(),
            'delivery_details' => ['phone' => '01012345678', 'delivery_fee' => 50, 'total' => 300, 'address_details' => 'عنوان محفوظ'],
            'uploaded_photos' => ['orders/photos/one.jpg', 'orders/photos/two.jpg'],
        ]);
        foreach ($this->order->uploaded_photos as $path) {
            Storage::disk('local')->put($path, 'original-photo');
        }
        $this->order->items()->create(['item_type' => 'product', 'title' => 'منتج سابق', 'quantity' => 1, 'unit_price_cents' => 25000, 'total_price_cents' => 25000]);
        $this->story = Story::create(['title' => 'رحلة الفضاء', 'slug' => 'package-space', 'price' => 200, 'language' => 'ar', 'active' => true, 'lesson_value' => 'الشجاعة']);
        $this->product = Product::create(['name_ar' => 'بوستر الطفل', 'slug' => 'package-poster', 'price_cents' => 10000,
            'is_active' => true, 'inventory_mode' => 'track_stock', 'stock_quantity' => 10,
            'personalization_mode' => 'collect_child_details', 'personalization_fields' => ['fields' => [
                'child_name' => ['enabled' => true, 'required' => true],
                'photos' => ['enabled' => true, 'required' => true, 'min_files' => 1, 'max_files' => 1],
            ]]]);
        $this->package = PricingPackage::create(['name' => 'باقة الطفل', 'slug' => 'quick-package', 'active' => true,
            'show_in_store' => true, 'price' => 449.99, 'story_count' => 2, 'applies_to_all_stories' => true]);
        $this->package->items()->create(['product_id' => $this->product->id, 'quantity' => 2]);
    }

    private function add(array $overrides = [])
    {
        return $this->actingAs($this->admin)->postJson(route('admin.orders.groups.packages.store', $this->order), array_replace([
            'request_key' => (string) Str::uuid(), 'pricing_package_id' => $this->package->id,
            'expected_price_cents' => 44999, 'story_ids' => [$this->story->id, $this->story->id],
            'reuse_child_order_id' => $this->order->id, 'change_reason' => 'طلب العميل إضافة باقة.',
        ], $overrides));
    }

    public function test_package_addition_preserves_original_payment_items_approval_shipping_and_group(): void
    {
        $original = $this->order->items()->first()->getAttributes();
        $this->add(['price' => 1, 'language' => 'en', 'gift_note' => 'إهداء ليلى'])->assertOk();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($this->order->id);
        $this->assertSame(74999, $group['total_cents']);
        $this->assertSame(30000, $group['paid_amount_cents']);
        $this->assertSame(44999, $group['remaining_amount_cents']);
        $this->assertSame(5000, $group['delivery_cents']);
        $this->assertSame($original, $this->order->items()->first()->getAttributes());
        $this->assertSame('ready_preview', $this->order->fresh()->status);
        $this->assertNotNull($this->order->fresh()->preview_approved_at);
        $this->assertSame(1, Order::distinct()->count('checkout_group_key'));
        $added = OrderItem::where('id', '!=', $original['id'])->get();
        $this->assertCount(3, $added);
        $this->assertSame(44999, (int) $added->sum('total_price_cents'));
        $this->assertCount(1, $added->pluck('item_snapshot.package.instance_key')->unique());
        foreach (Order::whereNotNull('story_id')->get() as $order) {
            $this->assertSame('ليلى', $order->child_name);
            $this->assertSame('en', $order->language);
            $this->assertSame('إهداء ليلى', $order->gift_note);
            $this->assertSame('new', $order->status);
            $this->assertNull($order->preview_approved_at);
            $this->assertCount(2, $order->uploaded_photos);
            $this->assertSame(13, $order->sceneTextSnapshots()->count());
            $this->assertEmpty(array_intersect($this->order->uploaded_photos, $order->uploaded_photos));
        }
        $productItem = $added->firstWhere('product_id', $this->product->id);
        $this->assertSame(2, $productItem->quantity);
        $this->assertCount(1, $productItem->order->uploaded_photos);
        $this->assertSame('ليلى', $productItem->personalization_snapshot['child_name']);
        $this->assertSame(8, $this->product->fresh()->stock_quantity);
        $event = OrderPaymentEvent::where('source', 'admin_package_added')->firstOrFail();
        $this->assertSame(0, $event->amount_delta_cents);
        $this->assertFalse($event->affects_collection_stats);
        $log = AdminActivityLog::where('action', 'checkout.package_added')->firstOrFail();
        $this->assertSame(30000, $log->properties['paid_amount_preserved_cents']);
    }

    public function test_options_and_page_offer_only_available_packages_and_allowed_stories(): void
    {
        $this->package->update(['applies_to_all_stories' => false]);
        $this->package->eligibleStories()->sync([$this->story->id]);
        $this->actingAs($this->admin)->get(route('admin.orders.groups.show', $this->order))->assertOk()->assertSee('data-quick-open="add-package"', false);
        $response = $this->actingAs($this->admin)->getJson(route('admin.orders.groups.quick-edit-options', $this->order))->assertOk();
        $response->assertJsonPath('packages.0.price_cents', 44999)->assertJsonPath('packages.0.story_ids', [$this->story->id]);
        $this->product->update(['stock_quantity' => 0]);
        $this->actingAs($this->admin)->getJson(route('admin.orders.groups.quick-edit-options', $this->order))->assertJsonPath('packages', []);
    }

    public function test_retries_do_not_duplicate_components_stock_or_payment_events(): void
    {
        $key = (string) Str::uuid();
        $this->add(['request_key' => $key])->assertOk();
        $this->add(['request_key' => $key])->assertOk();
        $this->add(['request_key' => $key, 'language' => 'en'])->assertStatus(409);
        $this->assertDatabaseCount('order_items', 4);
        $this->assertSame(8, $this->product->fresh()->stock_quantity);
        $this->assertSame(1, AdminActivityLog::where('action', 'checkout.package_added')->count());
    }

    public function test_stale_price_invalid_selection_missing_photos_and_inactive_package_are_rejected(): void
    {
        $this->add(['expected_price_cents' => 1])->assertStatus(409);
        $this->add(['story_ids' => [$this->story->id]])->assertUnprocessable();
        $this->add(['story_ids' => [99999, $this->story->id]])->assertUnprocessable();
        $this->add(['reuse_child_order_id' => null, 'personalization' => ['child_name' => 'عمر', 'child_age' => 7, 'child_gender' => 'boy']])->assertUnprocessable();
        $this->package->update(['active' => false]);
        $this->add()->assertUnprocessable();
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
    }

    public function test_same_customer_previous_child_allowed_other_customer_rejected(): void
    {
        $previous = $this->order->replicate();
        $previous->order_number = 'HK-PREVIOUS';
        $previous->checkout_group_key = 'CHK-PREVIOUS';
        $previous->save();
        $this->add(['reuse_child_order_id' => $previous->id])->assertOk();
        $previous->update(['delivery_details' => ['phone' => '01199999999']]);
        $this->add(['reuse_child_order_id' => $previous->id])->assertUnprocessable();
    }

    public function test_manual_new_child_and_uploaded_photos(): void
    {
        $this->add(['reuse_child_order_id' => null, 'personalization' => ['child_name' => 'عمر', 'child_age' => 8, 'child_gender' => 'boy'],
            'photos' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]])->assertOk();
        $this->assertSame('عمر', Order::whereNotNull('story_id')->first()->child_name);
        $this->assertSame('ليلى', $this->order->fresh()->child_name);
    }

    public function test_product_only_ready_package_requires_no_child_and_preserves_discount(): void
    {
        $this->package->update(['story_count' => 0, 'price' => 150]);
        $this->product->update(['personalization_mode' => 'none', 'personalization_fields' => null]);
        $this->order->update(['discount_cents' => 2000, 'discount_reason' => 'خصم سابق']);
        $this->add(['story_ids' => [], 'reuse_child_order_id' => null, 'expected_price_cents' => 15000])->assertOk();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($this->order->id);
        $this->assertSame(43000, $group['total_cents']);
        $this->assertSame(2000, $group['discount_cents']);
        $this->assertSame(30000, $group['paid_amount_cents']);
    }

    public function test_photo_and_order_update_permissions_are_required(): void
    {
        $this->admin->permissions()->sync(Permission::whereIn('key', ['orders.view'])->pluck('id'));
        $this->admin->unsetRelation('permissions');
        $this->add()->assertForbidden();
        $this->admin->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update'])->pluck('id'));
        $this->admin->unsetRelation('permissions');
        $this->add()->assertForbidden();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_package_story_pool_and_item_limit_are_enforced(): void
    {
        $this->package->update(['applies_to_all_stories' => false]);
        $other = $this->story->replicate();
        $other->slug = 'allowed-only';
        $other->save();
        $this->package->eligibleStories()->sync([$other->id]);
        $this->add()->assertUnprocessable()->assertJsonValidationErrors('story_ids');
        config(['orders.admin_max_items' => 3]);
        $this->add(['story_ids' => [$other->id, $other->id]])->assertUnprocessable()->assertJsonValidationErrors('pricing_package_id');
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_fixed_variant_and_linked_product_follow_package_not_client(): void
    {
        $this->product->update(['purchase_mode' => 'add_on_only', 'personalization_mode' => 'inherit_from_linked_story']);
        $variant = $this->product->variants()->create(['name_ar' => 'مقاس كبير', 'sku' => 'BIG-PACK', 'price_override_cents' => 12000, 'is_active' => true, 'stock_quantity' => 4]);
        $this->package->items()->update(['product_variant_id' => $variant->id]);
        $this->add(['variant_id' => 999])->assertOk();
        $item = OrderItem::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame('product_add_on', $item->item_type);
        $this->assertNotNull($item->linked_order_item_id);
        $this->assertSame('ليلى', $item->order->child_name);
        $this->assertSame(2, $variant->fresh()->stock_quantity);
        $this->assertSame(10, $this->product->fresh()->stock_quantity);
    }

    public function test_shared_stock_pool_shortage_rolls_back_even_after_story_creation(): void
    {
        $this->product->update(['stock_quantity' => 3]);
        $variant = $this->product->variants()->create(['name_ar' => 'خيار إضافي', 'sku' => 'OTHER-PACK', 'is_active' => true, 'stock_quantity' => null]);
        $this->package->items()->create(['product_id' => $this->product->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
        $this->add()->assertUnprocessable();
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(3, $this->product->fresh()->stock_quantity);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_missing_saved_photo_does_not_leave_partial_package(): void
    {
        Storage::disk('local')->delete($this->order->uploaded_photos[0]);
        $this->add()->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertDatabaseCount('order_items', 1);
    }

    public function test_personalized_product_only_package_accepts_one_photo_and_required_fields(): void
    {
        $this->package->update(['story_count' => 0, 'price' => 150]);
        $this->add(['story_ids' => [], 'reuse_child_order_id' => null, 'expected_price_cents' => 15000,
            'personalization' => ['child_name' => 'عمر'], 'photos' => [UploadedFile::fake()->image('child.jpg')]])->assertOk();
        $item = OrderItem::where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('عمر', $item->order->child_name);
        $this->assertCount(1, $item->order->uploaded_photos);
        $this->assertSame(15000, $item->total_price_cents);
    }

    public function test_legacy_unrepresented_prices_are_not_overwritten(): void
    {
        $this->order->items()->delete();
        $this->order->update(['delivery_details' => array_replace($this->order->delivery_details, ['item_price' => 250])]);
        $this->add()->assertUnprocessable()->assertJsonValidationErrors('pricing_package_id');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
    }

    public function test_missing_phone_and_foreign_child_give_validation_not_server_error(): void
    {
        $this->order->update(['delivery_details' => ['delivery_fee' => 50, 'total' => 300]]);
        $this->add(['reuse_child_order_id' => 99999])->assertUnprocessable()->assertJsonValidationErrors('reuse_child_order_id');
    }

    public function test_late_failure_rolls_back_new_files_stock_items_and_money(): void
    {
        AdminActivityLog::creating(function ($log): void {
            if ($log->action === 'checkout.package_added') {
                throw new \RuntimeException('Simulated late failure');
            }
        });
        $this->add()->assertStatus(500);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(10, $this->product->fresh()->stock_quantity);
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }
}
