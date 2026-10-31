<?php

namespace Tests\Feature;

use App\Models\BookletPreview;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderWhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderWhatsAppMessagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
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

    public function test_scenes_template_contains_every_checkout_preview_on_separate_lines_from_either_story(): void
    {
        $first = $this->order();
        $second = $first->replicate();
        $second->order_number = 'HK-WA-2';
        $second->save();
        $firstPreview = $this->previewFor($first);
        $secondPreview = $this->previewFor($second);
        $template = "مرحباً {{parent_name}}، دي معاينة لطلبك {{order_reference}} من HeroKid:\nيرجى التأكيد لدخول مرحلة الطباعة.\nلينك المعاينة:\n{{preview_scenes_url}}\nالمبلغ المتبقي عند الاستلام: {{remaining_amount}}";
        Setting::updateOrCreate(['key' => OrderWhatsAppMessageService::SETTING_KEY], ['value' => json_encode([[
            'id' => 'preview', 'title' => 'معاينة المشاهد', 'message' => $template, 'is_active' => true,
        ]], JSON_UNESCAPED_UNICODE)]);
        $service = app(OrderWhatsAppMessageService::class);
        $urls = $firstPreview->publicScenesUrl()."\n".$secondPreview->publicScenesUrl();

        foreach ([$first, $second] as $representative) {
            $group = app(AdminOrderGroupService::class)->findByRepresentative($representative->id);
            $variables = $service->variablesForGroup($group);
            $this->assertSame($urls, $variables['preview_scenes_url']);
            $message = $service->messagesForGroup($group)[0];
            $this->assertSame($service->render($template, $variables), $message['body']);
            $this->assertStringContainsString($urls, $message['body']);
            $this->assertSame(1, substr_count($message['body'], $firstPreview->publicScenesUrl()));
            $this->assertSame('https://wa.me/201111822277?text='.rawurlencode($message['body']), $message['url']);
        }
    }

    public function test_scenes_links_exclude_other_checkouts_and_unavailable_previews(): void
    {
        $first = $this->order();
        $preview = $this->previewFor($first);
        foreach (['revoked', 'no_version', 'invalid_token', 'deleted_preview', 'deleted_order', 'other_checkout', 'no_preview'] as $kind) {
            $sibling = $first->replicate();
            $sibling->order_number = 'HK-WA-'.$kind;
            if ($kind === 'other_checkout') {
                $sibling->checkout_group_key = 'OTHER-CHECKOUT';
            }
            $sibling->save();
            if ($kind === 'no_preview') {
                continue;
            }
            $unavailable = $this->previewFor($sibling);
            match ($kind) {
                'revoked' => $unavailable->update(['status' => 'revoked']),
                'no_version' => $unavailable->update(['current_version_id' => null]),
                'invalid_token' => $unavailable->update(['public_token_encrypted' => 'invalid']),
                'deleted_preview' => $unavailable->delete(),
                'deleted_order' => $sibling->delete(),
                default => null,
            };
        }
        $service = app(OrderWhatsAppMessageService::class);
        $group = app(AdminOrderGroupService::class)->findByRepresentative($first->id);
        $this->assertSame($preview->publicScenesUrl(), $service->variablesForGroup($group)['preview_scenes_url']);
        // Repeated model entries must not duplicate a customer link.
        $group['active_orders'] = collect([$first->fresh(), $first->fresh()]);
        $this->assertSame($preview->publicScenesUrl(), $service->variablesForGroup($group)['preview_scenes_url']);
        $preview->update(['status' => 'revoked']);
        $group = app(AdminOrderGroupService::class)->findByRepresentative($first->id);
        $this->assertSame('', $service->variablesForGroup($group)['preview_scenes_url']);
    }

    private function previewFor(Order $order): BookletPreview
    {
        $story = Story::create([
            'title' => 'قصة تجريبية', 'slug' => 'synthetic-'.Str::uuid(),
            'language' => 'ar', 'gender' => 'both', 'price' => 349, 'active' => true,
        ]);
        $order->update(['story_id' => $story->id]);
        $token = Str::random(48);
        $preview = BookletPreview::create([
            'uuid' => (string) Str::uuid(), 'source_type' => 'order', 'order_id' => $order->id,
            'title' => 'Synthetic story preview', 'status' => 'active',
            'public_token_hash' => hash('sha256', $token), 'public_token_encrypted' => Crypt::encryptString($token),
        ]);
        $version = $preview->versions()->create([
            'version_number' => 1, 'disk' => 'local', 'file_path' => 'synthetic/preview.pdf',
            'original_filename' => 'preview.pdf', 'file_size' => 1, 'checksum' => str_repeat('0', 64), 'page_count' => 2,
        ]);
        $preview->update(['current_version_id' => $version->id]);

        return $preview;
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
