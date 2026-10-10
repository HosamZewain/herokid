<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderQuickStoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    private Story $story;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['photo_uploads.min_files' => 2, 'photo_uploads.max_files' => 3]);
        Setting::updateOrCreate(['key' => 'story_global_price_enabled'], ['value' => '0']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->order = Order::create([
            'order_number' => 'HK-QUICK-STORY-BASE', 'checkout_group_key' => 'CHK-QUICK-STORY-BASE',
            'parent_name' => 'ولي الأمر', 'child_name' => 'ليلى', 'child_age' => 7, 'child_gender' => 'girl',
            'interests' => 'الفضاء', 'parent_notes' => 'ملاحظات محفوظة',
            'status' => 'ready_preview', 'order_source' => 'website', 'paid_amount_cents' => 30000,
            'payment_status' => 'paid_in_full', 'payment_method' => 'انستاباي',
            'preview_approved_at' => now()->subDay(),
            'delivery_details' => ['phone' => '01012345678', 'delivery_fee' => 50, 'total' => 300,
                'address_details' => 'عنوان محفوظ', 'bosta_district_id' => 'district-original', 'utm_campaign' => 'original-campaign'],
            'uploaded_photos' => ['orders/photos/quick-story/one.jpg', 'orders/photos/quick-story/two.jpg'],
        ]);
        foreach ($this->order->uploaded_photos as $index => $path) {
            Storage::disk('local')->put($path, 'source-photo-'.$index);
        }
        $this->order->items()->create(['item_type' => 'product', 'title' => 'منتج موجود', 'quantity' => 1, 'unit_price_cents' => 25000, 'total_price_cents' => 25000]);
        $this->story = Story::create(['title' => 'رحلة إلى الفضاء', 'slug' => 'quick-story-space', 'price' => 200,
            'language' => 'ar', 'gender' => 'both', 'age_range' => '6-9', 'active' => true, 'lesson_value' => 'الشجاعة']);
        $this->order->refresh();
    }

    private function add(array $values = [])
    {
        return $this->actingAs($this->admin)->postJson(route('admin.orders.groups.stories.store', $this->order), array_replace([
            'story_id' => $this->story->id, 'quantity' => 1, 'request_key' => (string) Str::uuid(),
            'reuse_child_order_id' => $this->order->id, 'change_reason' => 'طلب العميل إضافة قصة جديدة.',
        ], $values));
    }

    private function added(): Order
    {
        return Order::where('checkout_group_key', $this->order->checkoutGroupKey())->whereNotNull('story_id')->firstOrFail();
    }

    public function test_page_and_options_show_searchable_active_stories_and_only_this_customers_previous_children(): void
    {
        $previous = $this->order->replicate();
        $previous->order_number = 'HK-PREVIOUS';
        $previous->checkout_group_key = 'CHK-PREVIOUS';
        $previous->delivery_details = array_replace($previous->delivery_details, ['phone' => '+201012345678']);
        $previous->save();
        $other = $this->order->replicate();
        $other->order_number = 'HK-OTHER';
        $other->checkout_group_key = 'CHK-OTHER';
        $other->delivery_details = array_replace($other->delivery_details, ['phone' => '01112345678']);
        $other->save();
        $inactive = $this->story->replicate();
        $inactive->slug = 'quick-story-inactive';
        $inactive->active = false;
        $inactive->save();
        $this->actingAs($this->admin)->get(route('admin.orders.groups.show', $this->order))
            ->assertOk()->assertSee('data-quick-open="add-story"', false)->assertSee('data-add-story-url', false);
        $response = $this->actingAs($this->admin)->getJson(route('admin.orders.groups.quick-edit-options', $this->order))->assertOk();
        $ids = collect($response->json('story_children'))->pluck('order_id')->all();
        $this->assertContains($this->order->id, $ids);
        $this->assertContains($previous->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertContains($this->story->id, collect($response->json('story_catalog'))->pluck('id')->all());
        $this->assertNotContains($inactive->id, collect($response->json('story_catalog'))->pluck('id')->all());
        $response->assertJsonPath('story_photo_min', 2)->assertJsonPath('story_photo_max', 3);
    }

    public function test_addition_preserves_existing_items_payment_approval_and_address_and_records_employee_and_details(): void
    {
        $oldItem = $this->order->items()->firstOrFail();
        $old = $oldItem->getAttributes();
        $this->order->attachments()->create(['path' => 'orders/keep-story.pdf', 'original_name' => 'keep.pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        $this->add(['unit_price_cents' => 1])->assertOk();
        $added = $this->added();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($this->order->id);
        $this->assertSame(50000, $group['total_cents']);
        $this->assertSame(30000, $group['paid_amount_cents']);
        $this->assertSame(20000, $group['remaining_amount_cents']);
        $this->assertSame('partially_paid', $group['payment_status']);
        $this->assertSame($old, $oldItem->fresh()->getAttributes());
        $this->assertSame('ready_preview', $this->order->fresh()->status);
        $this->assertNotNull($this->order->fresh()->preview_approved_at);
        $this->assertSame('new', $added->status);
        $this->assertNull($added->preview_approved_at);
        $this->assertSame('district-original', data_get($added->delivery_details, 'bosta_district_id'));
        $this->assertSame('original-campaign', data_get($added->delivery_details, 'utm_campaign'));
        $this->assertSame(20000, $added->items()->first()->unit_price_cents);
        $this->assertSame($this->admin->id, $added->created_by_admin_id);
        $this->assertSame('الشجاعة', $added->lesson);
        $this->assertDatabaseCount('order_attachments', 1);
        $this->assertSame(13, $added->sceneTextSnapshots()->count());
        $event = OrderPaymentEvent::where('source', 'admin_story_added')->firstOrFail();
        $this->assertSame(0, $event->amount_delta_cents);
        $this->assertFalse($event->affects_collection_stats);
        $log = AdminActivityLog::where('action', 'checkout.story_added')->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('طلب العميل إضافة قصة جديدة.', $log->properties['reason']);
        $this->assertSame([$added->id], $log->properties['added_order_ids']);
        $this->assertSame(2, $log->properties['added_items'][0]['personalization_snapshot']['uploaded_photos_count']);
    }

    public function test_reused_child_can_be_adjusted_and_each_copy_has_independent_private_photos_in_original_order(): void
    {
        $this->add(['quantity' => 2, 'child_name' => 'ليلى أحمد', 'language' => 'en'])->assertOk();
        $added = Order::whereNotNull('story_id')->get();
        $this->assertCount(2, $added);
        $all = [];
        foreach ($added as $order) {
            $this->assertSame('ليلى أحمد', $order->child_name);
            $this->assertSame('en', $order->language);
            $this->assertSame('الفضاء', $order->interests);
            $this->assertSame('ملاحظات محفوظة', $order->parent_notes);
            $this->assertCount(2, $order->uploaded_photos);
            foreach ($order->uploaded_photos as $index => $path) {
                $this->assertNotContains($path, $this->order->uploaded_photos);
                $this->assertNotContains($path, $all);
                $this->assertSame('source-photo-'.$index, Storage::disk('local')->get($path));
                $all[] = $path;
            }
            $this->assertSame(1, $order->items()->first()->quantity);
        }
        $this->assertSame('ليلى', $this->order->fresh()->child_name);
    }

    public function test_can_reuse_previous_order_of_same_customer_but_cannot_reuse_another_customers_child(): void
    {
        $previous = $this->order->replicate();
        $previous->order_number = 'HK-PREVIOUS-CHILD';
        $previous->checkout_group_key = 'CHK-PREVIOUS-CHILD';
        $previous->delivery_details = array_replace($previous->delivery_details, ['phone' => '+201012345678']);
        $previous->save();
        $this->add(['reuse_child_order_id' => $previous->id])->assertOk();
        $this->assertSame('ليلى', $this->added()->child_name);
        $other = $this->order->replicate();
        $other->order_number = 'HK-FOREIGN-CHILD';
        $other->checkout_group_key = 'CHK-FOREIGN-CHILD';
        $other->delivery_details = array_replace($other->delivery_details, ['phone' => '01112345678']);
        $other->save();
        $this->add(['reuse_child_order_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('reuse_child_order_id');
        $this->assertSame(1, Order::whereNotNull('story_id')->count());
    }

    public function test_manual_child_data_and_new_photos_are_saved_without_reusing_another_child(): void
    {
        $this->add(['reuse_child_order_id' => null, 'child_name' => 'عمر', 'child_age' => 8, 'child_gender' => 'boy', 'gift_note' => 'هدية لعمر',
            'photos' => [UploadedFile::fake()->image('first.jpg'), UploadedFile::fake()->image('second.jpg')]])->assertOk();
        $order = $this->added();
        $this->assertSame('عمر', $order->child_name);
        $this->assertSame('هدية لعمر', $order->gift_note);
        $this->assertCount(2, $order->uploaded_photos);
        foreach ($order->uploaded_photos as $path) {
            Storage::disk('local')->assertExists($path);
        }
        $this->assertSame('ليلى', $this->order->fresh()->child_name);
    }

    public function test_validation_does_not_leave_partial_orders_or_change_payment(): void
    {
        $this->add(['reuse_child_order_id' => null, 'photos' => [UploadedFile::fake()->image('one.jpg')]])
            ->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->add(['reuse_child_order_id' => null, 'photos' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['child_name', 'child_age', 'child_gender']);
        $this->add(['photos' => [UploadedFile::fake()->image('three.jpg'), UploadedFile::fake()->image('four.jpg')]])
            ->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
        $this->assertCount(2, Storage::disk('local')->allFiles('orders/photos'));
    }

    public function test_retries_are_idempotent_and_different_payload_on_same_key_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $this->add(['request_key' => $key])->assertOk();
        $this->add(['request_key' => $key])->assertOk();
        $this->add(['request_key' => $key, 'quantity' => 2])->assertStatus(409);
        $this->assertSame(1, Order::whereNotNull('story_id')->count());
        $this->assertSame(1, AdminActivityLog::where('action', 'checkout.story_added')->count());
    }

    public function test_respects_twenty_item_limit_and_inactive_stories(): void
    {
        $this->add(['quantity' => 20])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->story->update(['active' => false]);
        $this->add()->assertUnprocessable()->assertJsonValidationErrors('story_id');
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_can_reach_twenty_items_without_changing_original_items(): void
    {
        $this->add(['quantity' => 19])->assertOk();
        $this->assertDatabaseCount('order_items', 20);
        $this->add()->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('order_items', 20);
    }

    public function test_existing_discount_shipping_and_zero_payment_are_preserved(): void
    {
        $this->order->update(['discount_cents' => 3000, 'discount_reason' => 'خصم محفوظ', 'paid_amount_cents' => 0, 'payment_status' => 'unpaid']);
        $this->add()->assertOk();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($this->order->id);
        $this->assertSame(47000, $group['total_cents']);
        $this->assertSame(3000, $group['discount_cents']);
        $this->assertSame(5000, $group['delivery_cents']);
        $this->assertSame(0, $group['paid_amount_cents']);
        $this->assertSame('خصم محفوظ', $group['discount_reason']);
    }

    public function test_server_uses_current_global_story_offer_not_client_price(): void
    {
        $this->order->update(['delivery_details' => array_replace($this->order->delivery_details, [
            'story_regular_price' => 500, 'story_offer_applied' => false, 'story_offer_label' => 'عرض سابق',
        ])]);
        foreach (['story_global_price_enabled' => '1', 'story_regular_price' => '399', 'story_offer_enabled' => '1', 'story_offer_price' => '149'] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $this->add(['price' => 1])->assertOk();
        $item = $this->added()->items()->first();
        $this->assertSame(14900, $item->unit_price_cents);
        $this->assertTrue($item->item_snapshot['offer_applied']);
        $this->assertSame(399.0, (float) $item->item_snapshot['regular_price']);
        $delivery = $this->added()->delivery_details;
        $this->assertSame(399.0, (float) $delivery['story_regular_price']);
        $this->assertTrue($delivery['story_offer_applied']);
        $this->assertSame($item->item_snapshot['offer_label'], $delivery['story_offer_label']);
        $this->assertSame(500, $this->order->fresh()->delivery_details['story_regular_price']);
        $this->assertFalse($this->order->fresh()->delivery_details['story_offer_applied']);
        $this->assertSame('عرض سابق', $this->order->fresh()->delivery_details['story_offer_label']);
    }

    public function test_late_failure_rolls_back_balances_and_removes_only_new_photo_copies(): void
    {
        AdminActivityLog::creating(function (AdminActivityLog $log): void {
            if ($log->action === 'checkout.story_added') {
                throw new \RuntimeException('Simulated late transaction failure');
            }
        });
        $this->add(['photos' => [UploadedFile::fake()->image('extra.jpg')]])->assertStatus(500);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
        $this->assertSame($this->order->uploaded_photos, Storage::disk('local')->allFiles('orders/photos'));
    }

    public function test_permissions_protect_addition_and_reused_customer_photos(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::whereIn('key', ['orders.view'])->pluck('id'));
        $limited->unsetRelation('permissions');
        $payload = ['story_id' => $this->story->id, 'quantity' => 1, 'request_key' => (string) Str::uuid(), 'reuse_child_order_id' => $this->order->id, 'change_reason' => 'إضافة قصة للعميل.'];
        $this->actingAs($limited)->postJson(route('admin.orders.groups.stories.store', $this->order), $payload)->assertForbidden();
        $limited->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update'])->pluck('id'));
        $limited->unsetRelation('permissions');
        $this->actingAs($limited)->postJson(route('admin.orders.groups.stories.store', $this->order), $payload)->assertForbidden();
        $this->actingAs($limited)->getJson(route('admin.orders.groups.quick-edit-options', $this->order))
            ->assertOk()->assertJsonPath('can_upload_photos', false)->assertJsonPath('story_children.0.photos', []);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_missing_source_photo_returns_actionable_validation_without_partial_orders(): void
    {
        Storage::disk('local')->delete($this->order->uploaded_photos[1]);
        $this->add()->assertUnprocessable()->assertJsonValidationErrors('photos');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('orders/photos'));
        $this->assertSame(30000, $this->order->fresh()->paid_amount_cents);
    }
}
