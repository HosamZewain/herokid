<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderCsatResponse;
use App\Models\Permission;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\RoboDesk\Actions\ConfirmIdentityAction;
use App\Services\RoboDesk\Actions\ConfirmOrderAction;
use App\Services\RoboDesk\Actions\RequestCsatAction;
use App\Services\RoboDesk\ConfirmIdentityGate;
use App\Services\RoboDesk\OrderConfirmationGate;
use App\Services\RoboDesk\RoboDeskActionRegistry;
use App\Services\RoboDesk\RoboDeskCredentialService;
use App\Services\RoboDesk\RoboDeskPayloadRenderer;
use App\Services\RoboDesk\RoboDeskSettings;
use App\Support\OrderWorkflowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoboDeskActionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The queue runs synchronously under test, so outbound delivery would
        // otherwise reach the network.
        Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    }

    // ── Happy paths ──────────────────────────────────────────────────────

    public function test_action_params_are_admin_configurable_and_override_connection_defaults(): void
    {
        $settings = app(RoboDeskSettings::class);
        $settings->save([
            'robodesk_default_channel' => 'whatsapp-default',
            'robodesk_default_language' => 'ar',
            'robodesk_events_path' => '/api/default/events',
        ]);

        app(RoboDeskActionRegistry::class)->save(ConfirmOrderAction::KEY, true, [
            'endpoint_path' => 'conversation/start/sendMsg',
            'channel' => 'whatsapp-herokid',
            'http_method' => 'PUT',
        ]);

        $action = app(RoboDeskActionRegistry::class)->get(ConfirmOrderAction::KEY);

        $this->assertTrue($action->enabled());
        $this->assertSame('/conversation/start/sendMsg', $action->endpointPath());
        $this->assertSame('whatsapp-herokid', $action->channel());
        $this->assertSame('PUT', $action->httpMethod());
        // Unset params still fall through to the connection defaults.
        $this->assertSame('ar', $action->language());
    }

    public function test_payload_template_renders_variables_and_preserves_native_types(): void
    {
        $rendered = app(RoboDeskPayloadRenderer::class)->render(
            '{"to":"{{ customer_phone }}","template":{"templateName":"t1","data":["{{ customer_name }}","{{ total }}"]},"amount":"{{ total }}"}',
            ['customer_phone' => '201000000000', 'customer_name' => 'أمير', 'total' => 349.5],
        );

        $this->assertSame('201000000000', $rendered['to']);
        $this->assertSame(['أمير', 349.5], $rendered['template']['data']);
        // A whole-value placeholder keeps its type rather than becoming a string.
        $this->assertSame(349.5, $rendered['amount']);
    }

    public function test_order_gate_parks_checkout_until_confirmation_then_releases_it(): void
    {
        $this->enableIntegration();
        app(RoboDeskActionRegistry::class)->save(ConfirmOrderAction::KEY, true, ['gate_production' => '1']);

        $this->assertSame('pending_confirmation', app(OrderConfirmationGate::class)->initialStatus());
        // Staff-created orders never wait on a WhatsApp round-trip.
        $this->assertSame('new', app(OrderConfirmationGate::class)->initialStatus(customerPlaced: false));

        $order = $this->order('CHK-GATE-OPEN', 'pending_confirmation');

        $this->postSigned('order.confirmed', ['checkout_reference' => $order->checkout_group_key]);

        $this->assertSame('new', $order->refresh()->status);
    }

    public function test_delivered_shipment_queues_a_csat_request(): void
    {
        $this->enableIntegration();
        app(RoboDeskActionRegistry::class)->save(RequestCsatAction::KEY, true, ['delay_minutes' => '60']);

        $order = $this->order('CHK-CSAT');
        $order->forceFill(['shipping_status' => OrderWorkflowStatus::SHIPPING_DELIVERED])->save();

        $event = RoboDeskIntegrationEvent::query()
            ->where('event_type', RequestCsatAction::KEY)
            ->where('checkout_group_key', $order->checkout_group_key)
            ->firstOrFail();

        $this->assertTrue($event->available_at->greaterThan(now()->addMinutes(50)));
    }

    public function test_submitted_csat_is_recorded_against_the_checkout(): void
    {
        $this->enableIntegration();
        $order = $this->order('CHK-CSAT-IN');

        $this->postSigned('csat.submitted', [
            'checkout_reference' => $order->checkout_group_key,
            'score' => 5,
            'comment' => 'ممتاز',
            'message_id' => 'csat-msg-1',
        ]);

        $this->assertDatabaseHas('order_csat_responses', [
            'checkout_group_key' => $order->checkout_group_key,
            'score' => 5,
        ]);

        // A resent survey reply must not create a second row.
        $this->postSigned('csat.submitted', [
            'checkout_reference' => $order->checkout_group_key,
            'score' => 5,
            'message_id' => 'csat-msg-1',
        ]);
        $this->assertSame(1, OrderCsatResponse::query()->count());
    }

    public function test_identity_gate_stops_auto_approval_only_while_it_is_on(): void
    {
        $this->assertFalse(app(ConfirmIdentityGate::class)->isOpen());

        $this->enableIntegration();
        app(RoboDeskActionRegistry::class)->save(ConfirmIdentityAction::KEY, true, ['gate_auto_approval' => '1']);
        $this->assertTrue(app(ConfirmIdentityGate::class)->isOpen());

        // Turning the integration off must restore auto-approval rather than
        // leave identities waiting on a reply that can never arrive.
        app(RoboDeskSettings::class)->save(['robodesk_enabled' => '0']);
        $this->assertFalse(app(ConfirmIdentityGate::class)->isOpen());
    }

    // ── Common exceptions ────────────────────────────────────────────────

    public function test_nothing_is_queued_while_an_action_is_disabled(): void
    {
        $this->enableIntegration();
        $this->order('CHK-DISABLED');

        $this->assertDatabaseMissing('robodesk_integration_events', [
            'event_type' => ConfirmOrderAction::KEY,
        ]);
    }

    public function test_gates_stay_closed_when_the_integration_is_disabled(): void
    {
        // Action on, integration off: behaviour must not change.
        app(RoboDeskActionRegistry::class)->save(ConfirmOrderAction::KEY, true, ['gate_production' => '1']);
        app(RoboDeskSettings::class)->save(['robodesk_enabled' => '0']);

        $this->assertFalse(app(OrderConfirmationGate::class)->isOpen());
        $this->assertSame('new', app(OrderConfirmationGate::class)->initialStatus());
    }

    public function test_confirmation_never_drags_a_live_order_backwards(): void
    {
        $this->enableIntegration();
        $order = $this->order('CHK-ALREADY-LIVE', 'generating');

        $this->postSigned('order.confirmed', ['checkout_reference' => $order->checkout_group_key]);

        // Only orders parked at pending_confirmation move.
        $this->assertSame('generating', $order->refresh()->status);
    }

    public function test_malformed_payload_template_is_rejected_by_the_admin_screen(): void
    {
        $admin = $this->admin(['robodesk.configure']);

        $this->actingAs($admin)
            ->post(route('admin.robodesk.settings.actions.update', ConfirmOrderAction::KEY), [
                'is_enabled' => '1',
                'params' => ['payload_template' => '{not json'],
            ])
            ->assertSessionHasErrors('params.payload_template');

        $this->assertDatabaseMissing('robodesk_action_settings', [
            'action_key' => ConfirmOrderAction::KEY,
            'is_enabled' => true,
        ]);
    }

    public function test_unknown_template_placeholders_are_rejected(): void
    {
        $admin = $this->admin(['robodesk.configure']);

        $this->actingAs($admin)
            ->post(route('admin.robodesk.settings.actions.update', ConfirmOrderAction::KEY), [
                'is_enabled' => '1',
                'params' => ['payload_template' => '{"to":"{{ not_a_real_variable }}"}'],
            ])
            ->assertSessionHasErrors('params.payload_template');
    }

    public function test_settings_screen_requires_its_permissions(): void
    {
        $this->actingAs($this->admin(['robodesk.view']))
            ->get(route('admin.robodesk.settings.index'))
            ->assertForbidden();

        $this->actingAs($this->admin(['robodesk.configure']))
            ->get(route('admin.robodesk.settings.index'))
            ->assertOk();
    }

    public function test_secrets_are_encrypted_and_never_rendered_back(): void
    {
        $admin = $this->admin(['robodesk.configure', 'robodesk.manage_credentials']);

        $this->actingAs($admin)
            ->post(route('admin.robodesk.settings.credentials'), [
                'credential_type' => 'auth_token',
                'value' => 'super-secret-token-1234',
            ])
            ->assertRedirect();

        $stored = (string) DB::table('robodesk_credentials')
            ->where('credential_type', 'auth_token')
            ->value('encrypted_value');

        $this->assertStringNotContainsString('super-secret-token-1234', $stored);
        $this->assertSame('super-secret-token-1234', app(RoboDeskCredentialService::class)->value('auth_token'));

        $this->actingAs($admin)
            ->get(route('admin.robodesk.settings.index'))
            ->assertOk()
            ->assertDontSee('super-secret-token-1234')
            ->assertSee('••••••••1234');
    }

    public function test_credentials_cannot_be_saved_without_the_credential_permission(): void
    {
        $this->actingAs($this->admin(['robodesk.configure']))
            ->post(route('admin.robodesk.settings.credentials'), [
                'credential_type' => 'auth_token',
                'value' => 'another-secret-value',
            ])
            ->assertForbidden();
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function enableIntegration(): void
    {
        app(RoboDeskSettings::class)->save([
            'robodesk_enabled' => '1',
            'robodesk_base_url' => 'https://herokid.robodesk.test',
        ]);
        app(RoboDeskSettings::class)->save(['robodesk_inbound_auth_mode' => 'signature']);
        app(RoboDeskCredentialService::class)->save('inbound_secret', 'inbound-test-secret');
        app(RoboDeskCredentialService::class)->save('outbound_secret', 'outbound-test-secret');
    }

    private function admin(array $permissions): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
        $admin->unsetRelation('permissions');

        return $admin;
    }

    private function postSigned(string $type, array $data): void
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'occurred_at' => now()->toIso8601String(),
            'data' => $data,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;

        $this->call(
            'POST',
            '/api/integrations/robodesk/v1/events',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ROBODESK_TIMESTAMP' => $timestamp,
                'HTTP_X_ROBODESK_EVENT_ID' => $payload['id'],
                'HTTP_X_ROBODESK_SIGNATURE' => hash_hmac(
                    'sha256',
                    $timestamp.'.'.$payload['id'].'.'.hash('sha256', $body),
                    'inbound-test-secret',
                ),
            ],
            $body,
        )->assertSuccessful();
    }

    private function order(string $checkout, string $status = 'new'): Order
    {
        return Order::query()->create([
            'order_number' => 'HK-'.Str::upper(Str::random(10)),
            'checkout_group_key' => $checkout,
            'parent_name' => 'ولي الأمر',
            'status' => $status,
            'uploaded_photos' => [],
            'delivery_details' => [
                'checkout_group' => $checkout,
                'phone' => '01501188884',
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'street' => 'شارع 1',
                'address_details' => 'الدور الثالث',
                'delivery_fee' => 50,
            ],
        ]);
    }
}
