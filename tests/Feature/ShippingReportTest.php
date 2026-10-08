<?php

namespace Tests\Feature;

use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\OrderStatusDefinition;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminShippingReportService;
use App\Support\OrderStatusRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShippingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function order(string $key, int $quantity = 2, array $attributes = []): Order
    {
        $order = Order::create($attributes + ['order_number' => 'SHIP-'.fake()->uuid(), 'checkout_group_key' => $key,
            'parent_name' => 'Synthetic Parent', 'child_name' => 'Synthetic Child', 'status' => 'new', 'shipping_status' => 'shipped']);
        $order->items()->create(['item_type' => 'product', 'title' => 'Synthetic Stickers', 'quantity' => $quantity, 'unit_price_cents' => 10000, 'total_price_cents' => $quantity * 10000]);

        return $order;
    }

    private function log(Order $order, string $date = '2026-10-08 09:00:00', string $status = 'shipped'): void
    {
        $order->statusLogs()->create(['status_type' => 'shipping', 'status' => $status, 'created_at' => $date, 'updated_at' => $date]);
    }

    private function report(array $filters = []): array
    {
        return app(AdminShippingReportService::class)->report(Request::create('/admin/shipping-report', 'GET', $filters + ['from' => '2026-10-01', 'to' => '2026-10-08', 'day' => '2026-10-08']));
    }

    private function shipment(Order $order): BostaShipment
    {
        return BostaShipment::create(['checkout_group_key' => $order->checkout_group_key, 'order_id' => $order->id,
            'business_reference' => $order->order_number, 'business_location_id' => 'synthetic', 'tracking_number' => 'TEST-'.$order->id, 'creation_status' => 'created']);
    }

    public function test_daily_quantities_count_checkout_once_and_break_down_mixed_contents(): void
    {
        $first = $this->order('MIXED', 3);
        $second = $this->order('MIXED', 2);
        $story = Story::create(['title' => 'Synthetic Adventure', 'slug' => 'shipping-story', 'price' => 100, 'gender' => 'both', 'language' => 'ar']);
        $second->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => $story->title, 'quantity' => 2, 'unit_price_cents' => 10000, 'total_price_cents' => 20000]);
        $second->items()->create(['item_type' => 'add_on', 'title' => 'Synthetic Gift', 'quantity' => 1, 'unit_price_cents' => 1000, 'total_price_cents' => 1000]);
        $this->log($first);
        $this->log($second);
        $this->log($first, '2026-10-08 10:00:00');
        $report = $this->report();
        $this->assertSame(['shipments' => 1, 'products' => 5, 'stories' => 2, 'add_ons' => 1, 'items' => 8], $report['summary']);
        $this->assertCount(4, $report['rows']->first()['items']);
        $this->assertSame(8, $report['teams']->first()['contents']->sum('quantity'));
        $this->assertSame(8, $report['teams']->first()['items']);
        $this->assertSame(8, $report['daily']->count());
    }

    public function test_provider_dispatch_time_wins_over_local_sync_time_and_creation_is_not_shipping(): void
    {
        $order = $this->order('BOSTA', 4);
        $this->log($order, '2026-10-08 09:00:00');
        $shipment = $this->shipment($order);
        foreach ([[20, '2026-10-06 08:00:00'], [21, '2026-10-07 08:00:00'], [30, '2026-10-08 08:00:00']] as $index => [$state, $date]) {
            $shipment->events()->create(['event_key' => 'synthetic-'.$index, 'state_code' => $state, 'occurred_at' => $date, 'payload' => []]);
        }
        $notShipped = $this->order('ONLY-CREATED', 9, ['shipping_status' => 'shipment_created']);
        $this->shipment($notShipped)->events()->create(['event_key' => 'only-created', 'state_code' => 20, 'occurred_at' => '2026-10-08 08:00:00', 'payload' => []]);
        $report = $this->report(['day' => '2026-10-07']);
        $this->assertSame(1, $report['summary']['shipments']);
        $this->assertSame(4, $report['selected_summary']['products']);
        $this->assertSame('bosta', $report['rows']->first()['date_source']);
        $this->assertSame('2026-10-07 08:00:00', $report['rows']->first()['shipped_at']);
    }

    public function test_cairo_day_boundary_and_returned_shipments_keep_original_dispatch_date(): void
    {
        $order = $this->order('BOUNDARY', 2, ['shipping_status' => 'returned', 'created_at' => '2026-09-01 08:00:00']);
        $this->log($order, '2026-10-07 21:30:00'); // 00:30 Cairo on October 8.
        $this->log($order, '2026-10-08 10:00:00', 'returned');
        $report = $this->report();
        $this->assertSame(1, $report['selected_summary']['shipments']);
        $this->assertSame('2026-10-08', $report['rows']->first()['day']);
    }

    public function test_custom_shipping_status_and_legacy_story_fallback_are_supported(): void
    {
        OrderStatusDefinition::create(['type' => 'shipping', 'key' => 'custom_sent', 'label_ar' => 'مرسل', 'behavior' => 'shipped', 'is_active' => true, 'sort_order' => 90]);
        OrderStatusRegistry::clearCache();
        $story = Story::create(['title' => 'Legacy Story', 'slug' => 'shipping-legacy', 'price' => 100]);
        $order = $this->order('LEGACY', 1, ['story_id' => $story->id, 'shipping_status' => 'custom_sent']);
        $order->items()->delete();
        $this->log($order, status: 'custom_sent');
        $report = $this->report();
        $this->assertSame(1, $report['summary']['stories']);
        $this->assertSame(0, $report['summary']['products']);
        $this->assertSame('Legacy Story', $report['rows']->first()['items']->first()->title);
    }

    public function test_each_team_member_has_their_own_products_and_unassigned_orders_are_visible(): void
    {
        foreach ([['A', 2, 'Synthetic Employee A'], ['B', 5, 'Synthetic Employee B'], ['C', 3, null]] as [$key, $quantity, $name]) {
            $order = $this->order($key, $quantity);
            $this->log($order);
            if ($name) {
                $employee = User::factory()->create(['role' => 'admin', 'name' => $name]);
                OrderGroupAssignment::create(['checkout_group_key' => $key, 'assigned_to_user_id' => $employee->id, 'assigned_by_user_id' => $this->admin->id, 'assigned_at' => now()]);
            }
        }
        $teams = $this->report()['teams']->keyBy('name');
        $this->assertSame(2, $teams['Synthetic Employee A']['products']);
        $this->assertSame(5, $teams['Synthetic Employee B']['contents']->first()['quantity']);
        $this->assertSame(3, $teams['غير مسند']['products']);
    }

    public function test_missing_dispatch_dates_are_reported_without_guessing_delivered_or_updated_dates(): void
    {
        $order = $this->order('UNDATED', 6, ['shipping_status' => 'delivered']);
        $this->log($order, status: 'delivered');
        $this->shipment($order)->events()->create(['event_key' => 'delivered-only', 'state_code' => 45, 'occurred_at' => now(), 'payload' => []]);
        $report = $this->report();
        $this->assertSame(1, $report['undated']);
        $this->assertSame(0, $report['summary']['items']);
    }

    public function test_edit_replacement_preserves_historical_shipping_evidence_but_not_removed_items(): void
    {
        $old = $this->order('REPLACED', 99);
        $this->log($old);
        $old->delete();
        $new = $this->order('REPLACED', 3);
        $report = $this->report();
        $this->assertSame(3, $report['summary']['products']);
        $this->assertSame($new->id, $report['rows']->first()['representative_id']);
    }

    public function test_shipment_details_are_paginated_and_lookup_does_not_mutate_orders_or_assignments(): void
    {
        for ($i = 0; $i < 27; $i++) {
            $this->log($this->order('PAGE-'.$i));
        }
        $before = DB::table('orders')->get()->toJson();
        $report = $this->report(['page' => 2]);
        $this->assertSame(27, $report['rows']->total());
        $this->assertCount(2, $report['rows']);
        $this->assertSame(54, $report['selected_summary']['products']);
        $this->assertSame($before, DB::table('orders')->get()->toJson());
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_route_requires_shipping_report_permission_and_does_not_expose_customer_private_fields(): void
    {
        $this->log($this->order('SAFE', attributes: ['notes' => 'synthetic-private-note', 'delivery_details' => ['phone' => '01000000000', 'address_details' => 'synthetic-private-address']]));
        $this->get('/admin/shipping-report')->assertOk()->assertSee('تقرير الشحن')->assertSee('Synthetic Stickers')
            ->assertSee('1 شحنة · 2 قطعة / نسخة')->assertDontSee('synthetic-private-note')->assertDontSee('synthetic-private-address')->assertDontSee('01000000000');
        $this->admin->permissions()->detach();
        $this->admin->unsetRelation('permissions');
        $this->get('/admin/shipping-report')->assertForbidden();
    }

    public function test_invalid_dates_and_overlong_period_are_rejected(): void
    {
        $this->get('/admin/shipping-report?from=not-a-date')->assertSessionHasErrors('from');
        $this->get('/admin/shipping-report?from=2024-01-01&to=2026-10-08')->assertStatus(422);
        $this->get('/admin/shipping-report?from=2026-10-01&to=2026-10-08&day=2026-09-30')->assertStatus(422);
    }
}
