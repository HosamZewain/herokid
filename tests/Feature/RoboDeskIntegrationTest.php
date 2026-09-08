<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\RoboDesk\RoboDeskIntegrationRegistry;
use App\Services\RoboDesk\RoboDeskSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The Order Confirmation integration: three fields, one trigger.
 */
class RoboDeskIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://robodesk.test/conversation/start/sendMsg';

    // ── Happy paths ──────────────────────────────────────────────────────

    public function test_a_new_order_posts_the_configured_payload_to_the_configured_url(): void
    {
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);
        $this->enable();
        $this->configure('{"to":"{{ customer_phone }}","name":"{{ customer_name }}","total":"{{ total }}"}');

        $order = $this->order('CHK-OK-1');

        $event = $this->eventFor($order);
        $this->assertSame('succeeded', $event->status);

        Http::assertSent(function ($request): bool {
            return $request->url() === self::URL
                && $request->method() === 'POST'
                && $request['to'] === '201501188884'
                && $request['name'] === 'ولي الأمر'
                && $request['total'] === 50
                && $request->hasHeader('Authorization', 'static-token');
        });
    }

    public function test_the_saved_payload_is_the_entire_body(): void
    {
        Http::fake([self::URL => Http::response([], 200)]);
        $this->enable();
        $this->configure('{"only":"{{ customer_name }}"}');

        $this->order('CHK-OK-2');

        Http::assertSent(fn ($request): bool => array_keys($request->data()) === ['only']);
    }

    public function test_an_empty_payload_sends_every_available_variable(): void
    {
        Http::fake([self::URL => Http::response([], 200)]);
        $this->enable();
        $this->configure('');

        $this->order('CHK-OK-3');

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return isset($data['checkout_reference'], $data['customer_name'], $data['delivery_address']);
        });
    }

    public function test_one_checkout_produces_one_message_however_many_orders_it_holds(): void
    {
        Http::fake([self::URL => Http::response([], 200)]);
        $this->enable();
        $this->configure('{"ref":"{{ checkout_reference }}"}');

        $this->order('CHK-OK-4');
        $this->order('CHK-OK-4');

        $this->assertSame(1, RoboDeskIntegrationEvent::query()->where('checkout_group_key', 'CHK-OK-4')->count());
        Http::assertSentCount(1);
    }

    // ── Common exceptions ────────────────────────────────────────────────

    public function test_a_disabled_integration_records_nothing_and_sends_nothing(): void
    {
        Http::fake();
        $order = $this->order('CHK-OFF');

        $this->assertDatabaseMissing('robodesk_integration_events', [
            'checkout_group_key' => $order->checkout_group_key,
        ]);
        Http::assertNothingSent();
    }

    public function test_an_enabled_integration_without_an_api_url_holds_rather_than_sending(): void
    {
        Http::fake();
        $this->enable();
        app(RoboDeskIntegrationRegistry::class)->save(
            RoboDeskIntegrationRegistry::ORDER_CONFIRMATION, true, '', 'static-token', '',
        );

        $order = $this->order('CHK-NO-URL');

        $event = $this->eventFor($order);
        $this->assertSame('held', $event->status);
        $this->assertStringContainsString('No API URL', (string) $event->last_error);
        Http::assertNothingSent();
    }

    public function test_a_failing_remote_records_the_error_and_leaves_the_event_retryable(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'boom'], 500)]);
        $this->enable();
        $this->configure('{"ref":"{{ checkout_reference }}"}');

        try {
            // The queue runs inline under test and the job rethrows so it can
            // be retried, so the failure surfaces here at creation time.
            $this->order('CHK-FAIL');
        } catch (\Throwable) {
            // Expected.
        }

        $event = RoboDeskIntegrationEvent::query()->where('checkout_group_key', 'CHK-FAIL')->firstOrFail();
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('500', (string) $event->last_error);
    }

    public function test_a_held_event_can_be_released_once_the_integration_is_configured(): void
    {
        Http::fake([self::URL => Http::response([], 200)]);
        $this->enable();
        app(RoboDeskIntegrationRegistry::class)->save(
            RoboDeskIntegrationRegistry::ORDER_CONFIRMATION, true, '', 'static-token', '',
        );

        $order = $this->order('CHK-RELEASE');
        $event = $this->eventFor($order);
        $this->assertSame('held', $event->status);

        $this->configure('{"ref":"{{ checkout_reference }}"}');

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::query()->whereIn('key', ['robodesk.view', 'robodesk.retry'])->pluck('id'));
        $admin->unsetRelation('permissions');

        $this->actingAs($admin)
            ->post(route('admin.robodesk.events.retry', $event))
            ->assertRedirect();

        $this->assertSame('succeeded', $event->refresh()->status);
    }

    // ── Admin screens ────────────────────────────────────────────────────

    public function test_the_settings_screens_render(): void
    {
        $this->enable();
        $this->configure('{"ref":"{{ checkout_reference }}"}');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.robodesk.settings.index'))
            ->assertOk()
            ->assertSee('تأكيد الطلب');

        // Renders the raw {{ variable }} help, which is where a Blade escaping
        // mistake previously crashed the page with a ParseError.
        $this->actingAs($admin)
            ->get(route('admin.robodesk.settings.edit', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION))
            ->assertOk()
            ->assertSee('{{ customer_name }}', false)
            ->assertSee('{{ total }}', false)
            // Each variable renders its own name, not the literal Blade source.
            ->assertSee('{{ delivery_address }}', false)
            ->assertDontSee('$name');
    }

    public function test_the_three_fields_save_and_the_token_is_never_rendered_back(): void
    {
        $this->enable();

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.settings.update', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION), [
                'is_enabled' => '1',
                'api_url' => self::URL,
                'token' => 'super-secret-token',
                'payload_template' => '{"ref":"{{ checkout_reference }}"}',
            ])
            ->assertRedirect();

        $integration = app(RoboDeskIntegrationRegistry::class)->orderConfirmation();
        $this->assertTrue($integration->enabled());
        $this->assertSame(self::URL, $integration->apiUrl());
        $this->assertSame('super-secret-token', $integration->token());

        $this->actingAs($this->admin())
            ->get(route('admin.robodesk.settings.edit', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION))
            ->assertOk()
            ->assertDontSee('super-secret-token');
    }

    public function test_a_blank_token_keeps_the_saved_one(): void
    {
        $this->enable();
        $this->configure('');

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.settings.update', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION), [
                'is_enabled' => '1',
                'api_url' => self::URL,
                'token' => '',
                'payload_template' => '',
            ])
            ->assertRedirect();

        $this->assertSame('static-token', app(RoboDeskIntegrationRegistry::class)->orderConfirmation()->token());
    }

    public function test_an_invalid_payload_or_unknown_variable_is_rejected(): void
    {
        $this->enable();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.robodesk.settings.update', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION), [
                'api_url' => self::URL,
                'payload_template' => '{"broken": ',
            ])
            ->assertSessionHasErrors('payload_template');

        $this->actingAs($admin)
            ->post(route('admin.robodesk.settings.update', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION), [
                'api_url' => self::URL,
                'payload_template' => '{"x":"{{ not_a_real_variable }}"}',
            ])
            ->assertSessionHasErrors('payload_template');
    }

    public function test_enabling_without_an_api_url_is_rejected(): void
    {
        $this->enable();

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.settings.update', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION), [
                'is_enabled' => '1',
                'api_url' => '',
            ])
            ->assertSessionHasErrors('api_url');
    }

    public function test_changing_the_token_needs_the_credentials_permission(): void
    {
        $this->enable();
        $this->configure('');

        $this->actingAs($this->admin(['robodesk.configure', 'robodesk.view']))
            ->post(route('admin.robodesk.settings.update', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION), [
                'is_enabled' => '1',
                'api_url' => self::URL,
                'token' => 'a-new-secret',
            ])
            ->assertForbidden();

        $this->assertSame('static-token', app(RoboDeskIntegrationRegistry::class)->orderConfirmation()->token());
    }

    public function test_settings_require_the_configure_permission(): void
    {
        $this->actingAs($this->admin(['robodesk.view']))
            ->get(route('admin.robodesk.settings.index'))
            ->assertForbidden();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function admin(array $permissions = ['robodesk.configure', 'robodesk.manage_credentials', 'robodesk.view']): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
        $admin->unsetRelation('permissions');

        return $admin;
    }

    private function enable(): void
    {
        app(RoboDeskSettings::class)->save(['robodesk_enabled' => '1']);
    }

    private function configure(string $payload): void
    {
        app(RoboDeskIntegrationRegistry::class)->save(
            RoboDeskIntegrationRegistry::ORDER_CONFIRMATION,
            true,
            self::URL,
            'static-token',
            $payload,
        );
    }

    private function eventFor(Order $order): RoboDeskIntegrationEvent
    {
        return RoboDeskIntegrationEvent::query()
            ->where('checkout_group_key', $order->checkout_group_key)
            ->where('direction', 'outbound')
            ->firstOrFail();
    }

    private function order(string $checkout): Order
    {
        return Order::query()->create($this->attributes($checkout));
    }

    private function attributes(string $checkout): array
    {
        return [
            'order_number' => 'HK-'.Str::upper(Str::random(10)),
            'checkout_group_key' => $checkout,
            'parent_name' => 'ولي الأمر',
            'status' => 'new',
            'uploaded_photos' => [],
            'delivery_details' => [
                'phone' => '01501188884',
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'street' => 'شارع 1',
                'address_details' => 'الدور الثالث',
                'delivery_fee' => 50,
            ],
        ];
    }
}
