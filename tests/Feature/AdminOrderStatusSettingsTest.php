<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusDefinition;
use App\Models\Permission;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderPaymentService;
use App\Support\OrderStatusRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderStatusSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_controls_status_settings_page_and_navigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $permission = Permission::where('key', 'settings.order_statuses.manage')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.settings.order-statuses.index'))
            ->assertOk()
            ->assertSee('إعدادات حالات الطلبات')
            ->assertSee('حالات الدفع');

        $admin->permissions()->detach($permission);
        $admin->unsetRelation('permissions');

        $this->actingAs($admin)
            ->get(route('admin.settings.order-statuses.index'))
            ->assertForbidden();
    }

    public function test_admin_can_create_update_and_delete_an_unused_custom_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.settings.order-statuses.store'), [
            'type' => 'order',
            'key' => 'customer_confirmed',
            'label_ar' => 'أكد العميل',
            'description' => 'جاهز لبدء التنفيذ',
            'behavior' => 'standard',
            'color' => 'teal',
            'sort_order' => 25,
            'is_active' => 1,
        ])->assertRedirect();

        $definition = OrderStatusDefinition::where('type', 'order')->where('key', 'customer_confirmed')->firstOrFail();
        $this->assertSame('أكد العميل', OrderStatusRegistry::label('order', 'customer_confirmed'));

        $this->actingAs($admin)->put(route('admin.settings.order-statuses.update', $definition), [
            'label_ar' => 'تم تأكيد العميل',
            'description' => 'تم التأكيد ويمكن بدء التنفيذ',
            'behavior' => 'standard',
            'color' => 'emerald',
            'sort_order' => 30,
            'is_active' => 1,
        ])->assertRedirect();

        $this->assertSame('تم تأكيد العميل', OrderStatusRegistry::label('order', 'customer_confirmed'));
        $this->assertStringContainsString('emerald', OrderStatusRegistry::color('order', 'customer_confirmed'));

        $this->actingAs($admin)
            ->delete(route('admin.settings.order-statuses.destroy', $definition))
            ->assertRedirect();

        $this->assertDatabaseMissing('order_status_definitions', ['id' => $definition->id]);
    }

    public function test_used_custom_status_is_deactivated_not_deleted_and_remains_readable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $definition = OrderStatusDefinition::create([
            'type' => 'order',
            'key' => 'waiting_customer',
            'label_ar' => 'بانتظار العميل',
            'behavior' => 'standard',
            'color' => 'amber',
            'sort_order' => 35,
            'is_active' => true,
            'is_system' => false,
        ]);
        OrderStatusRegistry::clearCache();
        $order = Order::create([
            'order_number' => 'HK-CUSTOM-STATUS',
            'parent_name' => 'عميل اختبار',
            'status' => 'waiting_customer',
            'delivery_details' => ['phone' => '01000000000'],
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.settings.order-statuses.destroy', $definition))
            ->assertRedirect();

        $this->assertDatabaseHas('order_status_definitions', ['id' => $definition->id, 'is_active' => false]);
        $this->assertSame('بانتظار العميل', OrderStatusRegistry::label('order', $order->fresh()->status));
        $this->assertNotContains('waiting_customer', OrderStatusRegistry::keys('order'));
        $this->assertContains('waiting_customer', OrderStatusRegistry::keys('order', false));
    }

    public function test_payment_status_behavior_drives_amount_calculation(): void
    {
        OrderStatusDefinition::create([
            'type' => 'payment',
            'key' => 'instapay_verified',
            'label_ar' => 'تم التحقق من إنستاباي',
            'behavior' => 'paid_in_full',
            'color' => 'emerald',
            'sort_order' => 50,
            'is_active' => true,
            'is_system' => false,
        ]);
        OrderStatusRegistry::clearCache();

        $resolved = app(OrderPaymentService::class)->resolve('instapay_verified', null, 'انستاباي', 44_400, 9_500);

        $this->assertSame(44_400, $resolved['paid_amount_cents']);
        $this->assertSame(0, $resolved['remaining_amount_cents']);
    }

    public function test_system_status_cannot_be_deleted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $definition = OrderStatusDefinition::where('type', 'order')->where('key', 'new')->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('admin.settings.order-statuses.destroy', $definition))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseHas('order_status_definitions', ['id' => $definition->id]);
    }

    public function test_custom_status_label_filter_and_cancelled_behavior_reflect_on_order_list_statistics(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        OrderStatusDefinition::create([
            'type' => 'order',
            'key' => 'customer_cancelled',
            'label_ar' => 'ألغاه العميل',
            'behavior' => 'cancelled',
            'color' => 'rose',
            'sort_order' => 95,
            'is_active' => true,
            'is_system' => false,
        ]);
        OrderStatusRegistry::clearCache();
        $order = Order::create([
            'order_number' => 'HK-CUSTOM-CANCELLED',
            'checkout_group_key' => 'CHK-CUSTOM-CANCELLED',
            'parent_name' => 'عميل ملغي',
            'status' => 'customer_cancelled',
            'delivery_details' => [
                'phone' => '01000000000',
                'item_price' => 349,
                'delivery_fee' => 50,
            ],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.index', ['status' => 'customer_cancelled']))
            ->assertOk()
            ->assertSee('ألغاه العميل')
            ->assertSee('HK-CUSTOM-CANCELLED')
            ->assertViewHas('stats', fn (array $stats): bool => $stats['checkouts'] === 1
                && $stats['cancelled_checkouts'] === 1
                && $stats['cancelled_value_cents'] === 39_900);

        $this->assertSame('customer_cancelled', $order->fresh()->status);
    }

    public function test_inactive_historical_workflow_status_keeps_its_label(): void
    {
        OrderStatusDefinition::create([
            'type' => 'shipping',
            'key' => 'waiting_carrier',
            'label_ar' => 'بانتظار شركة الشحن',
            'behavior' => 'not_ready',
            'color' => 'amber',
            'sort_order' => 25,
            'is_active' => false,
            'is_system' => false,
        ]);
        OrderStatusRegistry::clearCache();
        $order = Order::create([
            'order_number' => 'HK-HISTORICAL-SHIPPING',
            'checkout_group_key' => 'CHK-HISTORICAL-SHIPPING',
            'parent_name' => 'عميل تاريخي',
            'status' => 'new',
            'shipping_status' => 'waiting_carrier',
            'delivery_details' => ['phone' => '01000000001'],
        ]);

        $group = app(AdminOrderGroupService::class)->findByRepresentative($order->id);

        $this->assertSame('waiting_carrier', $group['shipping_status']);
        $this->assertSame('بانتظار شركة الشحن', $group['shipping_status_label']);
    }
}
