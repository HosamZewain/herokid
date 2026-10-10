<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderStatusDefinition;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCart;
use App\Services\Orders\AdminOrderReportService;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use App\Support\OrderStatusRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Tests\TestCase;

class ReportPerformanceTest extends TestCase
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

    private function request(array $filters = []): Request
    {
        $request = Request::create('/admin/order-report', 'GET', $filters);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    private function order(string $key, int $items = 30000, array $attributes = []): Order
    {
        $order = Order::create(array_replace([
            'order_number' => 'PERF-'.fake()->uuid(), 'checkout_group_key' => $key,
            'parent_name' => 'Synthetic Parent', 'child_name' => 'Synthetic Child', 'status' => 'new',
            'payment_status' => 'partially_paid', 'paid_amount_cents' => 10000,
            'delivery_details' => ['delivery_fee' => 50, 'country' => 'مصر', 'governorate' => 'القاهرة', 'phone' => '01000000000'],
            'uploaded_photos' => ['orders/synthetic-do-not-load.jpg'], 'notes' => str_repeat('Synthetic private note ', 100),
        ], $attributes));
        $order->items()->create(['item_type' => 'product', 'title' => 'Synthetic Product', 'quantity' => 1,
            'unit_price_cents' => $items, 'total_price_cents' => $items,
            'personalization_snapshot' => ['private' => str_repeat('Synthetic ', 100)]]);

        return $order;
    }

    private function variedFixture(): void
    {
        $story = Story::create(['title' => 'Synthetic Story', 'slug' => 'report-parity-story', 'price' => 250, 'gender' => 'both', 'language' => 'ar']);
        $this->order('MIXED', 33333, ['discount_cents' => 2222, 'order_source' => 'whatsapp']);
        $sibling = $this->order('MIXED', 12345, ['story_id' => $story->id, 'status' => 'cancelled']);
        $sibling->items()->update(['item_type' => 'story', 'story_id' => $story->id]);
        $this->order('FINISHED', 15000, ['status' => 'delivered', 'printing_status' => 'completed', 'shipping_status' => 'delivered', 'payment_status' => 'paid_in_full', 'paid_amount_cents' => 20000]);
        $this->order('OVERPAID', 10000, ['paid_amount_cents' => 25000, 'discount_cents' => 5000]);
        $old = $this->order('REPLACED', 88888, ['created_at' => now()->subDays(40)]);
        $old->delete();
        $this->order('REPLACED', 23456);
        $this->order('DELETED', 8765)->delete();
        $this->order('CANCELLED', 9999, ['status' => 'cancelled']);
        $legacy = $this->order('LEGACY', 0, ['story_id' => $story->id, 'delivery_details' => ['item_price' => 123.45, 'delivery_fee' => 0.55]]);
        $legacy->items()->delete();
        $this->order('ZERO', 0, ['discount_cents' => 30000, 'paid_amount_cents' => 0]);
        $this->order('UNKNOWN', 100, ['printing_status' => 'unknown', 'shipping_status' => 'unknown', 'payment_status' => 'unknown']);
    }

    public function test_sql_order_statistics_match_detailed_rows_for_filters_and_historical_edge_cases(): void
    {
        $this->variedFixture();
        $service = app(AdminOrderReportService::class);
        foreach ([[], ['lifecycle' => 'active'], ['lifecycle' => 'finished'], ['lifecycle' => 'cancelled'],
            ['catalog_type' => 'stories'], ['catalog_type' => 'products'], ['status' => 'new'], ['status' => 'mixed'],
            ['from' => '2026-10-01'], ['order_source' => 'whatsapp'], ['q' => 'REPLACED'],
            ['q' => 'no-matching-checkout'], ['printing_status' => 'completed']] as $filters) {
            $detailed = $service->report($this->request($filters));
            $optimized = $service->report($this->request($filters), paginate: true);
            $this->assertSame($detailed['summary'], $optimized['summary'], json_encode($filters));
            $this->assertSame($detailed['rows']->count(), $optimized['rows']->total());
            foreach ($detailed['breakdowns'] as $key => $breakdown) {
                $this->assertSame($breakdown->sortBy('label')->values()->toArray(), $optimized['breakdowns'][$key]->sortBy('label')->values()->toArray(), $key.' '.json_encode($filters));
            }
        }
    }

    public function test_money_remains_integer_cents_and_average_excludes_shipping_after_discount(): void
    {
        $this->order('A', 30000, ['discount_cents' => 5000, 'paid_amount_cents' => 10000]);
        $this->order('B', 10000, ['paid_amount_cents' => 20000]);
        $orders = app(AdminOrderReportService::class)->report($this->request(), true)['summary'];
        $this->assertSame(45000, $orders['total_cents']);
        $this->assertSame(17500, $orders['average_order_cents']);
        $this->assertSame(30000, $orders['paid_amount_cents']);
        $this->assertSame(20000, $orders['remaining_amount_cents']);
        $sales = app(SalesReportService::class)->report(SalesReportFilters::fromRequest($this->request()), 1)['summary'];
        $this->assertSame(300.0, $sales['total']); // Cash is not capped at the present order value.
        $this->assertSame(175.0, $sales['average_checkout']);
    }

    public function test_custom_cancelled_and_delivered_statuses_keep_mixed_lifecycle_semantics(): void
    {
        foreach (['cancelled', 'delivered'] as $behavior) {
            OrderStatusDefinition::create(['type' => 'order', 'key' => 'custom_'.$behavior, 'label_ar' => 'Synthetic '.$behavior,
                'behavior' => $behavior, 'color' => 'slate', 'sort_order' => 1, 'is_active' => true, 'is_system' => false]);
            $attributes = ['status' => $behavior, 'printing_status' => 'completed', 'shipping_status' => 'delivered', 'payment_status' => 'paid_in_full'];
            $this->order($behavior, 10000, $attributes);
            $this->order($behavior, 10000, ['status' => 'custom_'.$behavior] + $attributes);
        }
        OrderStatusRegistry::clearCache();
        $report = app(AdminOrderReportService::class)->report($this->request(), true);
        $this->assertSame(1, $report['summary']['cancelled_checkouts']);
        $this->assertSame(1, $report['summary']['finished_checkouts']);
    }

    public function test_order_report_loads_only_current_page_details_and_never_production_relations(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $order = $this->order('PAGE-'.$i);
            $order->items->first()->productionComponents()->create(['stable_key' => 'main', 'name' => 'Synthetic', 'prompt_template' => str_repeat('NOT_FOR_REPORT ', 500)]);
        }
        DB::enableQueryLog();
        $report = $this->get(route('admin.order-report.index', ['page' => 2]))->assertOk()->viewData('report');
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        $this->assertSame(60, $report['rows']->total());
        $this->assertCount(25, $report['rows']);
        $this->assertTrue($queries->contains(fn ($sql) => str_contains($sql, 'limit 25 offset 25')));
        foreach (['order_item_production_components', 'product_production_components', 'order_previews', 'order_attachments', 'order_scene_snapshots'] as $table) {
            $this->assertFalse($queries->contains(fn ($sql) => str_contains($sql, $table)), $table);
        }
        foreach ($report['rows'] as $row) {
            $order = $row['orders']->first();
            $this->assertArrayNotHasKey('uploaded_photos', $order->getAttributes());
            $this->assertArrayNotHasKey('notes', $order->getAttributes());
            $this->assertFalse($order->items->first()->relationLoaded('productionComponents'));
            $this->assertArrayNotHasKey('personalization_snapshot', $order->items->first()->getAttributes());
        }
    }

    public function test_sales_pagination_and_previous_period_summary_match_unpaginated_report(): void
    {
        $this->variedFixture();
        for ($i = 0; $i < 45; $i++) {
            $this->order('SALES-'.$i, 10000 + $i, ['created_at' => now()->subMinutes($i), 'status' => $i % 5 === 0 ? 'cancelled' : 'new']);
        }
        $service = app(SalesReportService::class);
        foreach ([[], ['type' => 'product'], ['min_total' => 150, 'max_total' => 400], ['sort' => 'highest'], ['source' => 'whatsapp'], ['q' => 'no-result']] as $query) {
            $filters = SalesReportFilters::fromRequest($this->request($query));
            $all = $service->report($filters);
            $page = $service->report($filters, 2);
            foreach (array_diff(array_keys($all), ['rows', 'options']) as $key) {
                $this->assertSame($all[$key], $page[$key], $key);
            }
            $this->assertSame($all['rows']->count(), $page['rows']->total());
            $this->assertSame($all['rows']->slice(25, 25)->values()->toJson(), $page['rows']->getCollection()->toJson());
        }
    }

    public function test_cart_lookup_preserves_first_matching_source_with_multiple_children_and_duplicate_carts(): void
    {
        $first = $this->order('SOURCE');
        $second = $this->order('SOURCE');
        foreach ([[$second, 'facebook'], [$first, 'instagram'], [$second, 'meta']] as [$order, $source]) {
            VisitorCart::create(['cart_identifier' => fake()->uuid(), 'related_order_id' => $order->id, 'status' => 'converted', 'utm_source' => $source, 'utm_medium' => 'paid_social']);
        }
        $carts = VisitorCart::whereIn('related_order_id', [$first->id, $second->id])->get(['related_order_id', 'utm_source', 'utm_medium', 'utm_campaign'])->keyBy('related_order_id');
        $expected = $carts->first(fn ($cart) => in_array($cart->related_order_id, [$first->id, $second->id], true));
        $rows = app(SalesReportService::class)->rows(SalesReportFilters::fromRequest($this->request()));
        $this->assertSame($expected->utm_source, $rows->first()['source_key']);
        $this->assertSame($expected->utm_source.' / paid_social', $rows->first()['source']);
        $this->assertSame(2, $rows->first()['order_records']);
    }

    public function test_sales_loads_compact_order_columns_without_private_media_or_prompts(): void
    {
        $this->order('COMPACT');
        DB::enableQueryLog();
        $this->get(route('admin.sales-report.index'))->assertOk();
        $sql = implode("\n", collect(DB::getQueryLog())->pluck('query')->all());
        DB::disableQueryLog();
        $this->assertStringNotContainsString('uploaded_photos', $sql);
        $this->assertStringNotContainsString('personalization_snapshot', $sql);
        $this->assertStringNotContainsString('production_prompt', $sql);
        $this->assertStringNotContainsString('select * from `orders`', $sql);
    }

    public function test_sales_tied_timestamps_have_stable_non_overlapping_pages_and_export_order(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $this->order('TIE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT));
        }
        $service = app(SalesReportService::class);
        foreach (['newest', 'oldest', 'highest', 'lowest'] as $sort) {
            $filters = SalesReportFilters::fromRequest($this->request(['sort' => $sort]));
            $first = $service->report($filters, 1)['rows']->pluck('key');
            $second = $service->report($filters, 2)['rows']->pluck('key');
            $export = $service->export($filters);
            $this->assertSame(35, $export['count']);
            $this->assertSame([], $first->intersect($second)->all());
            $this->assertSame($first->merge($second)->all(), $export['rows']->pluck('key')->all());
            $this->assertSame($first->all(), $service->report($filters, 1)['rows']->pluck('key')->all());
        }
    }

    public function test_batched_exports_match_full_rows_and_remain_lazy(): void
    {
        for ($i = 0; $i < 205; $i++) {
            $this->order('BATCH-'.$i, 10000 + $i, ['created_at' => now()->subMinutes($i)]);
        }
        $orders = app(AdminOrderReportService::class);
        $orderExport = $orders->export($this->request());
        $this->assertInstanceOf(LazyCollection::class, $orderExport['rows']);
        $this->assertSame(205, $orderExport['count']);
        $all = $orders->rows($this->request())->map(fn ($row) => [$row['key'], $row['total_cents'], $row['paid_amount_cents']])->all();
        $this->assertSame($all, $orderExport['rows']->map(fn ($row) => [$row['key'], $row['total_cents'], $row['paid_amount_cents']])->all());
        $sales = app(SalesReportService::class);
        $filters = SalesReportFilters::fromRequest($this->request());
        $saleExport = $sales->export($filters);
        $this->assertInstanceOf(LazyCollection::class, $saleExport['rows']);
        $this->assertSame($sales->rows($filters)->toJson(), $saleExport['rows']->values()->collect()->toJson());
    }

    public function test_reports_are_read_only_and_payment_changes_are_visible_immediately(): void
    {
        $order = $this->order('READ-ONLY');
        $before = DB::table('orders')->get()->toJson();
        $this->get(route('admin.order-report.index'))->assertOk();
        $this->get(route('admin.sales-report.index'))->assertOk();
        $this->assertSame($before, DB::table('orders')->get()->toJson());
        $this->assertDatabaseCount('order_group_assignments', 0);
        $order->update(['paid_amount_cents' => 12345]);
        $this->assertSame(12345, $this->get(route('admin.order-report.index'))->viewData('report')['summary']['paid_amount_cents']);
        $this->assertSame(100.0, $this->get(route('admin.sales-report.index'))->viewData('report')['summary']['total']); // A balance write is not a ledger payment.
        $order->update(['paid_amount_cents' => 10000]);
        $this->patch(route('admin.orders.groups.payment', $order->id), ['payment_status' => 'partially_paid', 'paid_amount' => 123.45, 'payment_method' => 'انستاباي'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(123.45, $this->get(route('admin.sales-report.index'))->viewData('report')['summary']['total']);
    }

    public function test_status_lookup_cache_invalidates_without_mutating_returned_collections(): void
    {
        $definitions = OrderStatusRegistry::definitions('order', false);
        $count = $definitions->count();
        $definitions->pop();
        $this->assertCount($count, OrderStatusRegistry::definitions('order', false));
        $status = OrderStatusDefinition::where('type', 'order')->where('key', 'new')->firstOrFail();
        $old = OrderStatusRegistry::label('order', 'new');
        $status->update(['label_ar' => 'Synthetic Updated Label']);
        OrderStatusRegistry::clearCache();
        $this->assertNotSame($old, OrderStatusRegistry::label('order', 'new'));
        $this->assertSame('Synthetic Updated Label', OrderStatusRegistry::label('order', 'new'));
    }
}
