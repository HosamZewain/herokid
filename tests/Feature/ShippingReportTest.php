<?php

namespace Tests\Feature;

use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\OrderStatusDefinition;
use App\Models\Permission;
use App\Models\Product;
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

    public function test_cairo_repeated_hour_is_included_once_in_shipping_day(): void
    {
        $this->log($this->order('DST-FIRST', 1), '2026-10-29 20:30:00');
        $this->log($this->order('DST-SECOND', 1), '2026-10-29 21:30:00');
        $this->log($this->order('DST-NEXT-DAY', 1), '2026-10-29 22:00:00');
        $report = $this->report(['from' => '2026-10-29', 'to' => '2026-10-29', 'day' => '2026-10-29']);
        $this->assertSame(2, $report['summary']['shipments']);
        $this->assertSame(20000, $report['summary']['items_net_cents']);
        $this->assertSame(2, $report['daily']->first()['shipments']);
        $this->assertSame(2, $report['rows']->total());
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
        $this->assertSame(['shipments' => 1, 'delivered' => 0, 'in_transit' => 1, 'products' => 5, 'stories' => 2, 'add_ons' => 1, 'items' => 8, 'total_cents' => 71000, 'items_net_cents' => 71000, 'products_net_cents' => 50000, 'stories_net_cents' => 20000, 'add_ons_net_cents' => 1000, 'unclassified_net_cents' => 0, 'paid_cents' => 0, 'remaining_cents' => 71000], $report['summary']);
        $this->assertCount(4, $report['rows']->first()['items']);
        $this->assertSame(8, $report['teams']->first()['contents']->sum('quantity'));
        $this->assertSame(8, $report['teams']->first()['items']);
        $this->assertSame(8, $report['daily']->count());
    }

    public function test_employee_daily_net_values_reconcile_mixed_categories_and_replicated_discount(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $first = $this->order('NET-MIXED', 2, ['discount_cents' => 6000, 'delivery_details' => ['delivery_fee' => 90]]);
        $second = $this->order('NET-MIXED', 1, ['discount_cents' => 6000, 'delivery_details' => ['delivery_fee' => 90]]);
        $second->items()->delete();
        $second->items()->create(['item_type' => 'story', 'title' => 'Synthetic Story', 'quantity' => 3, 'unit_price_cents' => 10000, 'total_price_cents' => 30000]);
        $second->items()->create(['item_type' => 'add_on', 'title' => 'Synthetic Extra', 'quantity' => 2, 'unit_price_cents' => 5000, 'total_price_cents' => 10000]);
        $this->log($first, '2026-10-02 10:00:00');
        $this->log($second, '2026-10-02 10:00:00');
        $later = $this->order('NET-LATER', 1, ['discount_cents' => 1000, 'delivery_details' => ['delivery_fee' => 70]]);
        $this->log($later);
        foreach (['NET-MIXED', 'NET-LATER'] as $key) {
            OrderGroupAssignment::create(['checkout_group_key' => $key, 'assigned_to_user_id' => $employee->id, 'assigned_at' => now()]);
        }
        $this->log($this->order('OTHER-EMPLOYEE', 20));
        $filters = ['employee' => (string) $employee->id, 'day' => '2026-10-02'];
        $report = $this->report($filters);
        $this->assertSame(63000, $report['summary']['items_net_cents']);
        $this->assertSame(2, $report['summary']['shipments']);
        $this->assertSame(54000, $report['selected_summary']['items_net_cents']);
        $daily = $report['daily']->keyBy('day');
        $this->assertCount(8, $daily);
        $this->assertSame(1, $daily['2026-10-02']['shipments']);
        $this->assertSame(3, $daily['2026-10-02']['stories']);
        $this->assertSame(2, $daily['2026-10-02']['products']);
        $this->assertSame(27000, $daily['2026-10-02']['stories_net_cents']);
        $this->assertSame(18000, $daily['2026-10-02']['products_net_cents']);
        $this->assertSame(9000, $daily['2026-10-02']['add_ons_net_cents']);
        $this->assertSame(9000, $daily['2026-10-08']['items_net_cents']);
        $this->assertSame(0, $daily['2026-10-03']['items_net_cents']);
        $this->assertSame(0, $daily['2026-10-03']['shipments']);
        $this->assertSame($report['summary']['items_net_cents'], $daily->sum('items_net_cents'));
        $row = $report['rows']->first();
        $this->assertSame(6000, $row['discount_cents']);
        $this->assertSame(54000, $row['items_net_cents']);
        $this->assertSame(63000, $row['total_cents']); // Invoice balance still includes shipping.
        $this->get(route('admin.shipping-report.index', $filters + ['from' => '2026-10-01', 'to' => '2026-10-08']))
            ->assertOk()->assertSee('الشحن يوم بيوم')->assertSee('بدون الشحن وبعد الخصومات الفعلية')
            ->assertSee('إجمالي الفترة')->assertDontSee('شاملة الشحن');
    }

    public function test_category_allocation_reconciles_rounding_free_items_and_full_discounts(): void
    {
        foreach ([0, 1, 2, 3, 10] as $discount) {
            $order = $this->order('ROUND-'.$discount, 1, ['discount_cents' => $discount]);
            $order->items()->update(['unit_price_cents' => 1, 'total_price_cents' => 1]);
            foreach (['story', 'add_on'] as $type) {
                $order->items()->create(['item_type' => $type, 'title' => 'Synthetic Cent', 'quantity' => 1, 'unit_price_cents' => 1, 'total_price_cents' => 1]);
            }
            $this->log($order);
        }
        $free = $this->order('FREE', 1);
        $free->items()->update(['unit_price_cents' => 0, 'total_price_cents' => 0]);
        $this->log($free);
        $rows = $this->report()['rows']->keyBy('key');
        foreach ($rows as $row) {
            $this->assertSame($row['items_net_cents'], $row['stories_net_cents'] + $row['products_net_cents'] + $row['add_ons_net_cents'] + $row['unclassified_net_cents']);
        }
        $this->assertSame(1, $rows['ROUND-1']['stories_net_cents']);
        $this->assertSame(1, $rows['ROUND-1']['products_net_cents']);
        $this->assertSame(0, $rows['ROUND-1']['add_ons_net_cents']);
        $this->assertSame(0, $rows['ROUND-10']['items_net_cents']);
        $this->assertSame(0, $rows['FREE']['items_net_cents']);
    }

    public function test_legacy_values_use_recorded_story_price_and_preserve_unclassified_value(): void
    {
        $story = Story::create(['title' => 'Synthetic Legacy', 'slug' => 'net-legacy', 'price' => 999]);
        $order = $this->order('LEGACY-NET', 1, ['story_id' => $story->id, 'delivery_details' => ['item_price' => 200, 'delivery_fee' => 60], 'discount_cents' => 2500]);
        $order->items()->delete();
        $this->log($order);
        $unknown = $this->order('UNKNOWN-NET', 1, ['delivery_details' => ['item_price' => 50], 'discount_cents' => 1000]);
        $unknown->items()->delete();
        $this->log($unknown);
        $report = $this->report();
        $rows = $report['rows']->keyBy('key');
        $this->assertSame(21500, $report['sku_breakdown']->sum('net_cents'));
        $this->assertSame(17500, $rows['LEGACY-NET']['stories_net_cents']);
        $this->assertSame(4000, $rows['UNKNOWN-NET']['unclassified_net_cents']);
        $this->assertSame(0, $rows['UNKNOWN-NET']['products_net_cents']);
    }

    public function test_sku_breakdown_groups_the_full_employee_selection_and_keeps_variants_separate(): void
    {
        $employee = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['name_ar' => 'Synthetic Puzzle', 'slug' => 'sku-puzzle', 'price_cents' => 10000]);
        $small = $product->variants()->create(['name_ar' => 'Small', 'sku' => 'PUZ-S']);
        $large = $product->variants()->create(['name_ar' => 'Large', 'sku' => 'PUZ-L']);
        for ($i = 0; $i < 26; $i++) {
            $order = $this->order('SKU-'.$i, 2, ['discount_cents' => 2000, 'delivery_details' => ['delivery_fee' => 70]]);
            $order->items()->update(['product_id' => $product->id, 'product_variant_id' => $small->id, 'sku' => 'PUZ-S', 'title' => $i % 2 ? 'Renamed Puzzle' : 'Synthetic Puzzle']);
            $this->log($order, $i === 0 ? '2026-10-02 10:00:00' : '2026-10-08 10:00:00');
            OrderGroupAssignment::create(['checkout_group_key' => $order->checkout_group_key, 'assigned_to_user_id' => $employee->id, 'assigned_at' => now()]);
        }
        $other = $this->order('OTHER-SKU', 5);
        $other->items()->update(['product_id' => $product->id, 'product_variant_id' => $small->id, 'sku' => 'PUZ-S']);
        $this->log($other);
        $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'product_variant_id' => $large->id,
            'sku' => 'PUZ-L', 'title' => 'Synthetic Puzzle', 'variant_snapshot' => ['id' => $large->id, 'name_ar' => 'Large'],
            'quantity' => 1, 'unit_price_cents' => 20000, 'total_price_cents' => 20000]);
        $filters = ['employee' => (string) $employee->id, 'day' => null, 'page' => 2];
        $report = $this->report($filters);
        $this->assertCount(1, $report['rows']);
        $breakdown = $report['sku_breakdown']->keyBy('sku');
        $this->assertCount(2, $breakdown);
        $this->assertSame(52, $breakdown['PUZ-S']['quantity']);
        $this->assertSame(469000, $breakdown['PUZ-S']['net_cents']);
        $this->assertSame(19000, $breakdown['PUZ-L']['net_cents']);
        $this->assertSame('Synthetic Puzzle — Large', $breakdown['PUZ-L']['title']);
        $this->assertSame($report['selected_summary']['items_net_cents'], $breakdown->sum('net_cents'));
        $day = $this->report(['employee' => (string) $employee->id, 'day' => '2026-10-02']);
        $this->assertSame(2, $day['sku_breakdown']->sum('quantity'));
        $this->assertSame(18000, $day['sku_breakdown']->sum('net_cents'));
        $response = $this->get(route('admin.shipping-report.index', ['employee' => $employee->id]));
        $response->assertOk()->assertSee('عرض تفاصيل الأوردرات')->assertSee('تجميع المنتجات والقصص');
        $this->assertMatchesRegularExpression('/<details id="shipment-details"\s+class=/', $response->getContent());
    }

    public function test_sku_breakdown_preserves_deleted_variant_snapshots_and_exact_cent_totals(): void
    {
        $order = $this->order('SNAPSHOT-SKU', 1, ['discount_cents' => 1]);
        $order->items()->delete();
        foreach ([['S', 'Small', 1], ['S', 'Small', 1], ['L', 'Large', 1], [null, 'Legacy Large', 0]] as [$sku, $variant, $price]) {
            $order->items()->create(['item_type' => 'product', 'title' => 'Archived Puzzle', 'sku' => 'PARENT',
                'variant_snapshot' => ['sku' => $sku, 'name_ar' => $variant], 'quantity' => 1,
                'unit_price_cents' => $price, 'total_price_cents' => $price]);
        }
        $story = Story::create(['title' => 'Synthetic Salah', 'slug' => 'sku-salah', 'price' => 100]);
        $order->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => $story->title,
            'quantity' => 3, 'unit_price_cents' => 1, 'total_price_cents' => 3]);
        $this->log($order);
        $report = $this->report();
        $this->assertCount(4, $report['sku_breakdown']);
        $this->assertSame(2, $report['sku_breakdown']->firstWhere('sku', 'S')['quantity']);
        $this->assertSame(3, $report['sku_breakdown']->firstWhere('title', 'Synthetic Salah')['quantity']);
        $this->assertSame(5, $report['sku_breakdown']->sum('net_cents'));
        $this->assertSame($report['summary']['stories_net_cents'], $report['sku_breakdown']->firstWhere('title', 'Synthetic Salah')['net_cents']);
        $this->assertSame($report['summary']['products_net_cents'], $report['sku_breakdown']->whereNotNull('sku')->sum('net_cents'));
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

    public function test_cairo_day_boundary_uses_shipping_date_and_excludes_returned_shipments(): void
    {
        $order = $this->order('BOUNDARY', 2, ['shipping_status' => 'delivered', 'created_at' => '2026-09-01 08:00:00']);
        $this->log($order, '2026-10-07 21:30:00'); // 00:30 Cairo on October 8.
        $this->log($order, '2026-10-08 10:00:00', 'delivered');
        $returned = $this->order('RETURNED', 9, ['shipping_status' => 'returned']);
        $this->log($returned);
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

    public function test_empty_legacy_checkout_keys_remain_separate_with_strict_grouping(): void
    {
        $first = $this->order('LEGACY-A', 2);
        $second = $this->order('LEGACY-B', 3);
        DB::table('orders')->whereIn('id', [$first->id, $second->id])->update(['checkout_group_key' => '']);
        $this->log($first);
        $this->log($second);
        $this->log($first, '2026-10-08 10:00:00');

        $report = $this->report();
        $this->assertSame(2, $report['summary']['shipments']);
        $this->assertSame(5, $report['summary']['products']);
        $this->assertSame(['order:'.$first->id, 'order:'.$second->id], $report['rows']->getCollection()->pluck('key')->sort()->values()->all());
        $this->assertSame([2, 3], $report['rows']->getCollection()->pluck('product_quantity')->map(fn ($value) => (int) $value)->sort()->values()->all());
    }

    public function test_employee_summary_covers_the_period_and_click_filters_all_their_orders(): void
    {
        $employee = User::factory()->create(['role' => 'admin', 'name' => 'Synthetic Period Employee']);
        foreach ([['PERIOD-A', '2026-10-02 10:00:00', 2], ['PERIOD-B', '2026-10-08 10:00:00', 3]] as [$key, $date, $qty]) {
            $this->log($this->order($key, $qty), $date);
            OrderGroupAssignment::create(['checkout_group_key' => $key, 'assigned_to_user_id' => $employee->id, 'assigned_at' => now()]);
        }
        $this->log($this->order('UNASSIGNED', 4));
        $filters = ['from' => '2026-10-01', 'to' => '2026-10-08', 'employee' => (string) $employee->id];
        $report = $this->report($filters + ['day' => null]);
        $this->assertNull($report['day']);
        $this->assertSame(2, $report['summary']['shipments']);
        $this->assertSame(2, $report['selected_summary']['shipments']);
        $this->assertSame(50000, $report['selected_summary']['total_cents']);
        $this->assertEqualsCanonicalizing(['PERIOD-A', 'PERIOD-B'], $report['rows']->pluck('key')->all());
        $this->assertSame(2, $report['teams']->firstWhere('id', (string) $employee->id)['shipments']);
        $this->get(route('admin.shipping-report.index', $filters))->assertOk()
            ->assertViewHas('report', fn ($data) => $data['rows']->pluck('key')->sort()->values()->all() === ['PERIOD-A', 'PERIOD-B'])
            ->assertSee(route('admin.shipping-report.index', $filters).'#sku-breakdown');
        $day = $this->report($filters);
        $this->assertSame(1, $day['selected_summary']['shipments']);
        $this->assertSame(2, $day['teams']->firstWhere('id', (string) $employee->id)['shipments']);
        $unassigned = $this->report(['employee' => 'unassigned', 'day' => null]);
        $this->assertSame(['UNASSIGNED'], $unassigned['rows']->pluck('key')->all());
    }

    public function test_daily_team_breakdown_reconciles_checkouts_and_day_drilldown_preserves_period_totals(): void
    {
        $alice = User::factory()->create(['role' => 'admin', 'name' => 'Synthetic Alice']);
        $bob = User::factory()->create(['role' => 'admin', 'name' => 'Synthetic Bob']);
        foreach ([['TEAM-A', '2026-10-02 10:00:00', $alice], ['TEAM-B', '2026-10-08 10:00:00', $alice], ['TEAM-C', '2026-10-08 10:00:00', $bob]] as [$key, $date, $employee]) {
            $this->log($this->order($key), $date);
            OrderGroupAssignment::create(['checkout_group_key' => $key, 'assigned_to_user_id' => $employee->id, 'assigned_at' => now()]);
        }
        // Another child in the same checkout is not a second shipment.
        $this->log($this->order('TEAM-B', 3, ['shipping_status' => 'delivered']));
        $this->log($this->order('TEAM-UNASSIGNED'));
        $report = $this->report();
        $daily = $report['daily']->keyBy('day');
        $this->assertSame(4, $report['summary']['shipments']);
        $this->assertSame(3, $report['selected_summary']['shipments']);
        $this->assertSame(3, $report['day_teams']->sum('shipments'));
        $this->assertSame(2, $report['teams']->firstWhere('id', (string) $alice->id)['shipments']);
        $this->assertSame(1, $report['day_teams']->firstWhere('id', (string) $alice->id)['shipments']);
        $this->assertSame(1, $report['day_teams']->firstWhere('id', 'unassigned')['shipments']);
        $this->assertSame(0, $daily['2026-10-03']['teams']->count());
        foreach ($daily as $row) {
            $this->assertSame($row['shipments'], $row['teams']->sum('shipments'));
            $this->assertSame($row['items_net_cents'], $row['teams']->sum('items_net_cents'));
        }
        $this->assertSame($report['selected_summary']['items_net_cents'], $report['day_teams']->sum('items_net_cents'));
        $filtered = $this->report(['employee' => (string) $alice->id]);
        $this->assertSame([(string) $alice->id], $filtered['day_teams']->pluck('id')->all());
        $this->assertSame(1, $filtered['daily']->firstWhere('day', '2026-10-08')['shipments']);
        $this->assertSame([], $this->report(['day' => '2026-10-03'])['day_teams']->all());
        $this->assertSame([], $this->report(['day' => null])['day_teams']->all());
        $link = route('admin.shipping-report.index', ['from' => '2026-10-01', 'to' => '2026-10-08', 'day' => '2026-10-08', 'employee' => $alice->id]);
        $response = $this->get('/admin/shipping-report?from=2026-10-01&to=2026-10-08&day=2026-10-08');
        $response->assertOk()->assertSee('مسؤولية التيم — 2026-10-08')->assertSee('إجمالي اليوم')
            ->assertSee($link.'#shipping-day-team')->assertSee($link.'#sku-breakdown')
            ->assertSee('مقارنة كل الموظفين خلال الفترة')->assertSee('تجميع المنتجات والقصص')->assertSee('عرض تفاصيل الأوردرات');
        $this->get($link)->assertOk()->assertViewHas('report', fn ($data) => $data['rows']->total() === 1 && $data['rows']->first()['key'] === 'TEAM-B');
        $this->get('/admin/shipping-report?from=2026-10-01&to=2026-10-08&day=2026-10-03')->assertOk()->assertSee('لا توجد شحنات للموظف واليوم المختارين.');

        // Reassignment affects daily attribution too, without altering dispatch evidence.
        OrderGroupAssignment::where('checkout_group_key', 'TEAM-B')->update(['assigned_to_user_id' => $bob->id]);
        $reassigned = $this->report();
        $this->assertNull($reassigned['day_teams']->firstWhere('id', (string) $alice->id));
        $this->assertSame(2, $reassigned['day_teams']->firstWhere('id', (string) $bob->id)['shipments']);
        $this->assertSame(3, $reassigned['daily']->firstWhere('day', '2026-10-08')['teams']->sum('shipments'));
    }

    public function test_current_employee_gets_credit_after_reassignment_even_when_shipping_was_earlier(): void
    {
        $first = User::factory()->create(['role' => 'admin']);
        $current = User::factory()->create(['role' => 'admin']);
        $this->log($this->order('REASSIGNED'), '2026-10-02 10:00:00');
        $assignment = OrderGroupAssignment::create(['checkout_group_key' => 'REASSIGNED', 'assigned_to_user_id' => $first->id, 'assigned_at' => '2026-10-01 08:00:00']);
        $this->assertSame((string) $first->id, $this->report(['day' => null])['teams']->first()['id']);
        $assignment->update(['assigned_to_user_id' => $current->id, 'assigned_at' => now()]);
        $report = $this->report(['day' => null]);
        $this->assertSame((string) $current->id, $report['teams']->first()['id']);
        $this->assertSame(0, $this->report(['day' => null, 'employee' => (string) $first->id])['rows']->total());
        $this->assertSame(1, $this->report(['day' => null, 'employee' => (string) $current->id])['rows']->total());
    }

    public function test_delivery_does_not_create_another_shipping_credit_and_partial_groups_are_excluded(): void
    {
        $order = $this->order('DELIVERED', attributes: ['shipping_status' => 'delivered']);
        $this->log($order, '2026-10-02 08:00:00');
        $this->log($order, '2026-10-08 08:00:00', 'delivered');
        $this->log($this->order('PARTIAL', 3));
        $this->order('PARTIAL', 7, ['shipping_status' => 'not_ready']);
        $this->log($this->order('MIXED-QUALIFIED', 2));
        $this->order('MIXED-QUALIFIED', 1, ['shipping_status' => 'delivered']);
        foreach (['returned', 'cancelled', 'shipment_created', 'not_ready'] as $status) {
            $this->log($this->order('EXCLUDED-'.$status, 9, ['shipping_status' => $status]));
        }
        $report = $this->report(['day' => null]);
        $this->assertSame(2, $report['summary']['shipments']);
        $this->assertSame(5, $report['summary']['products']);
        $this->assertSame('mixed', $report['rows']->firstWhere('key', 'MIXED-QUALIFIED')['current_shipping_status']);
        $this->assertSame(['MIXED-QUALIFIED'], $this->report(['from' => '2026-10-08'])['rows']->pluck('key')->all());
    }

    public function test_checkout_values_count_shipping_discount_and_paid_balance_once(): void
    {
        $first = $this->order('MONEY', 2, ['delivery_details' => ['delivery_fee' => 60], 'discount_cents' => 2500, 'paid_amount_cents' => 10000]);
        $second = $this->order('MONEY', 3, ['delivery_details' => ['delivery_fee' => 60], 'discount_cents' => 2500, 'paid_amount_cents' => 10000]);
        $this->log($first);
        $this->log($second);
        $row = $this->report()['rows']->first();
        $this->assertSame(50000, $row['items_cents']);
        $this->assertSame(6000, $row['delivery_cents']);
        $this->assertSame(2500, $row['discount_cents']);
        $this->assertSame(47500, $row['items_net_cents']);
        $this->assertSame(53500, $row['total_cents']);
        $this->assertSame(10000, $row['paid_cents']);
        $this->assertSame(43500, $row['remaining_cents']);
        $this->get('/admin/shipping-report')->assertOk()->assertSee('قيمة الأوردرات خلال الفترة')->assertDontSee('ج.م ج.م');
        $first->update(['paid_amount_cents' => 60000]);
        $this->assertSame(0, $this->report()['selected_summary']['remaining_cents']);
    }

    public function test_legacy_empty_keys_use_separate_balances_and_saved_story_price(): void
    {
        $story = Story::create(['title' => 'Legacy Price', 'slug' => 'legacy-price', 'price' => 100]);
        $one = $this->order('', 2, ['delivery_details' => ['delivery_fee' => 12.34], 'discount_cents' => 1234, 'paid_amount_cents' => 10000]);
        $two = $this->order('', 1, ['story_id' => $story->id, 'delivery_details' => ['item_price' => 150.25, 'delivery_fee' => 20.55]]);
        $two->items()->delete();
        DB::table('orders')->whereIn('id', [$one->id, $two->id])->update(['checkout_group_key' => '']);
        $this->log($one);
        $this->log($two);
        $rows = $this->report()['rows']->keyBy('representative_id');
        $this->assertSame(20000, $rows[$one->id]['total_cents']);
        $this->assertSame(10000, $rows[$one->id]['remaining_cents']);
        $this->assertSame(17080, $rows[$two->id]['total_cents']);
        $this->assertSame(15025, $rows[$two->id]['items_cents']);
    }

    public function test_merged_source_never_creates_duplicate_kpi_or_financial_balance(): void
    {
        $this->log($this->order('MERGED-SOURCE', 8));
        $this->log($this->order('MERGED-TARGET', 3));
        DB::table('order_group_merge_aliases')->insert(['source_checkout_group_key' => 'MERGED-SOURCE', 'target_checkout_group_key' => 'MERGED-TARGET', 'reason' => 'Synthetic merge', 'merged_at' => now()]);
        $report = $this->report();
        $this->assertSame(['MERGED-TARGET'], $report['rows']->pluck('key')->all());
        $this->assertSame(30000, $report['summary']['total_cents']);
    }

    public function test_shipping_only_permission_does_not_grant_financial_access(): void
    {
        $this->log($this->order('LIMITED', 2));
        $this->admin->permissions()->sync(Permission::where('key', 'shipping_reports.view')->pluck('id'));
        $this->admin->unsetRelation('permissions');
        $report = $this->report();
        $this->assertFalse($report['canViewValues']);
        $this->assertArrayNotHasKey('total_cents', $report['rows']->first());
        $this->assertArrayNotHasKey('net_cents', $report['sku_breakdown']->first());
        $this->get('/admin/shipping-report')->assertOk()->assertSee('Synthetic Stickers')
            ->assertDontSee('قيمة الأوردرات خلال الفترة')->assertDontSee('مدفوع:')->assertDontSee('ج.م');
        $this->get('/admin/shipping-report?from=2026-10-01&to=2026-10-08&day=2026-10-08')->assertOk()
            ->assertSee('مسؤولية التيم — 2026-10-08')->assertSee('شحنات اليوم')
            ->assertDontSee('صافي أوردرات اليوم')->assertDontSee('ج.م');
    }

    public function test_employee_filter_is_validated_and_pagination_keeps_period_and_employee(): void
    {
        foreach (['-1', '0', 'unknown', '1 OR 1=1'] as $value) {
            $this->get('/admin/shipping-report?employee='.urlencode($value))->assertSessionHasErrors('employee');
        }
        $this->get('/admin/shipping-report?employee[]=1')->assertSessionHasErrors('employee');
        for ($i = 0; $i < 26; $i++) {
            $this->log($this->order('FILTERED-'.$i, 1));
        }
        $report = $this->report(['employee' => 'unassigned', 'day' => null, 'page' => 2]);
        $this->assertCount(1, $report['rows']);
        $this->assertSame(26, $report['selected_summary']['shipments']);
        $this->assertStringContainsString('employee=unassigned', $report['rows']->url(1));
        $this->assertStringContainsString('from=2026-10-01', $report['rows']->url(1));
        $this->assertStringContainsString('to=2026-10-08', $report['rows']->url(1));
    }
}
