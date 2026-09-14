<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderFinishedLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_delivered_partially_paid_checkout_moves_to_finished_tab_and_report(): void
    {
        $order = $this->order('PARTIAL-FINISHED', 'partially_paid', 'delivered');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'catalog_type' => 'all',
                'lifecycle' => 'finished',
            ]))
            ->assertOk()
            ->assertSee($order->order_number);

        $report = $this->actingAs($this->admin)
            ->get(route('admin.order-report.index', [
                'catalog_type' => 'all',
                'lifecycle' => 'finished',
            ]))
            ->assertOk()
            ->viewData('report');

        $this->assertSame(1, $report['summary']['finished_checkouts']);
        $this->assertSame('finished', $report['rows']->first()['lifecycle']);
    }

    public function test_partial_payment_does_not_finish_checkout_before_delivery(): void
    {
        $order = $this->order('PARTIAL-IN-TRANSIT', 'partially_paid', 'shipped');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'catalog_type' => 'all',
                'lifecycle' => 'active',
            ]))
            ->assertOk()
            ->assertSee($order->order_number);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'catalog_type' => 'all',
                'lifecycle' => 'finished',
            ]))
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    public function test_unpaid_checkout_remains_active_after_delivery(): void
    {
        $order = $this->order('UNPAID-DELIVERED', 'unpaid', 'delivered');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'catalog_type' => 'all',
                'lifecycle' => 'active',
            ]))
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_partial_payment_does_not_finish_checkout_before_printing_is_complete(): void
    {
        $order = $this->order('PARTIAL-PRINTING', 'partially_paid', 'delivered');
        $order->update(['printing_status' => 'in_progress']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'catalog_type' => 'all',
                'lifecycle' => 'active',
            ]))
            ->assertOk()
            ->assertSee($order->order_number);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'catalog_type' => 'all',
                'lifecycle' => 'finished',
            ]))
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    private function order(string $group, string $paymentStatus, string $shippingStatus): Order
    {
        return Order::create([
            'order_number' => 'HK-'.$group,
            'checkout_group_key' => $group,
            'parent_name' => 'عميل اختبار دورة الطلب',
            'language' => 'ar',
            'delivery_details' => [
                'checkout_group' => $group,
                'phone' => '201001234567',
                'delivery_fee' => 50,
            ],
            'uploaded_photos' => [],
            'status' => 'delivered',
            'payment_status' => $paymentStatus,
            'paid_amount_cents' => $paymentStatus === 'unpaid' ? 0 : 10_000,
            'printing_status' => 'completed',
            'shipping_status' => $shippingStatus,
            'order_source' => 'website',
        ]);
    }
}
