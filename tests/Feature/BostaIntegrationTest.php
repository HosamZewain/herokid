<?php

namespace Tests\Feature;

use App\Models\BostaPickup;
use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\User;
use App\Services\Bosta\BostaShipmentEligibilityService;
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
        $this->assertSame(['shipment_created'], $orders->map->refresh()->pluck('shipping_status')->unique()->values()->all());
        $this->assertDatabaseCount('order_status_logs', 2);
    }

    public function test_failed_delivery_creation_is_preserved_and_can_be_retried_without_duplicate_local_shipments(): void
    {
        $order = $this->checkout('BOSTA-RETRY', 20_000)->first();
        Http::fake([
            '*/cities/*/districts' => Http::response(['data' => [[
                'districtId' => 'district-nasr-city',
                'districtName' => 'Nasr City',
                'districtOtherName' => 'مدينة نصر',
                'dropOffAvailability' => true,
            ]]]),
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
            ->get(route('admin.orders.groups.show', $order->id))
            ->assertOk()
            ->assertSee('مراجعة البيانات وإعادة محاولة إنشاء الشحنة')
            ->assertSee('حفظ التعديلات وإعادة المحاولة')
            ->assertSee('name="bosta_city_id"', false)
            ->assertSee('name="bosta_district_id"', false)
            ->assertSee('عنوان العميل الأصلي للمطابقة اليدوية');

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $order->id), [
                'district_name' => 'ElMaadi',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('bosta_shipments', 1);
        $this->assertDatabaseHas('bosta_shipments', [
            'checkout_group_key' => 'BOSTA-RETRY',
            'creation_status' => 'created',
            'tracking_number' => 'TRACK-RETRY',
        ]);

        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/deliveries?apiVersion=1')
            && $request['dropOffAddress']['districtName'] === 'ElMaadi');
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

        $this->actingAs($this->admin)
            ->get(route('admin.bosta.index'))
            ->assertOk()
            ->assertDontSee('TRACK-PICKUP');
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

    public function test_bosta_queue_only_lists_checkouts_whose_entire_shipping_status_is_ready(): void
    {
        $ready = $this->checkout('BOSTA-READY-QUEUE', 20_000);
        $notReady = $this->checkout('BOSTA-NOT-READY-QUEUE', 20_000);
        $notReady->each->update(['shipping_status' => 'not_ready']);
        $mixed = $this->checkout('BOSTA-MIXED-QUEUE', 20_000);
        $mixed->last()->update(['shipping_status' => 'not_ready']);

        $eligibleGroups = app(BostaShipmentEligibilityService::class)
            ->eligibleRepresentatives()
            ->map->checkoutGroupKey();
        $this->assertTrue($eligibleGroups->contains('BOSTA-READY-QUEUE'));
        $this->assertFalse($eligibleGroups->contains('BOSTA-NOT-READY-QUEUE'));
        $this->assertFalse($eligibleGroups->contains('BOSTA-MIXED-QUEUE'));

        $this->actingAs($this->admin)
            ->get(route('admin.bosta.index'))
            ->assertOk()
            ->assertSee(route('admin.orders.show', $ready->first()), false)
            ->assertDontSee(route('admin.orders.show', $notReady->first()), false)
            ->assertDontSee(route('admin.orders.show', $mixed->first()), false)
            ->assertSee('تظهر هنا للمراجعة فقط. افتح الطلب لمراجعة بيانات المستلم والعنوان وCOD ثم إنشاء الشحنة.');
    }

    public function test_direct_shipment_creation_rejects_checkout_until_every_order_is_ready_for_shipping(): void
    {
        $orders = $this->checkout('BOSTA-NOT-READY-CREATE', 20_000);
        $orders->last()->update(['shipping_status' => 'not_ready']);
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $orders->first()->id))
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertDatabaseCount('bosta_shipments', 0);
        Http::assertNothingSent();
    }

    public function test_checkout_order_page_shows_bosta_readiness_and_existing_shipment_details(): void
    {
        $orders = $this->checkout('BOSTA-ORDER-PAGE', 20_000);
        Http::fake([
            '*/cities/*/districts' => Http::response(['data' => []]),
            '*/cities*' => Http::response(['data' => ['list' => [[
                '_id' => 'city-cairo', 'name' => 'Cairo', 'otherName' => 'القاهرة',
            ]]]]),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $orders->first()->id))
            ->assertOk()
            ->assertSee('شحن Bosta')
            ->assertSee('جاهز لإنشاء الشحنة')
            ->assertSee('مراجعة بيانات الشحنة وإنشاؤها')
            ->assertSee('COD لدى Bosta');

        BostaShipment::query()->create([
            'checkout_group_key' => 'BOSTA-ORDER-PAGE',
            'order_id' => $orders->first()->id,
            'bosta_delivery_id' => 'delivery-order-page',
            'tracking_number' => 'TRACK-ORDER-PAGE',
            'business_reference' => 'HK09-99',
            'creation_status' => 'created',
            'shipping_status' => 'picked_up',
            'cod_amount_cents' => 25_000,
            'business_location_id' => 'location-123',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $orders->first()->id))
            ->assertOk()
            ->assertSee('TRACK-ORDER-PAGE')
            ->assertSee('picked_up')
            ->assertSee('تم إنشاء الشحنة');
    }

    public function test_admin_selects_official_bosta_city_and_district_and_sends_rich_description(): void
    {
        $orders = $this->checkout('BOSTA-OFFICIAL-ADDRESS', 40_000);
        $item = $orders->first()->items()->firstOrFail();
        $item->update([
            'title' => 'ستيكر مخصص',
            'quantity' => 2,
            'personalization_snapshot' => ['children' => [
                ['child_name' => 'Laila'],
                ['child_name' => 'Omar'],
            ]],
        ]);
        Http::fake([
            '*/cities/city-cairo/districts' => Http::response(['data' => [[
                'districtId' => 'district-maadi',
                'districtName' => 'ElMaadi',
                'districtOtherName' => 'المعادي',
                'zoneId' => 'zone-maadi',
                'dropOffAvailability' => true,
            ]]]),
            '*/cities*' => Http::response(['data' => ['list' => [[
                '_id' => 'city-cairo',
                'name' => 'Cairo',
                'otherName' => 'القاهرة',
            ]]]]),
            '*/deliveries?apiVersion=1' => Http::response(['data' => [
                '_id' => 'delivery-official',
                'trackingNumber' => 'TRACK-OFFICIAL',
            ]]),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $orders->first()->id))
            ->assertOk()
            ->assertSee('محافظة Bosta')
            ->assertSee('المعادي — ElMaadi')
            ->assertSee('عنوان العميل الأصلي للمطابقة اليدوية');

        $this->actingAs($this->admin)
            ->getJson(route('admin.bosta.districts', ['city_id' => 'city-cairo']))
            ->assertOk()
            ->assertJsonPath('districts.0.id', 'district-maadi');

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $orders->first()->id), [
                'receiver_name' => 'عميل Bosta',
                'receiver_phone' => '01001234567',
                'bosta_city_id' => 'city-cairo',
                'bosta_district_id' => 'district-maadi',
                'first_line' => '٧٢ شارع النهضة المعادي',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function (HttpRequest $request) use ($orders): bool {
            if (! str_contains($request->url(), '/deliveries?apiVersion=1')) {
                return false;
            }

            $description = (string) $request['specs']['packageDetails']['description'];
            $reference = $orders->first()->refresh()->checkoutReference?->short_reference ?: $orders->first()->order_number;

            return $request['dropOffAddress']['cityId'] === 'city-cairo'
                && $request['dropOffAddress']['districtId'] === 'district-maadi'
                && ! isset($request['dropOffAddress']['districtName'])
                && str_contains($description, 'ولي الأمر: عميل Bosta')
                && str_contains($description, 'الهاتف: 01001234567')
                && str_contains($description, 'الطلب: '.$reference)
                && str_contains($description, 'ستيكر مخصص × 2')
                && str_contains($description, 'Laila')
                && str_contains($description, 'Omar');
        });
    }

    public function test_awb_defaults_to_a6_and_accepts_a4(): void
    {
        $orders = $this->checkout('BOSTA-AWB', 20_000);
        $shipment = BostaShipment::query()->create([
            'checkout_group_key' => 'BOSTA-AWB',
            'order_id' => $orders->first()->id,
            'bosta_delivery_id' => 'delivery-awb',
            'tracking_number' => 'TRACK-AWB',
            'business_reference' => 'HK09-500',
            'creation_status' => 'created',
            'cod_amount_cents' => 0,
            'business_location_id' => 'location-123',
        ]);
        Http::fake(['*/deliveries/mass-awb' => Http::response(['data' => ['file' => base64_encode('%PDF-test')]])]);

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.awb'), ['shipments' => [$shipment->id]])
            ->assertOk();
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/deliveries/mass-awb')
            && $request['requestedAwbType'] === 'A6');

        Http::fake(['*/deliveries/mass-awb' => Http::response(['data' => ['file' => base64_encode('%PDF-test')]])]);
        $this->actingAs($this->admin)
            ->post(route('admin.bosta.awb'), ['shipments' => [$shipment->id], 'awb_type' => 'A4'])
            ->assertOk();
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/deliveries/mass-awb')
            && $request['requestedAwbType'] === 'A4');
    }

    public function test_admin_can_review_and_override_shipment_data_without_recording_a_payment(): void
    {
        $orders = $this->checkout('BOSTA-EDITABLE-DATA', 40_000, 10_000);
        $paymentEventsBefore = OrderPaymentEvent::query()->count();
        Http::fake([
            '*/cities*' => Http::response(['data' => ['list' => [[
                '_id' => 'city-giza',
                'name' => 'Giza',
                'otherName' => 'الجيزة',
            ]]]]),
            '*/deliveries?apiVersion=1' => Http::response(['data' => [
                '_id' => 'delivery-edited',
                'trackingNumber' => 'TRACK-EDITED',
            ]]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bosta.shipments.store', $orders->first()->id), [
                'receiver_name' => 'مستلم معدل',
                'receiver_phone' => '01111111111',
                'governorate' => 'الجيزة',
                'district_name' => 'الدقي',
                'first_line' => '١٥ شارع التحرير الرئيسي',
                'second_line' => 'الدور الثالث',
                'cod_amount' => '175.50',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(function (HttpRequest $request): bool {
            if (! str_contains($request->url(), '/deliveries?apiVersion=1')) {
                return false;
            }

            return $request['receiver']['fullName'] === 'مستلم معدل'
                && $request['receiver']['phone'] === '01111111111'
                && $request['dropOffAddress']['cityId'] === 'city-giza'
                && $request['dropOffAddress']['districtName'] === 'الدقي'
                && $request['dropOffAddress']['firstLine'] === '١٥ شارع التحرير الرئيسي'
                && $request['dropOffAddress']['secondLine'] === 'الدور الثالث'
                && $request['cod'] === 175.5;
        });

        $this->assertDatabaseHas('bosta_shipments', [
            'checkout_group_key' => 'BOSTA-EDITABLE-DATA',
            'cod_amount_cents' => 17_550,
            'tracking_number' => 'TRACK-EDITED',
        ]);
        $this->assertSame($paymentEventsBefore, OrderPaymentEvent::query()->count());
        $this->assertSame([10_000], $orders->map->refresh()->pluck('paid_amount_cents')->unique()->values()->all());
        $this->assertSame(['shipment_created'], $orders->map->refresh()->pluck('shipping_status')->unique()->values()->all());
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
                'shipping_status' => 'ready',
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
