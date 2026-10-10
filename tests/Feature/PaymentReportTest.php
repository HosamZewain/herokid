<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Payments\PaymentCollectionReportService;
use App\Services\Sales\AdvertisingTargetingReportService;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 17:00:00', 'Africa/Cairo')->utc());
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function order(string $key, array $types = ['story'], array $attributes = []): Order
    {
        $order = Order::create($attributes + ['order_number' => 'PAY-'.Str::uuid(), 'checkout_group_key' => $key,
            'parent_name' => 'Synthetic Customer', 'status' => 'new', 'paid_amount_cents' => 0,
            'delivery_details' => ['delivery_fee' => 95], 'created_at' => '2026-09-15 12:00:00']);
        foreach ($types as $i => $type) {
            $order->items()->create(['item_type' => $type, 'title' => 'Synthetic '.$type.' '.$i,
                'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        }

        return $order;
    }

    private function event(Order $order, int $cents, string $date, bool $affects = true): OrderPaymentEvent
    {
        return OrderPaymentEvent::create(['event_uuid' => Str::uuid(), 'checkout_group_key' => $order->checkout_group_key,
            'order_id' => $order->id, 'actor_user_id' => $this->admin->id,
            'event_type' => $cents >= 0 ? 'payment_received' : 'payment_reversed', 'source' => 'synthetic_test',
            'previous_status' => 'unpaid', 'new_status' => 'partially_paid', 'previous_paid_amount_cents' => 0,
            'new_paid_amount_cents' => max(0, $cents), 'amount_delta_cents' => $cents, 'affects_collection_stats' => $affects,
            'payment_method' => 'Synthetic Method', 'occurred_at' => CarbonImmutable::parse($date, 'Africa/Cairo')->utc()]);
    }

    private function filters(array $query = []): SalesReportFilters
    {
        return SalesReportFilters::fromRequest(Request::create('/', 'GET', $query + ['range' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10']));
    }

    public function test_production_shape_month_cash_and_seven_days_match_dashboard_and_sales_exactly(): void
    {
        $old = $this->order('OLD-PAID-OCT');
        foreach ([1 => 1334800, 4 => 935100, 5 => 563800, 6 => 469800, 7 => 479800, 8 => 563200, 9 => 156800, 10 => 535826] as $day => $cents) {
            $this->event($old, $cents, sprintf('2026-10-%02d 12:00:00', $day));
        }
        $service = app(PaymentCollectionReportService::class);
        $events = $service->events($this->filters());
        $this->assertSame(5039126, $service->summary($events)['net_cents']);
        $days = $service->daily($events, $this->filters());
        $this->assertCount(10, $days);
        $this->assertSame(5039126, (int) $days->sum('net_cents'));
        $this->assertSame(3704326, (int) $days->where('date', '>=', '2026-10-04')->sum('net_cents'));
        $sales = app(SalesReportService::class)->report($this->filters());
        $this->assertSame(50391.26, $sales['summary']['total']);
        $this->assertSame(0, $sales['operational_summary']['all_checkouts']); // Old order is not new intake.
        $this->assertEqualsWithDelta(50391.26, collect($sales['trend'])->sum('total'), 0.00001);
        $dashboard = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(3704326, (int) collect($dashboard['last_seven_days'])->sum('payments_cents'));
        $this->assertSame(535826, $dashboard['today']['payments_cents']);
        $this->assertSame(1, $service->summary($events)['checkouts']);
        $this->get(route('admin.payment-report.index', ['range' => 'this_month']))->assertOk()->assertSee('٥٠,٣٩١.٢٦');
        $this->get(route('admin.sales-report.index', ['range' => 'this_month']))->assertOk()->assertSee('٥٠,٣٩١.٢٦');
        $this->get(route('admin.dashboard.index'))->assertOk()->assertSee('٥,٣٥٨.٢٦');
    }

    public function test_cancelled_deleted_overpaid_and_reversed_cash_is_not_capped_or_excluded(): void
    {
        $cancelled = $this->order('CANCELLED', ['product'], ['status' => 'cancelled']);
        $deleted = $this->order('DELETED');
        $this->event($cancelled, 90000, '2026-10-04 12:00:00'); // More than current order value.
        $this->event($deleted, 50000, '2026-10-04 13:00:00');
        $this->event($deleted, -12345, '2026-10-05 12:00:00');
        $this->event($deleted, 999999, '2026-10-04 14:00:00', false); // Baseline/merge excluded.
        $deleted->delete();
        $service = app(PaymentCollectionReportService::class);
        $events = $service->events($this->filters());
        $summary = $service->summary($events);
        $this->assertSame(127655, $summary['net_cents']);
        $this->assertSame(140000, $summary['received_cents']);
        $this->assertSame(12345, $summary['reversed_cents']);
        $this->assertSame(1276.55, app(SalesReportService::class)->report($this->filters())['summary']['total']);
        $this->assertCount(3, $events);
        $this->assertSame(2, $summary['checkouts']);
        $this->assertSame(1, $events->where('category', 'product')->count());
        $this->assertSame(2, $events->where('category', 'story')->count());
    }

    public function test_disjoint_daily_story_product_package_mixed_and_unknown_totals_reconcile(): void
    {
        foreach (['STORY' => ['story'], 'PRODUCT' => ['product'], 'MIXED' => ['story', 'product'], 'PACKAGE' => ['product'], 'UNKNOWN' => []] as $key => $types) {
            $order = $this->order($key, $types);
            if ($key === 'PACKAGE') {
                $order->items()->first()->update(['item_snapshot' => ['package' => ['id' => 7]]]);
            }
            $this->event($order, 10001, '2026-10-04 12:00:00');
            $this->event($order, -1, '2026-10-04 12:01:00');
        }
        $service = app(PaymentCollectionReportService::class);
        $day = $service->daily($service->events($this->filters()), $this->filters())->firstWhere('date', '2026-10-04');
        $this->assertSame(10000, $day['story_cents']);
        $this->assertSame(10000, $day['product_cents']);
        $this->assertSame(20000, $day['group_cents']);
        $this->assertSame(10000, $day['unknown_cents']);
        $this->assertSame(50000, $day['net_cents']);
        $this->assertSame(10, $day['received_count'] + $day['reversed_count']);
        $this->assertSame(0, $service->daily($service->events($this->filters()), $this->filters())->firstWhere('date', '2026-10-02')['net_cents']);
    }

    public function test_multiple_carriers_and_superseded_items_do_not_duplicate_payments_or_quantities(): void
    {
        $old = $this->order('SAME', ['product']);
        $live = $this->order('SAME', ['story']);
        $old->delete();
        $this->event($live, 10001, '2026-10-04 12:00:00');
        $this->event($live, 5001, '2026-10-05 12:00:00');
        $service = app(PaymentCollectionReportService::class);
        $rows = $service->checkouts($service->events($this->filters()));
        $this->assertCount(1, $rows);
        $this->assertSame(15002, $rows->first()['paid_amount_cents']);
        $this->assertCount(1, $rows->first()['items']);
        $this->assertSame(1, $rows->first()['items'][0]['quantity']);
        $this->assertSame('story', $rows->first()['category']);
    }

    public function test_item_filter_allocates_once_and_preserves_signed_cents(): void
    {
        $order = $this->order('ALLOCATE', ['story', 'product', 'product_add_on']);
        $this->event($order, 10001, '2026-10-04 12:00:00');
        $this->event($order, -2, '2026-10-04 12:01:00');
        $service = app(PaymentCollectionReportService::class);
        $sum = 0;
        foreach (['story', 'product', 'product_add_on'] as $type) {
            $events = $service->events($this->filters(['type' => $type]));
            $sum += $service->summary($events)['net_cents'];
            $this->assertSame($type, $events->first()['items'][0]['type']);
        }
        $this->assertSame(9999, $sum);
        $this->assertSame(9999, (int) collect(app(SalesReportService::class)->report($this->filters())['type_breakdown'])->sum(fn ($row) => (int) round($row['sales'] * 100)));
    }

    public function test_cairo_midnight_half_open_calendar_and_stable_payment_order(): void
    {
        $order = $this->order('MIDNIGHT');
        $this->event($order, 99, '2026-09-30 23:59:59');
        $first = $this->event($order, 101, '2026-10-01 00:00:00');
        $last = $this->event($order, 202, '2026-10-01 00:00:00');
        $this->event($order, 404, '2026-10-02 00:00:00');
        $events = app(PaymentCollectionReportService::class)->events($this->filters(['end_date' => '2026-10-01']));
        $this->assertSame([$last->id, $first->id], $events->pluck('id')->all());
        $this->assertSame(303, (int) $events->sum('amount_delta_cents'));
    }

    public function test_daily_links_open_paginated_details_and_exports_with_identical_total(): void
    {
        $order = $this->order('DETAIL', ['product'], ['parent_name' => '=SYNTHETIC']);
        for ($i = 0; $i < 53; $i++) {
            $this->event($order, 101, '2026-10-04 12:00:00');
        }
        $url = route('admin.payment-report.index', ['range' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10']);
        $this->get($url)->assertOk()->assertSee('تقرير الدفعات')->assertSee('day=2026-10-04', false)->assertDontSee('=SYNTHETIC');
        $page = $this->get($url.'&day=2026-10-04&page=2')->assertOk()->assertSee('Synthetic Method');
        $this->assertSame(53, $page->viewData('rows')->total());
        $this->assertCount(3, $page->viewData('rows'));
        $this->assertSame(5353, $page->viewData('selectedSummary')['net_cents']);
        $response = $this->get(route('admin.payment-report.export', ['range' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'day' => '2026-10-04']))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=SYNTHETIC", $csv);
        $lines = explode("\n", trim($csv));
        $this->assertCount(54, $lines);
        $this->assertSame(5353, array_sum(array_map(fn ($line) => (int) round((float) str_getcsv($line)[6] * 100), array_slice($lines, 1))));
    }

    public function test_routes_enforce_permissions_dates_and_read_only_financial_behavior(): void
    {
        $order = $this->order('SAFE');
        $this->event($order, 20000, '2026-10-04 12:00:00');
        $before = DB::table('order_payment_events')->get()->toJson();
        $ordersBefore = DB::table('orders')->get()->toJson();
        $this->get(route('admin.payment-report.index'))->assertOk();
        $this->get(route('admin.payment-report.index', ['day' => '2026-09-01']))->assertStatus(422);
        $this->get(route('admin.payment-report.index', ['range' => 'custom', 'start_date' => '2020-01-01', 'end_date' => '2026-10-10']))->assertStatus(422);
        $this->assertSame($before, DB::table('order_payment_events')->get()->toJson());
        $this->assertSame($ordersBefore, DB::table('orders')->get()->toJson());
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');
        $this->actingAs($limited)->get(route('admin.payment-report.index'))->assertForbidden();
        $this->get(route('admin.payment-report.export'))->assertForbidden();
    }

    public function test_advertising_collection_basis_includes_old_orders_paid_in_period_not_their_old_balances(): void
    {
        $order = $this->order('OLD-MARKETING');
        $order->update(['paid_amount_cents' => 99000]);
        $this->event($order, 25000, '2026-10-04 12:00:00');
        $report = app(AdvertisingTargetingReportService::class)->report(Request::create('/', 'GET', ['range' => 'this_month', 'basis' => 'collected']));
        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame(25000, $report['summary']['collected_cents']);
    }

    public function test_negative_csv_amount_remains_numeric_and_day_details_preserve_item_filters(): void
    {
        $order = $this->order('CSV-SIGNED', ['story', 'product']);
        $this->event($order, 10001, '2026-10-04 12:00:00');
        $this->event($order, -101, '2026-10-04 13:00:00');
        $query = ['range' => 'custom', 'start_date' => '2026-10-01', 'end_date' => '2026-10-10', 'day' => '2026-10-04', 'type' => 'story'];
        $page = $this->get(route('admin.payment-report.index', $query))->assertOk();
        $this->assertSame(4950, $page->viewData('summary')['net_cents']);
        $this->assertSame(4950, $page->viewData('selectedSummary')['net_cents']);
        $csv = $this->get(route('admin.payment-report.export', $query))->assertOk()->streamedContent();
        $values = array_map(fn ($line) => str_getcsv($line)[6], array_slice(explode("\n", trim($csv)), 1));
        $this->assertContains('-0.50', $values);
        $this->assertSame(4950, array_sum(array_map(fn ($value) => (int) round((float) $value * 100), $values)));
    }

    public function test_week_month_and_day_cash_trends_reconcile_with_signed_ledger_across_batch_boundary(): void
    {
        $order = $this->order('BATCH');
        for ($i = 0; $i < 205; $i++) {
            $this->event($order, $i % 7 === 0 ? -5 : 101, $i % 2 === 0 ? '2026-10-01 12:00:00' : '2026-10-10 12:00:00');
        }
        $service = app(PaymentCollectionReportService::class);
        DB::enableQueryLog();
        $events = $service->events($this->filters());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(205, $events);
        $this->assertLessThan(25, count($queries)); // Bounded relation loading, not one query per payment.
        foreach (['day', 'week', 'month'] as $groupBy) {
            $trend = $service->trend($events, $this->filters(['group_by' => $groupBy]));
            $this->assertSame((int) $events->sum('amount_delta_cents'), (int) round(collect($trend)->sum('total') * 100));
        }
    }

    public function test_report_money_preserves_cents_and_marketing_csv_keeps_negative_cash_numeric(): void
    {
        $this->assertSame('١٢٣.٤٥', format_report_money(123.45, false));
        $this->assertSame('-٠.٠١', format_report_money(-0.01, false));
        $this->assertSame('١٢٣', format_report_money(123, false));
        $this->assertSame('١٢٣', format_money(123.45, false)); // Public prices retain their existing formatting.
        $order = $this->order('MARKETING-NEGATIVE');
        $this->event($order, -101, '2026-10-04 12:00:00');
        $csv = $this->get(route('admin.advertising-report.export', ['range' => 'this_month', 'basis' => 'collected']))->assertOk()->streamedContent();
        $values = array_map(fn ($line) => str_getcsv($line)[8], array_slice(explode("\n", trim($csv)), 1));
        $this->assertContains('-1.01', $values);
    }
}
