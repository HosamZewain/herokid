<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_order_page_shows_one_group_timeline_with_creation_and_edits(): void
    {
        $admin = User::factory()->create(['name' => 'مشرف الطلبات', 'role' => 'admin']);
        [$first, $second] = $this->productOrdersInOneCheckout();

        AdminActivityLogger::log(
            action: 'order.details_updated',
            description: 'تم تعديل بيانات الطفل الثاني.',
            subject: $second,
            properties: [
                'changes' => [
                    'child_name' => ['old' => 'عمر', 'new' => 'آدم'],
                ],
            ],
            admin: $admin,
        );

        $response = $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $first));

        $response->assertOk()
            ->assertSee('data-order-activity-toggle', false)
            ->assertSee('data-order-activity-drawer', false)
            ->assertSee('سجل الطلب')
            ->assertSee('تم إنشاء عملية الشراء واستلام بيانات الطلب.')
            ->assertSee('تم تعديل بيانات الطفل الثاني.')
            ->assertSee('مشرف الطلبات')
            ->assertSee('اسم الطفل')
            ->assertSee('عمر')
            ->assertSee('آدم');

        $activity = $response->viewData('orderActivity');
        $this->assertSame(2, $activity['count']);
    }

    public function test_prompt_copy_endpoint_records_only_metadata_without_prompt_content(): void
    {
        $admin = User::factory()->create(['name' => 'مسؤول الإنتاج', 'role' => 'admin']);
        [$order] = $this->productOrdersInOneCheckout();
        $item = $order->items()->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('admin.orders.activity.prompt-copied', $order), [
                'prompt_type' => 'product_production',
                'order_item_id' => $item->id,
            ])
            ->assertOk()
            ->assertJson(['recorded' => true]);

        $log = AdminActivityLog::query()->where('action', 'order.prompt_copied')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(Order::class, $log->subject_type);
        $this->assertSame($order->id, $log->subject_id);
        $this->assertSame('product_production', $log->properties['prompt_type']);
        $this->assertSame($item->id, $log->properties['order_item_id']);
        $this->assertFalse($log->properties['prompt_content_logged']);
        $this->assertArrayNotHasKey('prompt', $log->properties);
    }

    public function test_prompt_copy_cannot_reference_an_item_from_another_checkout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$order] = $this->productOrdersInOneCheckout();
        $other = $this->productOrder('HK-ACTIVITY-OTHER', 'CHECKOUT-ACTIVITY-OTHER');
        $otherItem = $other->items()->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('admin.orders.activity.prompt-copied', $order), [
                'prompt_type' => 'product_production',
                'order_item_id' => $otherItem->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('admin_activity_logs', ['action' => 'order.prompt_copied']);
    }

    public function test_story_page_and_product_prompt_controls_include_copy_audit_hooks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $story = Story::query()->create([
            'title' => 'قصة السجل',
            'slug' => 'activity-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);
        $storyOrder = Order::query()->create([
            'order_number' => 'HK-ACTIVITY-STORY',
            'checkout_group_key' => 'CHECKOUT-ACTIVITY-STORY',
            'story_id' => $story->id,
            'parent_name' => 'ولي الأمر',
            'child_name' => 'سلمى',
            'child_age' => 7,
            'child_gender' => 'girl',
            'status' => 'new',
            'delivery_details' => ['phone' => '201000000001'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $storyOrder))
            ->assertOk()
            ->assertSee("recordPromptCopy('child_identity')", false)
            ->assertSee("recordPromptCopy('story_production')", false)
            ->assertSee('data-order-activity-drawer', false);

        [$productOrder] = $this->productOrdersInOneCheckout();
        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $productOrder))
            ->assertOk()
            ->assertSee("recordPromptCopy('product_production'", false)
            ->assertSee('data-order-item-id', false);
    }

    /** @return array{Order, Order} */
    private function productOrdersInOneCheckout(): array
    {
        return [
            $this->productOrder('HK-ACTIVITY-1', 'CHECKOUT-ACTIVITY'),
            $this->productOrder('HK-ACTIVITY-2', 'CHECKOUT-ACTIVITY'),
        ];
    }

    private function productOrder(string $number, string $group): Order
    {
        $product = Product::query()->create([
            'name_ar' => 'ستيكر مخصص '.$number,
            'slug' => strtolower($number).'-'.uniqid(),
            'price_cents' => 19_500,
            'is_active' => true,
            'production_prompt_template' => 'Create sticker for {{child_full_name}}.',
        ]);
        $order = Order::query()->create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'story_id' => null,
            'parent_name' => 'ولي الأمر',
            'child_name' => 'عمر',
            'child_age' => 8,
            'child_gender' => 'boy',
            'status' => 'new',
            'delivery_details' => ['phone' => '201000000001'],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'quantity' => 1,
            'unit_price_cents' => 19_500,
            'total_price_cents' => 19_500,
            'personalization_snapshot' => ['child_name' => 'عمر'],
        ]);

        return $order->fresh(['items.product']);
    }
}
