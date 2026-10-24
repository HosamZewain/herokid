<?php

namespace Tests\Feature;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCart;
use App\Services\Analytics\AnalyticsDateRange;
use App\Services\Analytics\Ga4AnalyticsClient;
use App\Services\Analytics\Ga4AnalyticsRepository;
use App\Services\Analytics\LocalCartAnalyticsRepository;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareReportService;
use App\Services\Orders\AdminOrderReportService;
use App\Services\Orders\OrderFinancialStatistics;
use App\Services\Sales\AdvertisingTargetingReportService;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Regression coverage for the production report audit; isolated fixtures only. */
class ReportAuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
    }

    public function test_cart_conversion_uses_the_same_creation_cohort(): void
    {
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'active', 'first_added_at' => now()]);
        foreach ([1, 2] as $n) {
            VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'converted',
                'first_added_at' => now()->subDays($n), 'converted_at' => now()]);
        }
        $card = app(LocalCartAnalyticsRepository::class)->todaySummary()['conversion_rate_today'];
        $this->assertEquals(0, $card['value']);
        $this->assertSame('local', $card['source']);
    }

    public function test_cart_filter_also_filters_summary(): void
    {
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'active']);
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'converted']);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/visitor-carts?status=active')->assertOk();
        $this->assertSame(1, $response->viewData('carts')->total());
        $this->assertSame(0, $response->viewData('summary')['converted']);
    }

    public function test_analytics_keeps_ga_channels_separate_from_local_utm_and_filters_cart_cohort(): void
    {
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'converted',
            'first_added_at' => now(), 'converted_at' => now(), 'utm_source' => 'meta', 'utm_medium' => 'paid_social']);
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'active', 'first_added_at' => now()->subDays(5)]);
        $client = \Mockery::mock(Ga4AnalyticsClient::class);
        $client->shouldReceive('isConfigured')->andReturn(true);
        $client->shouldReceive('propertyId')->andReturn('isolated-audit-property');
        $client->shouldReceive('runRealtimeReport')->andReturn(['rows' => []]);
        $client->shouldReceive('runReport')->andReturnUsing(fn ($request) => data_get($request, 'dimensions.0.name') === 'sessionDefaultChannelGroup'
            ? ['rows' => [['dimensionValues' => [['value' => 'Paid Social']], 'metricValues' => [['value' => '3'], ['value' => '4']]]]]
            : ['rows' => []]);
        $this->app->instance(Ga4AnalyticsClient::class, $client);
        $range = new AnalyticsDateRange('custom', 'Test', '2026-10-24', '2026-10-24');
        $report = app(Ga4AnalyticsRepository::class)->dashboard($range);
        $this->assertSame([['source' => 'Paid Social', 'users' => 3, 'sessions' => 4]], $report['sources']);
        $this->assertSame(['meta / paid_social' => 1], $report['local_source_conversions']);
        $this->assertSame(0, $report['local_cart_summary']['active_carts']);
        $this->assertSame(1, $report['local_cart_summary']['converted_carts']);
    }

    public function test_share_table_excludes_activity_outside_the_period_without_mutating_counters(): void
    {
        $share = $this->share();
        $share->forceFill(['created_at' => '2026-10-01 12:00:00', 'total_views' => 5])->save();
        $share->events()->create(['event_type' => 'share.page_viewed', 'occurred_at' => '2026-10-02 12:00:00']);
        $report = app(ChildIdentityShareReportService::class)->build('2026-10-01', '2026-10-01');
        $this->assertSame(0, $report['summary']['views']);
        $this->assertCount(0, $report['top_shares']);
        $this->assertSame(5, $share->fresh()->total_views);
    }

    public function test_share_day_uses_cairo_and_includes_older_shares(): void
    {
        $share = $this->share();
        // This is 00:30 Cairo on October 24.
        $share->events()->create(['event_type' => 'share.page_viewed', 'occurred_at' => '2026-10-23 21:30:00']);
        $report = app(ChildIdentityShareReportService::class)->build('2026-10-24', '2026-10-24');
        $this->assertSame(1, $report['summary']['views']);
        $this->assertSame(1, $report['top_shares']->first()->period_views);
    }

    public function test_referred_value_uses_financial_items_not_stale_delivery_snapshot(): void
    {
        $share = $this->share();
        $order = $this->order('AUDIT-REFERRAL');
        $share->events()->create(['event_type' => 'share.order_created', 'referred_order_id' => $order->id, 'occurred_at' => now()]);
        $report = app(ChildIdentityShareReportService::class)->build('2026-10-01', '2026-10-24');
        $actual = app(OrderFinancialStatistics::class)->summarize(collect([$order->checkoutGroupKey()]), true);
        $this->assertSame(700.0, $report['summary']['revenue']);
        $this->assertSame(0.0, $report['summary']['paid_balance']);
        $this->assertSame(70000, $actual['total_value_cents']);
        $this->assertSame(0, (int) $order->paid_amount_cents);
    }

    public function test_deleted_order_is_in_both_order_and_sales_reports_once(): void
    {
        $order = $this->order('AUDIT-DELETED');
        $order->delete();
        $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'this_month']));
        $sales = app(SalesReportService::class)->rows($filters);
        $request = Request::create('/', 'GET', [
            'date_from' => '2026-10-01', 'date_to' => '2026-10-24', 'lifecycle' => 'all', 'catalog_type' => 'all',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $request->setUserResolver(fn () => $admin);
        $orders = app(AdminOrderReportService::class)->report($request, true);
        $this->assertSame(1, $sales->count());
        $this->assertTrue($sales->first()['cancelled']);
        $this->assertSame($orders['summary']['total_cents'], $sales->sum('total_cents'));
        $this->assertSame($orders['summary']['paid_amount_cents'], $sales->sum('paid_amount_cents'));
        $this->assertSame(1, $orders['summary']['checkouts']);
    }

    public function test_funnel_counts_distinct_carts_from_one_cohort_not_events_or_admin_orders(): void
    {
        $cart = VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'converted', 'first_added_at' => now(), 'converted_at' => now()]);
        foreach (range(1, 4) as $i) {
            $cart->activities()->create(['type' => 'item_added', 'created_at' => now()]);
        }
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'converted', 'first_added_at' => now()->subDays(3), 'converted_at' => now()]);
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'active', 'first_added_at' => now(), 'checkout_started_at' => now()]);
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'active', 'first_added_at' => now()]);
        $this->order('ADMIN-NOT-CART');
        $range = new AnalyticsDateRange('custom', 'Test', '2026-10-24', '2026-10-24');
        $local = app(LocalCartAnalyticsRepository::class);
        $funnel = $local->funnel($range);
        $this->assertSame([3, 2, 1], array_column($funnel, 'value'));
        $this->assertEquals([null, 33.3, 50], array_column($funnel, 'drop_off'));
        $this->assertEquals(33.33, $local->todaySummary()['conversion_rate_today']['value']);
        $this->assertSame(1, $local->cartSummary($range)['converted_carts']);
    }

    public function test_cairo_date_and_status_filters_apply_to_cart_cards_and_table(): void
    {
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'abandoned', 'first_added_at' => '2026-10-23 21:30:00', 'cart_total_cents' => 12345]);
        VisitorCart::create(['cart_identifier' => Str::uuid(), 'status' => 'abandoned', 'first_added_at' => '2026-10-23 20:30:00', 'cart_total_cents' => 99999]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/admin/visitor-carts?status=abandoned&date_from=2026-10-24&date_to=2026-10-24')->assertOk();
        $this->assertSame(1, $response->viewData('carts')->total());
        $this->assertSame(123.45, $response->viewData('summary')['abandoned_value']);
        $this->assertSame(1, $response->viewData('summary')['abandoned']);
    }

    public function test_share_ctr_is_same_viewer_subset_and_period_table_matches_events(): void
    {
        $share = $this->share();
        $share->forceFill(['created_at' => now()->subMonth(), 'total_views' => 999])->save();
        foreach ([['share.page_viewed', 'a'], ['share.page_viewed', 'b'], ['share.cta_clicked', 'a'], ['share.cta_clicked', 'a'], ['share.cta_clicked', 'no-view'], ['share.cta_clicked', null]] as [$type, $visitor]) {
            $share->events()->create(['event_type' => $type, 'anonymous_visitor_id' => $visitor, 'occurred_at' => now()]);
        }
        $report = app(ChildIdentityShareReportService::class)->build('2026-10-24', '2026-10-24');
        $this->assertSame(0, $report['summary']['shares']);
        $this->assertEquals(50, $report['summary']['ctr']);
        $this->assertSame(2, $report['summary']['views']);
        $this->assertSame(4, $report['summary']['cta_clicks']);
        $this->assertSame(2, $report['top_shares']->first()->period_views);
        $this->assertSame(4, $report['top_shares']->first()->period_clicks);
        $this->assertSame(999, $share->fresh()->total_views);
    }

    public function test_paid_in_full_means_positive_balance_covering_non_cancelled_value_in_both_reports(): void
    {
        $paid = $this->order('PAID');
        $paid->update(['paid_amount_cents' => 70000, 'payment_status' => 'partially_paid']); // Stale saved state must not override the balance.
        $zero = $this->order('ZERO');
        $zero->items()->update(['unit_price_cents' => 0, 'total_price_cents' => 0]);
        $zero->update(['payment_status' => 'paid_in_full']);
        $cancelled = $this->order('CANCELLED');
        $cancelled->update(['status' => 'cancelled', 'paid_amount_cents' => 70000, 'payment_status' => 'paid_in_full']);
        $admin = User::factory()->create(['role' => 'admin']);
        $request = Request::create('/', 'GET', ['lifecycle' => 'all', 'catalog_type' => 'all']);
        $request->setUserResolver(fn () => $admin);
        $before = DB::table('orders')->get()->toJson();
        $sales = app(SalesReportService::class)->report(SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'today'])));
        $orders = app(AdminOrderReportService::class)->report($request, true);
        $financial = app(OrderFinancialStatistics::class)->summarize(Order::query()->pluck('checkout_group_key'), true);
        $this->assertSame(1, $orders['summary']['fully_paid_checkouts']);
        $this->assertSame(1, $sales['operational_summary']['fully_paid_checkouts']);
        $this->assertSame(1, $financial['paid_checkouts']);
        $salesRows = app(SalesReportService::class)->rows(SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'today'])));
        $this->assertSame('رصيد مدفوع حالي', $salesRows->firstWhere('key', 'PAID')['sales_classification_label']);
        $this->assertSame($before, DB::table('orders')->get()->toJson());
    }

    public function test_sales_prefers_live_siblings_and_keeps_empty_retained_checkouts(): void
    {
        $old = $this->order('REPLACED-OLD');
        $live = $this->order('REPLACED-LIVE');
        $old->update(['checkout_group_key' => $live->checkout_group_key]);
        $old->delete();
        Order::create(['order_number' => 'EMPTY', 'checkout_group_key' => 'EMPTY', 'paid_amount_cents' => 123,
            'delivery_details' => ['delivery_fee' => 15], 'status' => 'new']);
        $rows = app(SalesReportService::class)->rows(SalesReportFilters::fromRequest(Request::create('/', 'GET', ['range' => 'today'])));
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows->firstWhere('key', $live->checkout_group_key)['items_quantity']);
        $this->assertSame(0, $rows->firstWhere('key', 'EMPTY')['items_quantity']);
        $this->assertSame(1500, $rows->firstWhere('key', 'EMPTY')['total_cents']);
        $this->assertSame(123, $rows->firstWhere('key', 'EMPTY')['paid_amount_cents']);
    }

    public function test_order_report_exclusive_types_match_cards_tables_and_export_without_deleted_sibling_leakage(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $story = $this->order('TYPE-STORY');
        $mixed = $this->order('TYPE-MIXED');
        $mixed->items()->create(['item_type' => 'product', 'title' => 'Test product', 'quantity' => 2,
            'unit_price_cents' => 10000, 'total_price_cents' => 20000]);
        $product = $this->order('TYPE-PRODUCT');
        $product->update(['story_id' => null]);
        $product->items()->update(['item_type' => 'product', 'story_id' => null]);
        $superseded = $this->order('TYPE-OLD-STORY');
        $superseded->update(['checkout_group_key' => $product->checkout_group_key]);
        $superseded->delete();
        Order::create(['order_number' => 'TYPE-UNKNOWN', 'checkout_group_key' => 'TYPE-UNKNOWN', 'status' => 'new']);
        $cases = ['stories_only' => [$story->checkout_group_key], 'products' => [$product->checkout_group_key],
            'mixed' => [$mixed->checkout_group_key], 'unclassified' => ['TYPE-UNKNOWN'],
            'stories' => [$story->checkout_group_key, $mixed->checkout_group_key]];
        foreach ($cases as $type => $keys) {
            $query = ['catalog_type' => $type, 'lifecycle' => 'all'];
            $response = $this->get(route('admin.order-report.index', $query))->assertOk();
            $report = $response->viewData('report');
            $this->assertSame(count($keys), $report['summary']['checkouts'], $type);
            $this->assertSame(count($keys), $report['rows']->total(), $type);
            $request = Request::create('/', 'GET', $query);
            $request->setUserResolver(fn () => $admin);
            $export = app(AdminOrderReportService::class)->export($request);
            $this->assertCount(count($keys), $export['rows'], $type);
        }
    }

    public function test_payment_navigation_forms_details_exports_keep_normalized_filters_and_payments_unchanged(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $order = $this->order('FILTER-PAID');
        $order->update(['payment_status' => 'paid_in_full', 'paid_amount_cents' => 70000]);
        foreach ([25000, -1000] as $amount) {
            OrderPaymentEvent::create(['event_uuid' => Str::uuid(), 'checkout_group_key' => $order->checkout_group_key,
                'order_id' => $order->id, 'event_type' => 'payment_received', 'source' => 'audit_test', 'new_status' => 'partially_paid',
                'previous_paid_amount_cents' => 0, 'new_paid_amount_cents' => max(0, $amount), 'amount_delta_cents' => $amount,
                'affects_collection_stats' => true, 'occurred_at' => now()]);
        }
        $query = ['range' => 'custom', 'start_date' => '2026-10-24', 'end_date' => '2026-10-24', 'type' => 'story',
            'item' => 'story:'.$order->story_id, 'payment_status' => 'paid_in_full', 'q' => 'FILTER-PAID', 'min_total' => 0];
        $before = DB::table('order_payment_events')->get()->toJson();
        $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', $query));
        $sales = $this->get(route('admin.sales-report.index', $query))->assertOk();
        $sales->assertSee(route('admin.payment-report.index', $filters->query()));
        $payments = $this->get(route('admin.payment-report.index', $query + ['day' => '2026-10-24']))->assertOk();
        $this->assertSame(24000, $payments->viewData('summary')['net_cents']);
        $this->assertSame(24000, $payments->viewData('selectedSummary')['net_cents']);
        $this->assertSame(24000, $payments->viewData('daily')->sum('net_cents'));
        $this->assertSame(240.0, $sales->viewData('report')['summary']['total']);
        foreach ($filters->selectionQuery() as $name => $value) {
            $payments->assertSee('name="'.$name.'" value="'.e($value).'"', false);
        }
        $this->get(route('admin.payment-report.index', $filters->query()))->assertOk()->assertViewHas('summary', fn ($s) => $s['net_cents'] === 24000);
        $export = $this->get(route('admin.payment-report.export', $filters->query()))->assertOk()->streamedContent();
        $this->assertStringContainsString('250.00', $export);
        $this->assertStringContainsString('-10.00', $export);
        $this->assertSame($before, DB::table('order_payment_events')->get()->toJson());
    }

    public function test_negative_collection_chart_has_a_signed_zero_axis_and_zero_is_not_a_positive_bar(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $order = $this->order('NEGATIVE');
        OrderPaymentEvent::create(['event_uuid' => Str::uuid(), 'checkout_group_key' => $order->checkout_group_key,
            'order_id' => $order->id, 'event_type' => 'payment_reversed', 'source' => 'audit_test', 'new_status' => 'unpaid',
            'previous_paid_amount_cents' => 5000, 'new_paid_amount_cents' => 0, 'amount_delta_cents' => -5000,
            'affects_collection_stats' => true, 'occurred_at' => now()]);
        $response = $this->get(route('admin.sales-report.index', ['range' => 'today']))->assertOk();
        $response->assertSee('خط المنتصف = صفر')->assertSee('rounded-b-xl bg-rose-600');
        $this->assertSame(-50.0, $response->viewData('report')['trend'][0]['total']);
    }

    public function test_advertising_parent_product_label_does_not_misattribute_all_variants_to_one_variant(): void
    {
        $product = Product::create(['name_ar' => 'كتاب تلوين', 'slug' => 'audit-coloring', 'price_cents' => 10000]);
        foreach ([20, 40] as $pages) {
            $order = $this->order('VARIANT-'.$pages);
            $order->update(['story_id' => null]);
            $order->items()->update(['item_type' => 'product', 'story_id' => null, 'product_id' => $product->id, 'title' => 'كتاب '.$pages.' صفحة']);
        }
        $request = Request::create('/', 'GET', ['range' => 'today']);
        $report = app(AdvertisingTargetingReportService::class)->report($request, true);
        $this->assertCount(1, $report['items']);
        $this->assertSame('كتاب تلوين — جميع النسخ', $report['items']->first()['title']);
        $this->assertSame(2, $report['items']->first()['quantity']);
        $this->assertSame(140000, $report['items']->sum('net_cents'));
    }

    private function order(string $key): Order
    {
        $story = Story::create(['title' => 'Audit Story', 'slug' => Str::slug($key), 'price' => 700, 'active' => true]);
        $order = Order::create(['order_number' => $key, 'checkout_group_key' => $key, 'story_id' => $story->id,
            'status' => 'new', 'payment_status' => 'unpaid', 'paid_amount_cents' => 0,
            'uploaded_photos' => [], 'delivery_details' => ['total' => 500, 'delivery_fee' => 0]]);
        $order->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => 'Audit Story',
            'quantity' => 1, 'unit_price_cents' => 70000, 'total_price_cents' => 70000]);

        return $order;
    }

    private function share(): ChildIdentityShare
    {
        $identity = ChildIdentityRequest::create([
            'uuid' => Str::uuid(), 'resume_token_hash' => hash('sha256', Str::random()),
            'parent_name' => 'Audit Parent', 'parent_phone' => '0000000000', 'child_name' => 'Audit Child',
            'child_age' => 5, 'age_range' => '3-6', 'consent_accepted_at' => now(), 'consent_version' => 'audit',
        ]);
        $attempt = $identity->attempts()->create(['attempt_number' => 1, 'idempotency_key' => Str::uuid(),
            'model' => 'audit-no-network', 'prompt_version' => 'audit', 'prompt_snapshot' => 'audit',
            'prompt_hash' => hash('sha256', 'audit'), 'input_photos_count' => 0,
            'image_size' => '1x1', 'image_quality' => 'test']);

        return ChildIdentityShare::create(['child_identity_request_id' => $identity->id,
            'generation_attempt_id' => $attempt->id, 'public_token' => Str::random(64),
            'consent_accepted_at' => now(), 'consent_version' => 'audit', 'created_by_type' => 'customer',
            'template_version' => 'audit', 'caption_snapshot' => '', 'hashtags_snapshot' => '']);
    }
}
