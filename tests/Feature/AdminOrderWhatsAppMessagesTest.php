<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderWhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderWhatsAppMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_default_templates_render_live_order_variables_on_order_surfaces(): void
    {
        $order = $this->order();

        $index = $this->actingAs($this->admin)->get(route('admin.orders.index', [
            'catalog_type' => 'products',
        ]));

        $index->assertOk()
            ->assertSee('مراسلة عامة')
            ->assertSee('إرسال معاينة للعميل')
            ->assertSee('إرسال بيانات الدفع')
            ->assertSee(rawurlencode('مرحباً، بخصوص طلبك '.$order->checkoutReference->short_reference), false);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('مراسلة عامة')
            ->assertSee('إرسال بيانات الدفع');
    }

    public function test_admin_can_add_change_disable_and_remove_templates_without_order_snapshots(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->put(route('admin.settings.order-whatsapp-messages.update'), [
                'templates' => [
                    [
                        'id' => 'custom_preview',
                        'title' => 'رسالة خاصة',
                        'message' => 'أهلاً {{parent_name}}، طلبك {{order_reference}} والمتبقي {{remaining_amount}}',
                        'is_active' => '1',
                        'sort_order' => 5,
                    ],
                    [
                        'id' => 'disabled',
                        'title' => 'رسالة مخفية',
                        'message' => 'لا تظهر {{order_reference}}',
                        'is_active' => '0',
                        'sort_order' => 10,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', ['key' => OrderWhatsAppMessageService::SETTING_KEY]);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'settings.order_whatsapp_messages.updated']);

        $group = app(AdminOrderGroupService::class)->findByRepresentative($order->id);
        $messages = app(OrderWhatsAppMessageService::class)->messagesForGroup($group);

        $this->assertCount(1, $messages);
        $this->assertSame('رسالة خاصة', $messages[0]['title']);
        $this->assertStringContainsString('أهلاً ولي الأمر', $messages[0]['body']);
        $this->assertStringContainsString($order->checkoutReference->short_reference, $messages[0]['body']);
        $this->assertStringContainsString('٣٠٠', $messages[0]['body']);
        $this->assertDatabaseCount('order_production_prompt_snapshots', 0);
    }

    public function test_unknown_message_variables_are_rejected_in_arabic(): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.settings.order-whatsapp-messages.edit'))
            ->put(route('admin.settings.order-whatsapp-messages.update'), [
                'templates' => [[
                    'id' => 'bad',
                    'title' => 'قالب غير صحيح',
                    'message' => 'طلبك {{not_a_real_variable}}',
                    'is_active' => '1',
                    'sort_order' => 10,
                ]],
            ])
            ->assertRedirect(route('admin.settings.order-whatsapp-messages.edit'))
            ->assertSessionHasErrors('templates.0.message');

        $this->assertDatabaseMissing('settings', ['key' => OrderWhatsAppMessageService::SETTING_KEY]);
    }

    public function test_settings_page_documents_every_supported_variable(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.settings.order-whatsapp-messages.edit'));

        $response->assertOk()->assertSee('رسائل واتساب للطلبات');
        foreach (array_keys(OrderWhatsAppMessageService::VARIABLES) as $variable) {
            $response->assertSee('{{'.$variable.'}}', false);
        }
    }

    private function order(): Order
    {
        $order = Order::create([
            'user_id' => null,
            'story_id' => null,
            'order_number' => 'HK-WA-1',
            'checkout_group_key' => 'GROUP-WHATSAPP',
            'parent_name' => 'ولي الأمر',
            'status' => 'new',
            'payment_status' => 'partially_paid',
            'paid_amount_cents' => 10_000,
            'delivery_details' => [
                'phone' => '01111822277',
                'delivery_fee' => 50,
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'street' => 'شارع رئيسي',
                'address_details' => 'العقار 10',
            ],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'title' => 'منتج مخصص',
            'unit_price_cents' => 35_000,
            'quantity' => 1,
            'total_price_cents' => 35_000,
        ]);

        return $order->refresh()->load('checkoutReference');
    }
}
