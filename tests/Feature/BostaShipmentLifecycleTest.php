<?php

namespace Tests\Feature;

use App\Models\BostaPickup;
use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Bosta\BostaAutomaticPickupService;
use App\Services\Bosta\BostaPickupSyncService;
use App\Services\Bosta\BostaWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BostaShipmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        config()->set(['bosta.enabled' => true, 'bosta.api_key' => 'synthetic', 'bosta.business_location_id' => 'location-test', 'bosta.pickup_sync_enabled' => false, 'bosta.retries' => 3]);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:30:00', 'UTC'));
    }

    public function test_cancel_and_recreate_uses_updated_checkout_and_retains_history_and_payments(): void
    {
        $old = $this->shipment();
        Http::fake($this->responses($old) + ['*/deliveries?apiVersion=1' => Http::response(['data' => ['_id' => 'new-delivery', 'trackingNumber' => 'NEW-TRACK']])]);
        $this->release($old, 'cancel')->assertSessionHas('success');
        $this->assertNotNull($old->refresh()->superseded_at);
        $this->assertNull($old->active_checkout_key);
        $order = $old->order->refresh();
        $order->update(['parent_name' => 'Updated Receiver', 'delivery_details' => array_replace($order->delivery_details, ['phone' => '01100000000'])]);
        $order->items()->create(['item_type' => 'product', 'title' => 'Extra product', 'quantity' => 2, 'unit_price_cents' => 5000, 'total_price_cents' => 10000]);
        $this->actingAs($this->admin)->post(route('admin.bosta.shipments.store', $order))->assertSessionHas('success');
        $this->assertDatabaseCount('bosta_shipments', 2);
        $this->assertSame(1, BostaShipment::current()->count());
        $this->assertSame(5000, $order->refresh()->paid_amount_cents);
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/deliveries?') && $r['receiver']['phone'] === '01100000000' && $r['receiver']['fullName'] === 'Updated Receiver' && $r['cod'] === 300);
        // Delayed old-carrier events must not overwrite the replacement order status.
        app(BostaWebhookService::class)->handle(['_id' => 'old-delivery', 'trackingNumber' => 'OLD-TRACK', 'businessReference' => 'HK-TEST', 'state' => 45, 'timeStamp' => now()->timestamp]);
        $this->assertSame('shipment_created', $order->refresh()->shipping_status);
        $this->assertSame('delivered', $old->refresh()->shipping_status);
        $this->actingAs($this->admin)->get(route('admin.orders.groups.show', $order))->assertOk()->assertSee('البوالص السابقة')->assertSee('NEW-TRACK')->assertSee('OLD-TRACK');
    }

    public function test_in_transit_shipment_cannot_be_cancelled_or_released(): void
    {
        $old = $this->shipment();
        Http::fake($this->responses($old, 'Delivering', 30));
        $this->release($old, 'cancel')->assertSessionHasErrors('order');
        $this->release($old, 'reship')->assertSessionHasErrors('order');
        $this->assertNull($old->refresh()->superseded_at);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_returned_lost_and_damaged_can_be_released_without_deleting_provider_delivery(): void
    {
        foreach (['Returned to business', 'Lost', 'Damaged'] as $i => $state) {
            $old = $this->shipment('terminal-'.$i);
            Http::fake($this->responses($old, $state, 60));
            $this->release($old, 'reship')->assertSessionHas('success');
            $this->assertNotNull($old->refresh()->superseded_at);
            $this->assertSame('ready', $old->order->refresh()->shipping_status);
        }
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_uncertain_cancel_blocks_replacement_and_can_only_finish_after_provider_confirms(): void
    {
        $old = $this->shipment();
        $responses = $this->responses($old);
        $responses['*/terminate'] = Http::response(['success' => false], 500);
        $responses['*/deliveries/business/'.$old->tracking_number] = Http::sequence()
            ->push(['success' => true, 'data' => ['_id' => $old->bosta_delivery_id, 'state' => ['value' => 'Pickup requested', 'code' => 10]]])
            ->push(['success' => true, 'data' => ['_id' => $old->bosta_delivery_id, 'state' => ['value' => 'Terminated', 'code' => 49]]]);
        Http::fake($responses);
        $this->release($old, 'cancel')->assertSessionHasErrors('order');
        $this->assertSame('uncertain', $old->refresh()->cancellation_status);
        $this->assertNull($old->superseded_at);
        Http::assertSentCount(2); // No mutation retries despite configured retries=3.
        $this->release($old, 'cancel')->assertSessionHas('success');
        $this->assertNotNull($old->refresh()->superseded_at);
        $this->assertSame(1, count(Http::recorded(fn ($r) => $r->method() === 'DELETE')));
    }

    public function test_unknown_provider_id_never_falls_back_to_shared_checkout_reference(): void
    {
        $old = $this->shipment();
        $this->expectException(ModelNotFoundException::class);
        app(BostaWebhookService::class)->handle(['_id' => 'unknown-delivery', 'businessReference' => $old->business_reference, 'state' => 45]);
    }

    public function test_out_of_order_webhook_is_preserved_but_does_not_regress_shipping_state(): void
    {
        $old = $this->shipment();
        $webhook = app(BostaWebhookService::class);
        $webhook->handle(['_id' => $old->bosta_delivery_id, 'state' => 45, 'timeStamp' => now()->timestamp]);
        $webhook->handle(['_id' => $old->bosta_delivery_id, 'state' => 10, 'timeStamp' => now()->subHour()->timestamp]);
        $this->assertSame('delivered', $old->order->refresh()->shipping_status);
        $this->assertSame(2, $old->events()->count());
    }

    public function test_pickup_normalizes_phone_omits_null_notes_and_sends_exact_tracking_numbers(): void
    {
        $shipment = $this->shipment();
        Http::fake($this->pickupResponses());
        $this->pickup($shipment)->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['contactPerson']['phone'] === '01000000000' && ! array_key_exists('notes', $r->data()) && $r['trackingNumbers'] === ['OLD-TRACK']);
        $this->pickup($shipment)->assertSessionHasErrors('shipments');
        $this->assertSame(1, count(Http::recorded(fn ($r) => $r->method() === 'POST')));
    }

    public function test_unavailable_pickup_day_fails_before_mutation(): void
    {
        $shipment = $this->shipment();
        $responses = $this->pickupResponses();
        $responses['*/pickups/available-dates*'] = Http::response(['data' => ['2026-11-02']]);
        Http::fake($responses);
        $this->pickup($shipment)->assertSessionHasErrors('scheduled_date');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertDatabaseCount('bosta_pickups', 0);
    }

    public function test_uncertain_pickup_is_retained_and_blocks_duplicate_send(): void
    {
        $shipment = $this->shipment();
        $responses = $this->pickupResponses();
        $responses['*/pickups'] = Http::response(['message' => 'unknown result'], 500);
        Http::fake($responses);
        $this->pickup($shipment)->assertSessionHasErrors('shipments');
        $this->assertDatabaseHas('bosta_pickups', ['status' => 'uncertain']);
        $this->assertFalse($shipment->refresh()->isAwaitingPickup());
        $this->pickup($shipment)->assertSessionHasErrors('shipments');
        $this->assertSame(1, count(Http::recorded(fn ($r) => $r->method() === 'POST')));
    }

    public function test_automatic_pickup_is_disabled_by_default_and_runs_once_for_eligible_shipments(): void
    {
        $shipment = $this->shipment();
        Http::fake($this->pickupResponses());
        $service = app(BostaAutomaticPickupService::class);
        $this->assertSame('disabled_or_outside_schedule', $service->run());
        Http::assertNothingSent();
        $this->enableAutomatic();
        $this->assertSame('created', $service->run());
        $this->assertSame('no_eligible_shipments', $service->run());
        $this->assertDatabaseCount('bosta_pickups', 1);
        $this->assertSame('daily:2026-11-01:location-test', BostaPickup::first()->request_key);
        $this->assertSame(1, count(Http::recorded(fn ($r) => $r->method() === 'POST')));
    }

    public function test_schedule_skips_friday_wrong_time_and_empty_shipments(): void
    {
        $this->enableAutomatic();
        Http::fake();
        $service = app(BostaAutomaticPickupService::class);
        $this->assertSame('no_eligible_shipments', $service->run());
        $this->shipment();
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:30:00', 'UTC'));
        $this->assertSame('disabled_or_outside_schedule', $service->run());
        $this->travelTo(CarbonImmutable::parse('2026-11-07 12:29:00', 'UTC'));
        $this->assertSame('disabled_or_outside_schedule', $service->run());
        Http::assertNothingSent();
    }

    public function test_unauthorized_employee_cannot_cancel_or_enable_automatic_pickup(): void
    {
        $shipment = $this->shipment();
        $staff = User::factory()->create(['role' => 'user']);
        Http::fake();
        $this->actingAs($staff)->post(route('admin.bosta.shipments.release', $shipment), ['action' => 'cancel', 'reason' => 'Change phone'])->assertForbidden();
        $this->actingAs($staff)->put(route('admin.bosta.automatic-pickup.update'), ['enabled' => 1, 'contact_name' => 'Test', 'contact_phone' => '01000000000'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_pickup_timeout_reconciliation_requires_exact_tracking_set_date_and_location(): void
    {
        $shipment = $this->shipment();
        $pickup = BostaPickup::create(['uuid' => (string) Str::uuid(), 'scheduled_date' => '2026-11-01', 'business_location_id' => 'location-test', 'contact_name' => 'Synthetic', 'contact_phone' => '01000000000', 'number_of_parcels' => 1, 'status' => 'uncertain', 'request_key' => 'daily:test']);
        $pickup->shipments()->attach($shipment);
        $data = ['_id' => 'external-verified', 'scheduledDate' => '2026-11-01', 'businessLocationId' => 'location-test', 'state' => 'Requested', 'deliveryTrackingNumbers' => ['OLD-TRACK']];
        Http::fake(['*/pickups/search*' => Http::response(['data' => ['list' => [$data], 'pages' => 1]]), '*/pickups/external-verified' => Http::response(['data' => $data])]);
        app(BostaPickupSyncService::class)->sync();
        $this->assertDatabaseCount('bosta_pickups', 1);
        $this->assertSame('external-verified', $pickup->refresh()->bosta_pickup_id);
        $this->assertSame('daily:test', $pickup->request_key);
        $this->assertSame('Requested', $pickup->status);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_existing_external_pickup_blocks_even_when_it_has_no_shipment_identifiers(): void
    {
        $shipment = $this->shipment();
        BostaPickup::create(['uuid' => (string) Str::uuid(), 'bosta_pickup_id' => 'external-no-items', 'scheduled_date' => '2026-11-01', 'business_location_id' => 'location-test', 'contact_name' => 'Synthetic', 'contact_phone' => '01000000000', 'number_of_parcels' => 0, 'status' => 'Requested']);
        Http::fake($this->pickupResponses());
        $this->pickup($shipment)->assertSessionHasErrors('shipments');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_scheduler_uses_cairo_clock_in_summer_and_winter_and_excludes_friday(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'bosta:automatic-pickup'));
        $this->assertNotNull($event);
        $this->assertSame('Africa/Cairo', $event->timezone);
        $this->assertTrue($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-07-01 11:30:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-11-06 12:30:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));
    }

    public function test_automatic_pickup_does_not_include_other_statuses_or_cancelled_orders(): void
    {
        $this->enableAutomatic();
        $shipment = $this->shipment();
        $shipment->order->update(['status' => 'cancelled']);
        Http::fake();
        $this->assertSame('no_eligible_shipments', app(BostaAutomaticPickupService::class)->run());
        $shipment->order->update(['status' => 'new', 'shipping_status' => 'ready']);
        $this->assertSame('no_eligible_shipments', app(BostaAutomaticPickupService::class)->run());
        Http::assertNothingSent();
    }

    public function test_ambiguous_create_is_not_retried_and_prevents_second_shipment(): void
    {
        $shipment = $this->shipment();
        $shipment->update(['bosta_delivery_id' => null, 'tracking_number' => null, 'creation_status' => 'failed']);
        $shipment->order->update(['shipping_status' => 'ready']);
        Http::fake(['*/deliveries?apiVersion=1' => Http::response(['success' => false], 500)] + $this->responses($shipment));
        $this->actingAs($this->admin)->post(route('admin.bosta.shipments.store', $shipment->order))->assertSessionHasErrors('order');
        $this->assertSame('uncertain', $shipment->refresh()->creation_status);
        $this->actingAs($this->admin)->post(route('admin.bosta.shipments.store', $shipment->order))->assertSessionHasErrors('order');
        $this->assertSame(1, count(Http::recorded(fn ($r) => $r->method() === 'POST')));
        $this->assertDatabaseCount('bosta_shipments', 1);
    }

    public function test_admin_can_fetch_available_dates_and_save_schedule_but_regular_shipping_staff_cannot_enable_it(): void
    {
        Http::fake(['*/pickups/available-dates*' => Http::response(['data' => ['2026-11-01', 'invalid']])]);
        $this->actingAs($this->admin)->getJson(route('admin.bosta.pickups.dates'))->assertOk()->assertExactJson(['dates' => ['2026-11-01']]);
        $this->actingAs($this->admin)->put(route('admin.bosta.automatic-pickup.update'), ['enabled' => 1, 'contact_name' => 'Synthetic', 'contact_phone' => '01000000000'])->assertSessionHas('success');
        $this->assertTrue(app(BostaAutomaticPickupService::class)->settings()['enabled']);
        $staff = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $staff->permissions()->sync(Permission::where('key', 'bosta.create_pickup')->pluck('id'));
        $this->actingAs($staff)->put(route('admin.bosta.automatic-pickup.update'), ['enabled' => 0, 'contact_name' => 'Synthetic', 'contact_phone' => '01000000000'])->assertForbidden();
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    private function enableAutomatic(): void
    {
        Setting::updateOrCreate(['key' => BostaAutomaticPickupService::SETTING_KEY], ['value' => json_encode(['enabled' => true, 'contact_name' => 'Synthetic', 'contact_phone' => '01000000000', 'authorized_by' => $this->admin->id])]);
    }

    private function release(BostaShipment $shipment, string $action)
    {
        return $this->actingAs($this->admin)->post(route('admin.bosta.shipments.release', $shipment), ['action' => $action, 'reason' => 'Updated customer request']);
    }

    private function pickup(BostaShipment $shipment)
    {
        return $this->actingAs($this->admin)->post(route('admin.bosta.pickups.store'), ['shipments' => [$shipment->id], 'scheduled_date' => '2026-11-01', 'contact_name' => 'Synthetic', 'contact_phone' => '+201000000000']);
    }

    private function pickupResponses(): array
    {
        return ['*/pickups/available-dates*' => Http::response(['data' => ['2026-11-01']]), '*/pickups/creationValidity' => Http::response(['data' => ['valid' => true]]), '*/pickups' => Http::response(['success' => true, 'data' => ['_id' => 'pickup-synthetic', 'state' => 'Requested']])];
    }

    private function responses(BostaShipment $shipment, string $state = 'Pickup requested', int $code = 10): array
    {
        return ['*/deliveries/business/'.$shipment->tracking_number => Http::response(['success' => true, 'data' => ['_id' => $shipment->bosta_delivery_id, 'state' => ['value' => $state, 'code' => $code]]]), '*/terminate' => Http::response(['success' => true]), '*/cities*' => Http::response(['data' => ['list' => [['_id' => 'city-cairo', 'name' => 'Cairo', 'otherName' => 'القاهرة']]]])];
    }

    private function shipment(string $key = 'TEST'): BostaShipment
    {
        $order = Order::create(['order_number' => 'HK-'.$key, 'checkout_group_key' => $key, 'parent_name' => 'Synthetic', 'status' => 'new', 'shipping_status' => 'shipment_created', 'paid_amount_cents' => 5000, 'payment_status' => 'partially_paid', 'delivery_details' => ['phone' => '01000000000', 'governorate' => 'القاهرة', 'city' => 'مدينة نصر', 'street' => 'Synthetic address', 'delivery_fee' => 50]]);
        $order->items()->create(['item_type' => 'product', 'title' => 'Synthetic product', 'quantity' => 1, 'unit_price_cents' => 20000, 'total_price_cents' => 20000]);

        return BostaShipment::create(['checkout_group_key' => $key, 'order_id' => $order->id, 'bosta_delivery_id' => $key === 'TEST' ? 'old-delivery' : $key, 'tracking_number' => $key === 'TEST' ? 'OLD-TRACK' : 'TRACK-'.$key, 'business_reference' => 'HK-'.$key, 'creation_status' => 'created', 'shipping_status' => 'shipment_created', 'business_location_id' => 'location-test']);
    }
}
