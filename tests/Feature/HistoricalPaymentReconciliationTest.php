<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderGroupMergeAlias;
use App\Models\OrderPaymentEvent;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderFinancialStatistics;
use App\Services\Payments\HistoricalPaymentSource;
use App\Services\Payments\PaymentCollectionReportService;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HistoricalPaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 17:00:00', 'Africa/Cairo')->utc());
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function order(string $key, int $paid = 0): Order
    {
        $order = Order::create(['order_number' => 'RECON-'.Str::uuid(), 'checkout_group_key' => $key, 'status' => 'new',
            'parent_name' => 'Synthetic', 'paid_amount_cents' => 0, 'delivery_details' => ['delivery_fee' => 0], 'created_at' => '2026-08-01 12:00:00']);
        $order->forceFill(['paid_amount_cents' => $paid])->saveQuietly();
        $order->items()->create(['item_type' => 'product', 'title' => 'Synthetic', 'quantity' => 1, 'unit_price_cents' => 50000, 'total_price_cents' => 50000]);

        return $order;
    }

    private function event(Order $order, string $type, int $old, int $new, int $delta, bool $cash = false): OrderPaymentEvent
    {
        return OrderPaymentEvent::create(['event_uuid' => Str::uuid(), 'checkout_group_key' => $order->checkout_group_key,
            'order_id' => $order->id, 'event_type' => $type, 'source' => $type, 'new_status' => 'partially_paid',
            'previous_paid_amount_cents' => $old, 'new_paid_amount_cents' => $new, 'amount_delta_cents' => $delta,
            'affects_collection_stats' => $cash, 'occurred_at' => '2026-08-31 10:01:09',
            'created_at' => $type === 'legacy_baseline' ? '2026-08-31 09:00:00' : '2026-09-01 12:00:00']);
    }

    private function log(Order $order, int $old, int $new, string $date, string $action = 'checkout.payment_updated'): AdminActivityLog
    {
        return AdminActivityLog::create(['user_id' => auth()->id(), 'action' => $action, 'subject_type' => Order::class, 'subject_id' => $order->id,
            'properties' => ['checkout_group_key' => $order->checkout_group_key,
                'old' => ['paid_amount_cents' => $old], 'new' => ['paid_amount_cents' => $new, 'payment_status' => 'partially_paid', 'payment_method' => 'Synthetic'],
                'payment' => ['paid_amount_cents' => $new, 'payment_status' => 'partially_paid', 'payment_method' => 'Synthetic']], 'created_at' => $date]);
    }

    public function test_live_figures_are_explained_without_treating_adjustments_or_merges_as_cash(): void
    {
        $order = $this->order('FIGURES', 35532101);
        $this->event($order, 'legacy_baseline', 0, 4464700, 0);
        $this->event($order, 'payment_received', 4464700, 36005501, 31540801, true);
        $this->event($order, 'payment_reversed', 36005501, 35089601, -915900, true);
        $this->event($order, 'payment_balance_adjusted', 35089601, 35261001, 171400);
        $this->event($order, 'merge_reconciliation', 10000, 739300, 0);
        $before = DB::table('order_payment_events')->get()->toJson();
        $report = app(PaymentReconciliationService::class)->report();
        $this->assertSame(35089601, $report['opening_plus_net_cents']);
        $this->assertSame(171400, $report['non_cash_adjustments_cents']);
        $this->assertSame(729300, $report['merge_transfers_cents']);
        $this->assertSame(35261001, $report['expected_balance_cents']);
        $this->assertSame(271100, $report['unreconciled_cents']);
        $this->assertSame(4464700, $report['history']['undated_cents']);
        $this->assertSame($before, DB::table('order_payment_events')->get()->toJson());
        foreach (['admin.payment-report.index', 'admin.sales-report.index', 'admin.order-report.index', 'admin.advertising-report.index', 'admin.dashboard.index'] as $route) {
            $this->get(route($route))->assertOk()->assertSee('٣٥٠,٨٩٦.٠١')->assertSee('إجمالي المدفوع المسجل');
        }
    }

    public function test_legacy_partial_payments_and_reversal_use_real_audit_dates_once(): void
    {
        $order = $this->order('HISTORY', 15000);
        $this->event($order, 'legacy_baseline', 0, 15000, 0);
        $this->log($order, 0, 10000, '2026-08-10 12:00:00');
        $this->log($order, 10000, 20000, '2026-08-11 12:00:00');
        $this->log($order, 20000, 15000, '2026-08-12 12:00:00');
        // It is after the baseline and belongs to the new ledger, not the projection.
        $this->log($order, 15000, 99999, '2026-09-02 12:00:00');
        $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']));
        $source = app(HistoricalPaymentSource::class)->history();
        $this->assertSame(15000, $source['recovered_net_cents']);
        $this->assertSame(0, $source['undated_cents']);
        $events = app(PaymentCollectionReportService::class)->events($filters, true);
        $this->assertCount(3, $events);
        $this->assertSame(15000, (int) $events->sum('amount_delta_cents'));
        $this->assertSame('Synthetic', $events->first()['payment_method']);
        $this->assertNotNull($events->first()['actor_name']);
        $this->get(route('admin.payment-report.index', ['range' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31', 'day' => '2026-08-10']))
            ->assertOk()->assertSee('سجل قديم');
    }

    public function test_unknown_opening_credit_is_counted_but_never_put_on_the_order_or_baseline_date(): void
    {
        $order = $this->order('UNKNOWN', 30000);
        $this->event($order, 'legacy_baseline', 0, 30000, 0);
        $this->log($order, 20000, 30000, '2026-08-20 12:00:00');
        $history = app(HistoricalPaymentSource::class)->history();
        $this->assertSame(10000, $history['recovered_net_cents']);
        $this->assertSame(20000, $history['undated_cents']);
        $this->assertSame(30000, app(PaymentReconciliationService::class)->report()['opening_plus_net_cents']);
        $this->assertSame('2026-08-20', $history['events']->first()->occurred_at->toDateString());
    }

    public function test_broken_history_or_automatic_revaluation_is_not_fabricated_as_a_receipt(): void
    {
        $order = $this->order('BROKEN', 20000);
        $this->event($order, 'legacy_baseline', 0, 20000, 0);
        $this->log($order, 0, 10000, '2026-08-10 12:00:00');
        $this->log($order, 15000, 20000, '2026-08-12 12:00:00');
        $history = app(HistoricalPaymentSource::class)->history();
        $this->assertSame(0, $history['recovered_net_cents']);
        $this->assertSame(20000, $history['undated_cents']);
        $this->assertSame('incomplete_history', $history['issues']->first()['reason']);
    }

    public function test_cash_stays_once_after_merge_even_when_deleted_source_carrier_remains(): void
    {
        $source = $this->order('SOURCE', 20000);
        $target = $this->order('TARGET', 50000);
        $this->event($source, 'payment_received', 0, 20000, 20000, true);
        $this->event($target, 'payment_received', 0, 30000, 30000, true);
        $this->event($target, 'merge_reconciliation', 30000, 50000, 0);
        $source->delete();
        OrderGroupMergeAlias::create(['source_checkout_group_key' => 'SOURCE', 'target_checkout_group_key' => 'TARGET',
            'reason' => 'Synthetic merge', 'merged_at' => now()]);
        $report = app(PaymentReconciliationService::class)->report();
        $this->assertSame(50000, $report['opening_plus_net_cents']);
        $this->assertSame(50000, $report['current_balance_cents']);
        $this->assertSame(20000, $report['merged_source_copies_cents']);
        $this->assertSame(0, $report['unreconciled_cents']);
        $this->assertSame(50000, app(OrderFinancialStatistics::class)->summarize(['SOURCE', 'TARGET'], true)['collected_cents']);
        $request = Request::create('/', 'GET', ['catalog_type' => 'all', 'lifecycle' => 'all']);
        $request->setUserResolver(fn () => auth()->user());
        $request->attributes->set('order_report', true);
        $this->assertCount(1, app(AdminOrderGroupService::class)->reportFacts($request));
        $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-09-30']));
        $rows = app(PaymentCollectionReportService::class)->events($filters);
        $this->assertSame(50000, (int) $rows->sum('amount_delta_cents'));
        $this->assertSame(['TARGET'], $rows->pluck('key')->unique()->all());
    }

    public function test_manual_creation_payment_is_recovered_and_exported_with_its_audit_identity(): void
    {
        $order = $this->order('MANUAL', 12000);
        $this->event($order, 'legacy_baseline', 0, 12000, 0);
        $log = $this->log($order, 0, 12000, '2026-08-15 12:00:00', 'order.created_manually');
        $history = app(HistoricalPaymentSource::class)->history();
        $this->assertSame(12000, $history['recovered_net_cents']);
        $this->assertSame(0, $history['undated_cents']);
        $response = $this->get(route('admin.payment-report.export', ['range' => 'custom', 'start_date' => '2026-08-15', 'end_date' => '2026-08-15']))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('activity:'.$log->id, $csv);
        $this->assertStringContainsString('historical_admin_activity', $csv);
    }

    public function test_zero_baseline_does_not_hide_historic_receipts_and_reversals(): void
    {
        $order = $this->order('ZERO');
        $this->event($order, 'legacy_baseline', 0, 0, 0);
        $this->log($order, 0, 12000, '2026-08-15 12:00:00');
        $this->log($order, 12000, 0, '2026-08-16 12:00:00');
        $history = app(HistoricalPaymentSource::class)->history();
        $this->assertCount(2, $history['events']);
        $this->assertSame(0, $history['recovered_net_cents']);
    }

    public function test_historic_full_edit_revaluation_and_overlapping_ledger_are_flagged_not_double_counted(): void
    {
        $edited = $this->order('EDITED', 15000);
        $this->event($edited, 'legacy_baseline', 0, 15000, 0);
        $this->log($edited, 0, 10000, '2026-08-10 12:00:00');
        AdminActivityLog::create(['action' => 'checkout.full_order_updated', 'subject_type' => Order::class,
            'subject_id' => $edited->id, 'properties' => ['checkout_group_key' => 'EDITED',
                'before' => ['paid_amount_cents' => 10000], 'after' => ['paid_amount_cents' => 15000]],
            'created_at' => '2026-08-11 12:00:00']);
        $overlap = $this->order('OVERLAP', 20000);
        $this->event($overlap, 'legacy_baseline', 0, 20000, 0);
        $this->log($overlap, 0, 20000, '2026-08-10 12:00:00');
        $event = $this->event($overlap, 'payment_received', 0, 20000, 20000, true);
        DB::table('order_payment_events')->where('id', $event->id)->update(['occurred_at' => '2026-08-10 12:00:00']);
        $history = app(HistoricalPaymentSource::class)->history();
        $this->assertCount(0, $history['events']);
        $this->assertSame(35000, $history['undated_cents']);
        $this->assertEqualsCanonicalizing(['incomplete_history', 'overlapping_ledger'], $history['issues']->pluck('reason')->all());
    }

    public function test_today_and_daily_dashboard_sales_and_payment_report_share_the_same_historical_source(): void
    {
        $order = $this->order('TODAY', 12501);
        $baseline = $this->event($order, 'legacy_baseline', 0, 12501, 0);
        DB::table('order_payment_events')->where('id', $baseline->id)->update(['created_at' => '2026-10-10 13:30:00']);
        $this->log($order, 0, 12501, '2026-10-10 13:00:00');
        $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'today']));
        $payments = app(PaymentCollectionReportService::class);
        $rows = $payments->events($filters);
        $summary = $payments->summary($rows);
        $dashboard = app(AdminOrderGroupService::class)->dashboardStats();
        $sales = app(SalesReportService::class)->report($filters, 1);
        $this->assertSame(12501, $summary['net_cents']);
        $this->assertSame($summary['net_cents'], $dashboard['today']['payments_cents']);
        $this->assertSame($summary['net_cents'], (int) collect($dashboard['last_seven_days'])->sum('payments_cents'));
        $this->assertSame($summary['net_cents'], (int) $payments->daily($rows, $filters)->sum('net_cents'));
        $this->assertSame($summary, $sales['collection_summary']);
        $this->get(route('admin.dashboard.index'))->assertOk()->assertSee('إجمالي المدفوع المسجل')->assertSee('١٢٥.٠١');
    }

    public function test_reconciliation_command_is_read_only_and_reports_opposing_differences_even_when_net_is_zero(): void
    {
        $higher = $this->order('HIGHER', 15000);
        $lower = $this->order('LOWER', 5000);
        $this->event($higher, 'payment_received', 0, 10000, 10000, true);
        $this->event($lower, 'payment_received', 0, 10000, 10000, true);
        $before = DB::table('order_payment_events')->get()->toJson();
        $this->artisan('payments:reconcile', ['--json' => true, '--limit' => 0])->assertSuccessful();
        $this->assertSame($before, DB::table('order_payment_events')->get()->toJson());
        $report = app(PaymentReconciliationService::class)->report();
        $this->assertSame(0, $report['unreconciled_cents']);
        $this->assertCount(2, $report['differences']);
        $this->get(route('admin.payment-report.index'))->assertOk()->assertSee('المطابقة غير مكتملة');
        $this->artisan('payments:reconcile', ['--limit' => 101])->assertExitCode(2);
    }

    public function test_unchanged_old_edit_snapshot_still_has_to_agree_with_the_payment_chain(): void
    {
        $order = $this->order('GAP-IN-SNAPSHOTS', 20000);
        $this->event($order, 'legacy_baseline', 0, 20000, 0);
        $this->log($order, 0, 10000, '2026-08-10 12:00:00');
        AdminActivityLog::create(['action' => 'checkout.discount_updated', 'properties' => [
            'checkout_group_key' => $order->checkout_group_key,
            'before' => ['paid_amount_cents' => 5000], 'after' => ['paid_amount_cents' => 5000]],
            'created_at' => '2026-08-11 12:00:00']);
        $this->log($order, 10000, 20000, '2026-08-12 12:00:00');
        $history = app(HistoricalPaymentSource::class)->history();
        $this->assertEmpty($history['events']);
        $this->assertSame(20000, $history['undated_cents']);
        $this->assertSame('incomplete_history', $history['issues']->first()['reason']);
    }

    public function test_historical_queries_are_batched_and_request_cached_not_one_per_checkout(): void
    {
        $order = $this->order('BASELINE-BATCH', 100);
        for ($i = 0; $i < 220; $i++) {
            OrderPaymentEvent::create(['event_uuid' => Str::uuid(), 'order_id' => $order->id,
                'checkout_group_key' => 'LEGACY-'.$i, 'event_type' => 'legacy_baseline', 'source' => 'legacy_baseline',
                'new_status' => 'partially_paid',
                'previous_paid_amount_cents' => 0, 'new_paid_amount_cents' => 100, 'amount_delta_cents' => 0,
                'affects_collection_stats' => false, 'occurred_at' => '2026-08-31 10:00:00', 'created_at' => '2026-08-31 10:00:00']);
        }
        DB::enableQueryLog();
        $source = app(HistoricalPaymentSource::class);
        $this->assertSame(22000, $source->history()['undated_cents']);
        $this->assertLessThanOrEqual(5, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $source->history();
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
