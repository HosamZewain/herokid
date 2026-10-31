<?php

namespace Tests\Feature;

use App\Models\BostaShipment;
use App\Models\Order;
use App\Models\Permission;
use App\Models\User;
use App\Services\Orders\DashboardMonthComparison;
use App\Support\OrderDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardMonthComparisonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00', 'Africa/Cairo')->utc());
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function order(string $key, string $date, int $stories = 1, int $products = 0, array $extra = []): Order
    {
        $o = Order::create($extra + ['order_number' => 'MOM-'.fake()->uuid(), 'checkout_group_key' => $key, 'status' => 'new',
            'created_at' => $date, 'delivery_details' => ['delivery_fee' => 50], 'uploaded_photos' => []]);
        foreach (['story' => $stories, 'product' => $products] as $type => $quantity) {
            if ($quantity) {
                $o->items()->create(['item_type' => $type, 'title' => 'Synthetic', 'quantity' => $quantity, 'unit_price_cents' => 10000, 'total_price_cents' => $quantity * 10000]);
            }
        }

        return $o;
    }

    private function summary(): array
    {
        return app(DashboardMonthComparison::class)->summarize(CarbonImmutable::now());
    }

    private function dispatch(Order $o, string $date): void
    {
        $o->statusLogs()->create(['status_type' => 'shipping', 'status' => 'shipped', 'created_at' => $date, 'updated_at' => $date]);
    }

    public function test_six_calendar_months_zero_fill_and_first_purchase_grouping_with_net_average(): void
    {
        $this->order('mixed', '2026-09-01 10:00:00', 3, 4, ['discount_cents' => 5000]);
        $this->order('mixed', '2026-10-02 10:00:00', 2, 1, ['discount_cents' => 5000]);
        $this->order('new', '2026-10-02 10:00:00', 1, 2);
        $this->order('old', '2026-04-01 10:00:00', 10);
        $this->order('future', '2026-11-01 10:00:00', 10);
        $s = $this->summary();
        $this->assertSame(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], array_column($s['months'], 'key'));
        $september = $s['months'][4];
        $this->assertSame(1, $september['checkouts']);
        $this->assertSame(5, $september['story_pieces']);
        $this->assertSame(5, $september['product_pieces']);
        $this->assertSame(95000, $september['average_order_cents']);
        $this->assertSame(30000, $s['months'][5]['average_order_cents']);
        $this->assertSame(0, $s['months'][0]['checkouts']);
        $this->assertNull($s['months'][0]['average_order_cents']);
        $this->assertTrue($s['months'][5]['partial']);
    }

    public function test_shipping_uses_dispatch_month_not_purchase_and_deduplicates_delivered_and_mixed_parts(): void
    {
        $a = $this->order('old-group', '2026-03-01 12:00:00', 1, 0, ['shipping_status' => 'shipped']);
        $b = $this->order('old-group', '2026-03-01 12:00:00', 0, 2, ['shipping_status' => 'delivered']);
        $this->dispatch($a, '2026-10-02 12:00:00');
        $this->dispatch($b, '2026-10-03 12:00:00');
        $this->order('undated', '2026-03-01 12:00:00', 1, 0, ['shipping_status' => 'shipped']);
        $returned = $this->order('returned', '2026-03-01 12:00:00', 1, 0, ['shipping_status' => 'returned']);
        $this->dispatch($returned, '2026-10-04 12:00:00');
        $s = $this->summary();
        $this->assertSame(0, $s['months'][5]['checkouts']);
        $this->assertSame(1, $s['months'][5]['shipped_checkouts']);
        $this->assertSame(1, $s['undated_shipments']);
        $this->assertSame(0, $s['months'][4]['shipped_checkouts']);
    }

    public function test_bosta_evidence_precedes_local_log_and_cairo_boundary_is_respected(): void
    {
        $o = $this->order('bosta', '2026-03-01 12:00:00', 1, 0, ['shipping_status' => 'delivered']);
        $this->dispatch($o, '2026-10-02 12:00:00');
        $s = BostaShipment::create(['checkout_group_key' => $o->checkout_group_key, 'order_id' => $o->id,
            'business_reference' => $o->order_number, 'business_location_id' => 'synthetic', 'tracking_number' => 'SYNTHETIC', 'creation_status' => 'created']);
        $boundary = OrderDateTime::utcStartOfDay('2026-10-01');
        $s->events()->create(['event_key' => 'synthetic-boundary', 'state_code' => 21, 'occurred_at' => $boundary->subSecond(), 'payload' => []]);
        $summary = $this->summary();
        $this->assertSame(1, $summary['months'][4]['shipped_checkouts']);
        $this->assertSame(0, $summary['months'][5]['shipped_checkouts']);
        $this->order('cairo-before', $boundary->subSecond()->format('Y-m-d H:i:s'));
        $this->order('cairo-at', $boundary->format('Y-m-d H:i:s'));
        $summary = $this->summary();
        $this->assertSame(1, $summary['months'][4]['checkouts']);
        $this->assertSame(1, $summary['months'][5]['checkouts']);
    }

    public function test_cancelled_and_deleted_history_matches_intake_but_merged_copies_are_not_double_counted(): void
    {
        $this->order('cancelled', '2026-10-02 12:00:00', 1, 0, ['status' => 'cancelled']);
        $this->order('deleted', '2026-10-02 12:00:00', 0, 2)->delete();
        $this->order('merged', '2026-10-02 12:00:00', 0, 8)->delete();
        DB::table('order_group_merge_aliases')->insert(['source_checkout_group_key' => 'merged', 'target_checkout_group_key' => 'deleted', 'reason' => 'Synthetic', 'merged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $m = $this->summary()['months'][5];
        $this->assertSame(2, $m['checkouts']);
        $this->assertSame(1, $m['story_pieces']);
        $this->assertSame(2, $m['product_pieces']);
        $this->assertSame(15000, $m['average_order_cents']);
    }

    public function test_comparison_is_not_computed_or_exposed_without_statistics_permission_and_section_order_is_preserved(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('admin.dashboard.index'))->assertOk()->assertSee('Month Over Month')->getContent();
        $this->assertLessThan(strpos($html, 'Month Over Month'), strpos($html, 'حالات التشغيل الحالية لكل الفترات'));
        $this->assertLessThan(strpos($html, 'management-notes'), strpos($html, 'تفاصيل حركات الدفع اليوم'));
        $admin->permissions()->detach(Permission::where('key', 'dashboard.statistics.view')->value('id'));
        $admin->unsetRelation('permissions');
        $this->mock(DashboardMonthComparison::class)->shouldNotReceive('summarize');
        $this->actingAs($admin)->get(route('admin.dashboard.index'))->assertOk()->assertDontSee('Month Over Month')->assertViewHas('monthComparison', null);
    }
}
