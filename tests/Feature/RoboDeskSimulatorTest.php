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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoboDeskSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing should reach the network in simulation mode; the fake proves
        // it rather than assuming it.
        Http::fake(['*' => Http::response(['accepted' => true], 202)]);
    }

    // ── Happy paths ──────────────────────────────────────────────────────

    public function test_simulation_mode_records_the_exact_payload_without_sending_it(): void
    {
        $this->simulate();
        $this->configure('{"to":"{{ customer_phone }}","templateName":"herokid_order_confirm","data":["{{ customer_name }}","{{ total }}"]}');

        $order = $this->order('CHK-SIM-1');

        $event = RoboDeskIntegrationEvent::query()
            ->where('event_type', RoboDeskIntegrationRegistry::ORDER_CONFIRMATION)
            ->where('checkout_group_key', $order->checkout_group_key)
            ->firstOrFail();

        $this->assertSame('succeeded', $event->status);
        $this->assertTrue(data_get($event->response_payload, 'simulated'));
        $this->assertSame(
            'https://robodesk.test/conversation/start/sendMsg',
            data_get($event->response_payload, 'would_have_sent.url'),
        );
        // The saved template is the whole body — nothing is wrapped around it.
        $body = data_get($event->response_payload, 'would_have_sent.body');
        $this->assertSame('herokid_order_confirm', $body['templateName']);
        // MySQL's JSON type normalises object key order, so compare the set.
        // Ordered *lists* — which is what template variables are — survive.
        $keys = array_keys($body);
        sort($keys);
        $this->assertSame(['data', 'templateName', 'to'], $keys);
        $this->assertSame(['ولي الأمر', 50], $body['data']);
        $this->assertArrayNotHasKey('_rendered', $body);
        $this->assertSame('201501188884', $body['to']);

        Http::assertNothingSent();
    }

    public function test_simulated_confirmation_moves_the_order_and_is_recorded_in_the_thread(): void
    {
        $this->simulate();
        app(RoboDeskSettings::class)->save(['robodesk_gate_order_confirmation' => '1']);
        $this->configure('');

        $order = $this->order('CHK-SIM-2', 'pending_confirmation');

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.simulator.reply', $order->checkout_group_key), [
                'type' => 'order.confirmed',
            ])
            ->assertRedirect();

        $this->assertSame('new', $order->refresh()->status);
        $this->assertDatabaseHas('robodesk_integration_events', [
            'direction' => 'inbound',
            'event_type' => 'order.confirmed',
            'checkout_group_key' => $order->checkout_group_key,
            'status' => 'succeeded',
        ]);
    }

    public function test_simulated_payment_proof_is_stored_privately_and_never_marks_the_order_paid(): void
    {
        Storage::fake('local');
        $this->simulate();
        $order = $this->order('CHK-SIM-3');

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.simulator.reply', $order->checkout_group_key), [
                'type' => 'payment.proof',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('order_payment_proofs', [
            'checkout_group_key' => $order->checkout_group_key,
            'status' => 'pending',
        ]);
        $this->assertSame('unpaid', $order->refresh()->payment_status);
    }

    public function test_simulated_csat_is_recorded_with_its_score(): void
    {
        $this->simulate();
        $order = $this->order('CHK-SIM-4');

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.simulator.reply', $order->checkout_group_key), [
                'type' => 'csat.submitted',
                'score' => 4,
                'comment' => 'جيد',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('order_csat_responses', [
            'checkout_group_key' => $order->checkout_group_key,
            'score' => 4,
        ]);
    }

    public function test_the_thread_screen_renders_both_directions(): void
    {
        $this->simulate();
        $this->configure('');
        $order = $this->order('CHK-SIM-5');

        $this->actingAs($this->admin())
            ->get(route('admin.robodesk.simulator.show', $order->checkout_group_key))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee(RoboDeskIntegrationRegistry::ORDER_CONFIRMATION);
    }

    // ── Common exceptions ────────────────────────────────────────────────

    public function test_identity_change_request_without_a_comment_is_rejected(): void
    {
        $this->simulate();
        $order = $this->order('CHK-SIM-6');

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.simulator.reply', $order->checkout_group_key), [
                'type' => 'identity.changes_requested',
                'comment' => '',
            ])
            ->assertSessionHasErrors();
    }

    public function test_unknown_reply_type_is_rejected(): void
    {
        $this->simulate();
        $order = $this->order('CHK-SIM-7');

        $this->actingAs($this->admin())
            ->post(route('admin.robodesk.simulator.reply', $order->checkout_group_key), [
                'type' => 'not.a.real.type',
            ])
            ->assertSessionHasErrors('type');
    }

    public function test_simulator_requires_the_configure_permission(): void
    {
        $order = $this->order('CHK-SIM-8');

        $this->actingAs($this->admin(['robodesk.view']))
            ->get(route('admin.robodesk.simulator.show', $order->checkout_group_key))
            ->assertForbidden();
    }

    public function test_unknown_checkout_returns_not_found(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.robodesk.simulator.show', 'CHK-DOES-NOT-EXIST'))
            ->assertNotFound();
    }

    public function test_inbound_token_auth_rejects_a_missing_or_wrong_token(): void
    {
        app(RoboDeskSettings::class)->save(['robodesk_enabled' => '1']);
        $this->configure('');

        $payload = ['id' => (string) Str::uuid(), 'type' => 'order.confirmed', 'data' => ['checkout_reference' => 'x']];

        $this->postJson('/api/integrations/robodesk/v1/events', $payload)->assertStatus(401);

        $this->withHeader('X-RoboDesk-Token', 'wrong-token')
            ->postJson('/api/integrations/robodesk/v1/events', $payload)
            ->assertStatus(401);
    }

    public function test_inbound_token_auth_accepts_the_configured_token(): void
    {
        app(RoboDeskSettings::class)->save(['robodesk_enabled' => '1']);
        $this->configure('');
        $order = $this->order('CHK-SIM-TOKEN', 'pending_confirmation');

        $eventId = (string) Str::uuid();

        $this->withHeaders([
            'X-RoboDesk-Token' => 'static-token',
        ])->postJson('/api/integrations/robodesk/v1/events', [
            'id' => $eventId,
            'type' => 'order.confirmed',
            'data' => ['checkout_reference' => $order->checkout_group_key],
        ])->assertAccepted();

        $this->assertSame('new', $order->refresh()->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function configure(string $payload): void
    {
        app(RoboDeskIntegrationRegistry::class)->save(
            RoboDeskIntegrationRegistry::ORDER_CONFIRMATION,
            true,
            'https://robodesk.test/conversation/start/sendMsg',
            'static-token',
            $payload,
        );
    }

    private function simulate(): void
    {
        app(RoboDeskSettings::class)->save([
            'robodesk_enabled' => '1',
            'robodesk_simulation_mode' => '1',
        ]);
    }

    private function admin(array $permissions = ['robodesk.configure', 'robodesk.view']): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));
        $admin->unsetRelation('permissions');

        return $admin;
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
