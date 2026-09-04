<?php

namespace Tests\Feature;

use App\Models\BostaPickup;
use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BostaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        config()->set([
            'bosta.enabled' => true,
            'bosta.api_key' => 'test-bosta-key',
            'bosta.business_location_id' => 'location-123',
            'bosta.country_id' => 'egypt-123',
            'bosta.webhook_secret' => 'webhook-secret',
            'bosta.webhook_header' => 'X-Bosta-Webhook-Secret',
            'bosta.retries' => 0,
        ]);
        Cache::flush();
    }

    public function test_admin_creates_one_checkout_shipment_with_remaining_amount_as_operational_cod(): void
    {
        $orders = $this->checkout('BOSTA-CREATE', 40_000, 15_000, 5_000);
        $paymentEventsBefore = OrderPaymentEvent::query()->count();

        Http::fake([
            '*/cities*' => Http::response(['data' => ['list' => [[
                '_id' => 'city-cairo',
                'name' => 'Cairo',
                'otherName' => 'القاهرة',
            ]]]]),
            '*/deliveries?apiVersion=1' => Http::response(['data' => [
                '_id' => 'delivery-123',
                'trackingNumber' => 'TRACK-123',
            ]]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $orders->first()->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $shipment = BostaShipment::query()->firstOrFail();
        $this->assertSame('created', $shipment->creation_status);
        $this->assertSame(30_000, $shipment->cod_amount_cents);
        $this->assertSame('Small', $shipment->package_type);
        $this->assertFalse($shipment->allow_open_package);

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_contains($request->url(), '/deliveries?apiVersion=1')) {
                return false;
            }

            return $request['businessLocationId'] === 'location-123'
                && $request['cod'] === 300
                && $request['allowToOpenPackage'] === false
                && $request['dropOffAddress']['cityId'] === 'city-cairo'
                && $request['dropOffAddress']['districtName'] === 'مدينة نصر'
                && $request['specs']['packageType'] === 'Small';
        });

        $this->assertSame($paymentEventsBefore, OrderPaymentEvent::query()->count());
        $this->assertSame([15_000], $orders->map->refresh()->pluck('paid_amount_cents')->unique()->values()->all());
        $this->assertSame(['partially_paid'], $orders->map->refresh()->pluck('payment_status')->unique()->values()->all());
    }

    public function test_failed_delivery_creation_is_preserved_and_can_be_retried_without_duplicate_local_shipments(): void
    {
        $order = $this->checkout('BOSTA-RETRY', 20_000)->first();
        Http::fake([
            '*/cities*' => Http::response(['data' => [[
                '_id' => 'city-cairo',
                'name' => 'Cairo',
                'otherName' => 'القاهرة',
            ]]]),
            '*/deliveries?apiVersion=1' => Http::sequence()
                ->push(['message' => 'temporary failure'], 500)
                ->push(['data' => ['_id' => 'delivery-retry', 'trackingNumber' => 'TRACK-RETRY']], 200),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $order->id))
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertDatabaseCount('bosta_shipments', 1);
        $this->assertDatabaseHas('bosta_shipments', [
            'checkout_group_key' => 'BOSTA-RETRY',
            'creation_status' => 'failed',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $order->id))
            ->assertRedirect();

        $this->assertDatabaseCount('bosta_shipments', 1);
        $this->assertDatabaseHas('bosta_shipments', [
            'checkout_group_key' => 'BOSTA-RETRY',
            'creation_status' => 'created',
            'tracking_number' => 'TRACK-RETRY',
        ]);
    }

    public function test_recent_pending_delivery_prevents_a_concurrent_duplicate_provider_request(): void
    {
        $order = $this->checkout('BOSTA-PENDING', 20_000)->first();
        BostaShipment::query()->create([
            'checkout_group_key' => 'BOSTA-PENDING',
            'order_id' => $order->id,
            'business_reference' => 'HK09-12',
            'creation_status' => 'pending',
            'cod_amount_cents' => 25_000,
            'business_location_id' => 'location-123',
        ]);
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $order->id))
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertDatabaseCount('bosta_shipments', 1);
        Http::assertNothingSent();
    }

    public function test_bosta_webhook_updates_shipping_only_and_is_idempotent(): void
    {
        $orders = $this->checkout('BOSTA-WEBHOOK', 50_000, 10_000);
        $shipment = BostaShipment::query()->create([
            'checkout_group_key' => 'BOSTA-WEBHOOK',
            'order_id' => $orders->first()->id,
            'bosta_delivery_id' => 'delivery-webhook',
            'tracking_number' => 'TRACK-WEBHOOK',
            'business_reference' => 'HK09-10',
            'creation_status' => 'created',
            'cod_amount_cents' => 45_000,
            'business_location_id' => 'location-123',
        ]);
        $paymentEventsBefore = OrderPaymentEvent::query()->count();
        $payload = [
            '_id' => 'delivery-webhook',
            'trackingNumber' => 'TRACK-WEBHOOK',
            'businessReference' => 'HK09-10',
            'state' => 45,
            'timeStamp' => 1_787_000_000_000,
            'cod' => 450,
            'isConfirmedDelivery' => true,
            'numberOfAttempts' => 1,
        ];

        $this->withHeader('X-Bosta-Webhook-Secret', 'webhook-secret')
            ->postJson(route('integrations.bosta.webhook'), $payload)
            ->assertOk()
            ->assertJson(['success' => true, 'shipment_id' => $shipment->id]);
        $this->withHeader('X-Bosta-Webhook-Secret', 'webhook-secret')
            ->postJson(route('integrations.bosta.webhook'), $payload)
            ->assertOk();

        $this->assertDatabaseCount('bosta_shipment_events', 1);
        $this->assertSame(45_000, $shipment->refresh()->provider_reported_cod_cents);
        $this->assertSame(['delivered'], $orders->map->refresh()->pluck('shipping_status')->unique()->values()->all());
        $this->assertSame([10_000], $orders->map->refresh()->pluck('paid_amount_cents')->unique()->values()->all());
        $this->assertSame(['partially_paid'], $orders->map->refresh()->pluck('payment_status')->unique()->values()->all());
        $this->assertSame($paymentEventsBefore, OrderPaymentEvent::query()->count());
        $this->assertDatabaseCount('order_status_logs', 2);
    }

    public function test_admin_creates_manual_pickup_for_selected_shipments_without_touching_payments(): void
    {
        $orders = $this->checkout('BOSTA-PICKUP', 30_000, 5_000);
        $paymentEventsBefore = OrderPaymentEvent::query()->count();
        $shipment = BostaShipment::query()->create([
            'checkout_group_key' => 'BOSTA-PICKUP',
            'order_id' => $orders->first()->id,
            'bosta_delivery_id' => 'delivery-pickup',
            'tracking_number' => 'TRACK-PICKUP',
            'business_reference' => 'HK09-11',
            'creation_status' => 'created',
            'cod_amount_cents' => 30_000,
            'business_location_id' => 'location-123',
        ]);
        Http::fake([
            '*/pickups' => Http::response(['data' => [
                '_id' => 'pickup-123',
                'state' => 'Requested',
                'scheduledDate' => now()->addDay()->toDateString(),
            ]]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.pickups.store'), [
                'shipments' => [$shipment->id],
                'scheduled_date' => now()->addDay()->toDateString(),
                'contact_name' => 'HeroKid',
                'contact_phone' => '01501188884',
                'notes' => 'استلام يدوي',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $pickup = BostaPickup::query()->firstOrFail();
        $this->assertSame('pickup-123', $pickup->bosta_pickup_id);
        $this->assertSame(1, $pickup->number_of_parcels);
        $this->assertTrue($pickup->shipments()->whereKey($shipment->id)->exists());
        $this->assertSame($paymentEventsBefore, OrderPaymentEvent::query()->count());
        $this->assertSame([5_000], $orders->map->refresh()->pluck('paid_amount_cents')->unique()->values()->all());
    }

    public function test_webhook_rejects_invalid_secret_and_unknown_shipment(): void
    {
        $payload = ['trackingNumber' => 'UNKNOWN', 'state' => 45];

        $this->withHeader('X-Bosta-Webhook-Secret', 'wrong')
            ->postJson(route('integrations.bosta.webhook'), $payload)
            ->assertUnauthorized();

        $this->withHeader('X-Bosta-Webhook-Secret', 'webhook-secret')
            ->postJson(route('integrations.bosta.webhook'), $payload)
            ->assertNotFound();
    }

    public function test_bosta_page_and_actions_are_permission_protected(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.bosta.index'))
            ->assertOk()
            ->assertSee('Bosta للشحن')
            ->assertSee('COD معلومة تشغيلية للشحن فقط');

        $limited = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $limited->permissions()->sync(Permission::query()->where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');

        $this->actingAs($limited)->get(route('admin.bosta.index'))->assertForbidden();
        $this->actingAs($limited)
            ->post(route('admin.bosta.shipments.store', $this->checkout('BOSTA-PERMISSION', 10_000)->first()->id))
            ->assertForbidden();
    }

    /** @return Collection<int, Order> */
    private function checkout(string $group, int $itemsCents, int $paidCents = 0, int $deliveryCents = 5_000)
    {
        $orders = collect();

        foreach ([1, 2] as $index) {
            $order = Order::query()->create([
                'order_number' => 'HK-'.$group.'-'.$index,
                'checkout_group_key' => $group,
                'parent_name' => 'عميل Bosta',
                'language' => 'ar',
                'delivery_details' => [
                    'checkout_group' => $group,
                    'phone' => '01001234567',
                    'country' => 'مصر',
                    'governorate' => 'القاهرة',
                    'city' => 'مدينة نصر',
                    'street' => '١٠ شارع الاختبار الرئيسي',
                    'address_details' => 'الدور الثاني شقة ٥',
                    'delivery_fee' => $deliveryCents / 100,
                ],
                'uploaded_photos' => [],
                'status' => 'new',
                'printing_status' => 'not_started',
                'shipping_status' => 'not_ready',
                'payment_status' => $paidCents > 0 ? 'partially_paid' : 'unpaid',
                'paid_amount_cents' => $paidCents,
                'order_source' => 'website',
            ]);
            $lineCents = intdiv($itemsCents, 2) + ($index === 1 ? $itemsCents % 2 : 0);
            $order->items()->create([
                'item_type' => 'product',
                'title' => 'منتج Bosta '.$index,
                'unit_price_cents' => $lineCents,
                'quantity' => 1,
                'total_price_cents' => $lineCents,
            ]);
            $orders->push($order);
        }

        return $orders;
    }
}
