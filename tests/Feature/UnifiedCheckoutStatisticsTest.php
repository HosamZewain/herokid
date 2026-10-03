<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use App\Services\Analytics\AnalyticsDateRange;
use App\Services\Analytics\LocalCartAnalyticsRepository;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\CheckoutIntakeStatistics;
use App\Services\Orders\OrderFinancialStatistics;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use App\Support\AppDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnifiedCheckoutStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-03 17:50:00', 'Africa/Cairo')->utc());
    }

    public function test_screenshot_regression_cards_and_saturday_use_the_same_purchases(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->row("TODAY-$i", 38200, 9550);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->row("OLDER-$i", 48200, 9400, now()->subDay());
            $this->row("OLDER-$i", 20000, 9400);
        }

        $stats = app(AdminOrderGroupService::class)->dashboardStats();
        $today = collect($stats['last_seven_days'])->firstWhere('date', '2026-10-03');
        $yesterday = collect($stats['last_seven_days'])->firstWhere('date', '2026-10-02');

        $this->assertSame(12, $stats['today']['new_checkouts']);
        $this->assertSame(573000, $stats['today']['order_value_cents']);
        $this->assertSame(38200, $stats['today']['average_order_cents']);
        $this->assertSame($today['new_checkouts'], $stats['today']['new_checkouts']);
        $this->assertSame($today['total_value_cents'], $stats['today']['order_value_cents']);
        $this->assertSame($today['average_order_cents'], $stats['today']['average_order_cents']);
        $this->assertSame(3, $stats['today']['yesterday_checkouts']);
        $this->assertSame($yesterday['new_checkouts'], $stats['today']['yesterday_checkouts']);
        $this->assertSame(9, $stats['today']['new_checkouts_difference']);
        $this->assertSame(232800, $yesterday['total_value_cents']);
    }

    public function test_deleted_and_cancelled_purchases_remain_in_daily_intake(): void
    {
        $this->row('LIVE', 30000, 9500);
        $deleted = $this->row('DELETED', 50000, 9500);
        $deleted->delete();
        $cancelled = $this->row('CANCELLED', 10000, 9500);
        $cancelled->update(['status' => 'cancelled']);

        $stats = app(AdminOrderGroupService::class)->dashboardStats();
        $day = collect($stats['last_seven_days'])->firstWhere('date', '2026-10-03');
        $this->assertSame(3, $stats['today']['new_checkouts']);
        $this->assertSame(118500, $stats['today']['order_value_cents']);
        $this->assertSame(30000, $stats['today']['average_order_cents']);
        $this->assertSame($day['new_checkouts'], $stats['today']['new_checkouts']);
        $this->assertSame($day['average_order_cents'], $stats['today']['average_order_cents']);
        $this->assertSame(1, $stats['operations']['active_checkouts']);
    }

    public function test_removed_original_row_does_not_move_purchase_to_another_day_or_double_count_old_items(): void
    {
        $original = $this->row('REPLACED', 30000, 9500, now()->subDay());
        $replacement = $this->row('REPLACED', 50000, 9500);
        $original->delete();

        $stats = app(AdminOrderGroupService::class)->dashboardStats();
        $yesterday = collect($stats['last_seven_days'])->firstWhere('date', '2026-10-02');
        $this->assertSame(0, $stats['today']['new_checkouts']);
        $this->assertSame(1, $stats['today']['yesterday_checkouts']);
        $this->assertSame(59500, $yesterday['total_value_cents']);
        $this->assertSame(50000, $yesterday['average_order_cents']);

        $admin = User::factory()->create(['role' => 'admin']);
        $report = $this->actingAs($admin)->get(route('admin.order-report.index', [
            'lifecycle' => 'active', 'from' => '2026-10-02', 'to' => '2026-10-02',
        ]))->assertOk()->viewData('report');
        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame(59500, $report['summary']['total_cents']);
        $this->assertSame('2026-10-02', AppDateTime::format($report['rows']->first()['created_at'], 'Y-m-d'));
        $this->assertSame($replacement->id, $report['rows']->first()['representative_id']);
    }

    public function test_multi_story_purchase_counts_once_and_average_deducts_discount_not_shipping(): void
    {
        $story = Story::create([
            'title' => 'Synthetic story', 'slug' => 'statistics-story',
            'language' => 'ar', 'gender' => 'both', 'price' => 300, 'active' => true,
        ]);
        foreach ([30000, 20000] as $cents) {
            $order = $this->row('MULTI', $cents, 9500);
            $order->update(['story_id' => $story->id, 'discount_cents' => 5000]);
            $order->items()->update(['item_type' => 'story', 'story_id' => $story->id]);
        }

        $stats = app(AdminOrderGroupService::class)->dashboardStats();
        $day = collect($stats['last_seven_days'])->firstWhere('date', '2026-10-03');
        $this->assertSame(1, $stats['today']['new_checkouts']);
        $this->assertSame(54500, $stats['today']['order_value_cents']);
        $this->assertSame(45000, $stats['today']['average_order_cents']);
        $this->assertSame(1, $day['story_checkouts']);
        $this->assertSame(0, $day['product_checkouts']);
    }

    public function test_empty_days_return_zero_without_division_errors(): void
    {
        $stats = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertCount(7, $stats['last_seven_days']);
        $this->assertSame(0, $stats['today']['new_checkouts']);
        $this->assertSame(0, $stats['today']['average_order_cents']);
        $this->assertSame(0, $stats['today']['order_value_cents']);
    }

    public function test_cairo_midnight_boundaries_and_original_dates_are_consistent(): void
    {
        $start = AppDateTime::utcStartOfDay('2026-10-03');
        $end = AppDateTime::utcEndOfDay('2026-10-03');
        $this->row('BEFORE', 10000, 0, $start->subSecond());
        $this->row('START', 20000, 0, $start);
        $this->row('END', 30000, 0, $end->startOfSecond());
        $this->row('AFTER', 40000, 0, $end->addSecond());
        $this->row('BEFORE', 50000, 0, $start->addHour());

        $intake = app(CheckoutIntakeStatistics::class);
        $this->assertEqualsCanonicalizing(['START', 'END'], $intake->keysBetween($start, $end)->pluck('checkout_group_key')->all());
        $this->assertSame(2, $intake->countBetween($start, $end));
        $this->assertSame(1, $intake->keysBetween(null, $start->subSecond())->get()->count());
    }

    public function test_order_list_report_and_csv_filter_by_first_purchase_date(): void
    {
        $this->row('OLDER', 30000, 9500, now()->subDay());
        $added = $this->row('OLDER', 20000, 9500);
        $this->row('NEW', 10000, 9500);
        $admin = User::factory()->create(['role' => 'admin']);
        $filters = ['catalog_type' => 'all', 'lifecycle' => 'all', 'from' => '2026-10-03', 'to' => '2026-10-03'];

        $index = $this->actingAs($admin)->get(route('admin.orders.index', $filters))->assertOk();
        $this->assertSame(1, $index->viewData('groups')->total());
        $this->assertSame('NEW', $index->viewData('groups')->items()[0]['key']);
        $this->assertSame(10000, $index->viewData('stats')['average_order_cents']);
        $report = $this->get(route('admin.order-report.index', $filters))->assertOk()->viewData('report');
        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame(10000, $report['summary']['average_order_cents']);
        $export = $this->get(route('admin.orders.export', $filters))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($added->order_number, $export);

        $filters['from'] = $filters['to'] = '2026-10-02';
        $report = $this->get(route('admin.order-report.index', $filters))->assertOk()->viewData('report');
        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame(59500, $report['summary']['total_cents']);
        $this->assertSame(50000, $report['summary']['average_order_cents']);
        $this->assertSame(1, $report['breakdowns']['daily']->first()['count']);
        $this->assertSame('2026-10-02', $report['breakdowns']['daily']->first()['label']);
    }

    public function test_sales_intake_does_not_recount_old_checkout_and_includes_later_items_in_original_period(): void
    {
        $old = $this->row('OLDER', 30000, 9500, now()->subDay());
        $old->update(['paid_amount_cents' => 50000, 'payment_status' => 'partially_paid']);
        $this->row('OLDER', 20000, 9500);
        $current = $this->row('NEW', 10000, 9500);
        $current->update(['paid_amount_cents' => 19500, 'payment_status' => 'paid_in_full']);

        $today = $this->sales('today');
        $this->assertSame(1, $today['operational_summary']['all_checkouts']);
        $this->assertSame(100.0, $today['summary']['average_checkout']);
        $yesterday = $this->sales('yesterday');
        $this->assertSame(1, $yesterday['summary']['checkouts']);
        $this->assertSame(500.0, $yesterday['summary']['average_checkout']);
        $this->assertSame(595.0, $yesterday['summary']['order_value']);
        $this->assertSame(2, $yesterday['summary']['order_records']);
        $this->assertSame('2026-10-02', $yesterday['trend'][0]['key']);
    }

    public function test_sales_trend_uses_cairo_day_instead_of_utc_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03 00:30:00', 'Africa/Cairo')->utc());
        $order = $this->row('MIDNIGHT', 30000, 9500);
        $order->update(['paid_amount_cents' => 39500, 'payment_status' => 'paid_in_full']);
        $report = $this->sales('today');
        $this->assertCount(1, $report['trend']);
        $this->assertSame('2026-10-03', $report['trend'][0]['key']);
        $this->assertSame(1, $report['trend'][0]['checkouts']);
        $this->assertSame(395.0, $report['trend'][0]['total']);
    }

    public function test_local_analytics_counts_purchases_not_story_rows_and_matches_dashboard_value(): void
    {
        $this->row('MULTI', 30000, 9500);
        $this->row('MULTI', 20000, 9500)->update(['discount_cents' => 5000]);
        $this->row('OLDER', 20000, 9500, now()->subDay());
        $this->row('OLDER', 30000, 9500);
        $this->row('DELETED', 10000, 9500)->delete();

        $local = app(LocalCartAnalyticsRepository::class);
        $summary = $local->todaySummary();
        $stats = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(2.0, $summary['purchases_today']['value']);
        $this->assertSame(740.0, $summary['revenue_today']['value']);
        $this->assertSame((float) $stats['today']['new_checkouts'], $summary['purchases_today']['value']);
        $this->assertSame((float) ($stats['today']['order_value_cents'] / 100), $summary['revenue_today']['value']);
        $funnel = $local->funnel(new AnalyticsDateRange('custom', 'Test', '2026-10-03', '2026-10-03'));
        $this->assertSame(2, collect($funnel)->firstWhere('event', 'purchase_local')['value']);
    }

    public function test_statistics_are_read_only_and_historical_values_prefer_current_live_rows(): void
    {
        $old = $this->row('REPLACED', 30000, 9500, now()->subDay());
        $this->row('REPLACED', 40000, 9500);
        $old->delete();
        $before = DB::table('orders')->orderBy('id')->get()->toJson();
        $keys = app(CheckoutIntakeStatistics::class)->keysBetween(AppDateTime::utcStartOfDay('2026-10-02'), AppDateTime::utcEndOfDay('2026-10-02'));
        $financial = app(OrderFinancialStatistics::class)->summarize($keys, true);
        $this->assertSame(49500, $financial['total_value_cents']);
        app(AdminOrderGroupService::class)->dashboardStats();
        app(LocalCartAnalyticsRepository::class)->todaySummary();
        $this->sales('yesterday');
        $this->assertSame($before, DB::table('orders')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_sales_recognition_and_catalog_filters_remain_intentionally_distinct_from_intake(): void
    {
        $paid = $this->row('PAID', 30000, 9500);
        $paid->update(['paid_amount_cents' => 39500, 'payment_status' => 'paid_in_full']);
        $unpaid = $this->row('UNPAID', 20000, 9500);
        $cancelled = $this->row('CANCELLED', 10000, 9500);
        $cancelled->update(['status' => 'cancelled', 'paid_amount_cents' => 19500, 'payment_status' => 'paid_in_full']);
        $deleted = $this->row('DELETED', 40000, 9500);
        $deleted->update(['paid_amount_cents' => 49500, 'payment_status' => 'paid_in_full']);
        $deleted->delete();

        $sales = $this->sales('today');
        $this->assertSame(1, $sales['summary']['checkouts']);
        $this->assertSame(300.0, $sales['summary']['average_checkout']);
        $this->assertSame(3, $sales['operational_summary']['all_checkouts']);
        $this->assertSame(1, $sales['operational_summary']['cancelled_checkouts']);
        $this->assertSame(4, app(AdminOrderGroupService::class)->dashboardStats()['today']['new_checkouts']);

        $admin = User::factory()->create(['role' => 'admin']);
        $report = $this->actingAs($admin)->get(route('admin.order-report.index', [
            'catalog_type' => 'products', 'lifecycle' => 'active', 'from' => '2026-10-03', 'to' => '2026-10-03',
        ]))->assertOk()->viewData('report');
        $this->assertSame(2, $report['summary']['checkouts']);
        $this->assertEqualsCanonicalizing([$paid->id, $unpaid->id], $report['rows']->pluck('representative_id')->all());
    }

    public function test_legacy_story_without_items_keeps_price_fallback_and_local_analytics_matches_it(): void
    {
        $story = Story::create([
            'title' => 'Legacy story', 'slug' => 'statistics-legacy-story',
            'language' => 'ar', 'gender' => 'both', 'price' => 300, 'active' => true,
        ]);
        Order::create([
            'order_number' => 'LEGACY', 'checkout_group_key' => 'LEGACY', 'story_id' => $story->id,
            'status' => 'new', 'discount_cents' => 5000,
            'delivery_details' => ['item_price' => 250, 'delivery_fee' => 95],
        ]);

        $today = app(AdminOrderGroupService::class)->dashboardStats()['today'];
        $local = app(LocalCartAnalyticsRepository::class)->todaySummary();
        $this->assertSame(1, $today['new_checkouts']);
        $this->assertSame(29500, $today['order_value_cents']);
        $this->assertSame(20000, $today['average_order_cents']);
        $this->assertSame(295.0, $local['revenue_today']['value']);
    }

    public function test_open_ended_and_invalid_date_filters_keep_the_list_usable(): void
    {
        $this->row('OLDER', 30000, 9500, now()->subDay());
        $this->row('OLDER', 20000, 9500);
        $this->row('NEW', 10000, 9500);
        $admin = User::factory()->create(['role' => 'admin']);
        $base = ['catalog_type' => 'all', 'lifecycle' => 'all'];
        $this->actingAs($admin);

        $from = $this->get(route('admin.orders.index', $base + ['from' => '2026-10-03']))->assertOk();
        $this->assertSame(1, $from->viewData('groups')->total());
        $this->assertSame('NEW', $from->viewData('groups')->items()[0]['key']);
        $to = $this->get(route('admin.orders.index', $base + ['to' => '2026-10-02']))->assertOk();
        $this->assertSame(1, $to->viewData('groups')->total());
        $this->assertSame('OLDER', $to->viewData('groups')->items()[0]['key']);
        $invalid = $this->get(route('admin.orders.index', $base + ['from' => 'not-a-date', 'to' => 'not-a-date']))->assertOk();
        $this->assertSame(2, $invalid->viewData('groups')->total());
    }

    private function sales(string $range): array
    {
        return app(SalesReportService::class)->report(SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => $range])));
    }

    private function row(string $group, int $cents, int $shipping, mixed $createdAt = null): Order
    {
        $order = Order::create([
            'order_number' => 'SYNTHETIC-'.fake()->uuid(), 'checkout_group_key' => $group,
            'parent_name' => 'Synthetic fixture', 'status' => 'new',
            'delivery_details' => ['checkout_group' => $group, 'delivery_fee' => $shipping / 100],
        ]);
        $order->items()->create([
            'item_type' => 'product', 'title' => 'Synthetic item',
            'unit_price_cents' => $cents, 'total_price_cents' => $cents, 'quantity' => 1,
        ]);
        $order->forceFill(['created_at' => $createdAt ?? now()])->save();

        return $order;
    }
}
