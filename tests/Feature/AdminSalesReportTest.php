<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DeliveryCountry $country;

    private DeliveryGovernorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-20 12:00:00');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->country = DeliveryCountry::query()->where('code', 'EG')->firstOrFail();
        $this->governorate = DeliveryGovernorate::query()
            ->where('delivery_country_id', $this->country->id)
            ->where('name', 'القاهرة')
            ->firstOrCreate([
                'name' => 'القاهرة',
            ], [
                'delivery_fee' => 50,
                'active' => true,
            ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sales_report_groups_checkout_and_counts_delivery_once(): void
    {
        [$first, $second] = $this->multiOrderCheckout();

        $response = $this->actingAs($this->admin)->get(route('admin.sales-report.index'));

        $response->assertOk()
            ->assertSee('تقرير المبيعات')
            ->assertSee('قاعدة احتساب المبيعات')
            ->assertSee($first->order_number)
            ->assertSee($second->order_number);

        $summary = $response->viewData('report')['summary'];
        $this->assertSame(798.0, $summary['total']);
        $this->assertSame(748.0, $summary['items_sales']);
        $this->assertSame(50.0, $summary['delivery']);
        $this->assertSame(1, $summary['checkouts']);
        $this->assertSame(2, $summary['order_records']);
        $this->assertSame(3, $summary['items_quantity']);
        $this->assertSame(798.0, $summary['average_checkout']);
    }

    public function test_report_filters_status_type_customer_location_source_amount_and_search(): void
    {
        [$first] = $this->multiOrderCheckout();
        VisitorCart::create([
            'cart_identifier' => fake()->uuid(),
            'status' => 'converted',
            'currency' => 'EGP',
            'item_count' => 3,
            'items_subtotal_cents' => 74800,
            'cart_total_cents' => 74800,
            'related_order_id' => $first->id,
            'utm_source' => 'facebook',
            'utm_medium' => 'paid_social',
            'utm_campaign' => 'summer',
            'converted_at' => now(),
            'last_activity_at' => now(),
        ]);

        $cancelled = $this->order('HK-CANCELLED', 'CANCELLED-GROUP', 'cancelled', null, 50, now()->subDay(), 'unpaid');
        $cancelled->items()->create([
            'item_type' => 'story',
            'story_id' => $cancelled->story_id,
            'title' => 'قصة ملغاة',
            'unit_price_cents' => 20000,
            'quantity' => 1,
            'total_price_cents' => 20000,
        ]);

        $default = $this->actingAs($this->admin)->get(route('admin.sales-report.index'))->viewData('report');
        $this->assertSame(1, $default['summary']['checkouts']);
        $this->assertSame(2, $default['operational_summary']['all_checkouts']);
        $this->assertSame(1, $default['operational_summary']['cancelled_checkouts']);

        $all = $this->actingAs($this->admin)->get(route('admin.sales-report.index', ['status' => 'all']))->viewData('report');
        $this->assertSame(1, $all['summary']['checkouts']);
        $this->assertSame(798.0, $all['summary']['total']);

        $addon = $this->actingAs($this->admin)->get(route('admin.sales-report.index', [
            'type' => 'product_add_on',
            'customer_type' => 'guest',
            'country_id' => $this->country->id,
            'governorate_id' => $this->governorate->id,
            'source' => 'facebook',
            'min_total' => 100,
            'max_total' => 100,
            'q' => 'ملصق',
        ]))->viewData('report');

        $this->assertSame(1, $addon['summary']['checkouts']);
        $this->assertSame(50.0, $addon['summary']['items_sales']);
        $this->assertSame(100.0, $addon['summary']['total']);
        $this->assertSame('facebook / paid_social', $addon['rows']->first()['source']);
    }

    public function test_report_uses_immutable_item_snapshots_and_supports_specific_item_filter(): void
    {
        [$first] = $this->multiOrderCheckout();
        $product = $first->items()->where('item_type', 'product_add_on')->firstOrFail()->product;
        $product->update(['name_ar' => 'اسم تغير لاحقاً', 'sale_price_cents' => 9900]);

        $report = $this->actingAs($this->admin)->get(route('admin.sales-report.index', [
            'item' => 'product:'.$product->id,
        ]))->viewData('report');

        $this->assertSame(50.0, $report['summary']['items_sales']);
        $this->assertSame('ملصق باسم الطفل', $report['top_items'][0]['title']);
    }

    public function test_csv_export_contains_one_row_per_checkout_and_is_private(): void
    {
        [$first, $second] = $this->multiOrderCheckout();

        $response = $this->actingAs($this->admin)->get(route('admin.sales-report.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('مجموعة الشراء', $csv);
        $this->assertStringContainsString('محتسب في المبيعات', $csv);
        $this->assertStringContainsString('مدفوع — المبلغ المحصل محتسب', $csv);
        $this->assertSame(1, substr_count($csv, 'CHECKOUT-GROUP'));
        $this->assertStringContainsString($first->order_number, $csv);
        $this->assertStringContainsString($second->order_number, $csv);
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'sales_report.exported',
        ]);
    }

    public function test_sales_report_requires_dedicated_permission_and_navigation_respects_it(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');

        $this->actingAs($limited)
            ->get(route('admin.sales-report.index'))
            ->assertForbidden();

        $this->actingAs($limited)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertDontSee('تقرير المبيعات');

        $this->actingAs($this->admin)
            ->get(route('admin.sales-report.index'))
            ->assertOk()
            ->assertSee('تقرير المبيعات');
    }

    public function test_previous_period_comparison_and_daily_zero_fill_are_calculated(): void
    {
        $current = $this->order('HK-CURRENT', 'CURRENT', 'delivered', null, 0, now());
        $current->items()->create([
            'item_type' => 'story',
            'story_id' => $current->story_id,
            'title' => 'قصة اليوم',
            'unit_price_cents' => 20000,
            'quantity' => 1,
            'total_price_cents' => 20000,
        ]);

        $previous = $this->order('HK-PREVIOUS', 'PREVIOUS', 'delivered', null, 0, now()->subDay());
        $previous->items()->create([
            'item_type' => 'story',
            'story_id' => $previous->story_id,
            'title' => 'قصة أمس',
            'unit_price_cents' => 10000,
            'quantity' => 1,
            'total_price_cents' => 10000,
        ]);

        $report = $this->actingAs($this->admin)->get(route('admin.sales-report.index', [
            'range' => 'today',
            'group_by' => 'day',
        ]))->viewData('report');

        $this->assertSame(100.0, $report['comparison']['total']);
        $this->assertCount(1, $report['trend']);
        $this->assertSame(200.0, $report['trend'][0]['total']);
    }

    public function test_every_non_cancelled_collected_amount_is_recognized_without_waiting_for_delivery(): void
    {
        $cases = [
            ['HK-RECOGNIZED', 'RECOGNIZED', 'delivered', 'paid_in_full', 20_000],
            ['HK-UNPAID', 'UNPAID', 'delivered', 'unpaid', 30_000],
            ['HK-PARTIAL', 'PARTIAL', 'delivered', 'partially_paid', 40_000],
            ['HK-NOT-DELIVERED', 'NOT-DELIVERED', 'shipped', 'paid_in_full', 50_000],
            ['HK-CANCELLED-2', 'CANCELLED-2', 'cancelled', 'unpaid', 60_000],
        ];

        foreach ($cases as [$number, $group, $status, $paymentStatus, $amount]) {
            $order = $this->order(
                $number,
                $group,
                $status,
                null,
                0,
                now(),
                $paymentStatus,
                $paymentStatus === 'partially_paid' ? 10_000 : null,
            );
            $order->items()->create([
                'item_type' => 'story',
                'story_id' => $order->story_id,
                'title' => 'قصة '.$number,
                'unit_price_cents' => $amount,
                'quantity' => 1,
                'total_price_cents' => $amount,
            ]);
        }

        foreach ([['HK-MIXED-DELIVERED', 'delivered', 70_000], ['HK-MIXED-SHIPPED', 'shipped', 80_000]] as [$number, $status, $amount]) {
            $order = $this->order($number, 'MIXED-CHECKOUT', $status, null, 0, now());
            $order->items()->create([
                'item_type' => 'story',
                'story_id' => $order->story_id,
                'title' => 'قصة '.$number,
                'unit_price_cents' => $amount,
                'quantity' => 1,
                'total_price_cents' => $amount,
            ]);
        }

        $report = $this->actingAs($this->admin)->get(route('admin.sales-report.index', [
            'range' => 'today',
        ]))->viewData('report');

        $this->assertSame(2300.0, $report['summary']['total']);
        $this->assertSame(4, $report['summary']['checkouts']);
        $this->assertSame(6, $report['operational_summary']['all_checkouts']);
        $this->assertSame(4, $report['operational_summary']['paid_checkouts']);
        $this->assertSame(2300.0, $report['operational_summary']['paid_amount']);
        $this->assertSame(2, $report['operational_summary']['unrecognized_checkouts']);
        $this->assertSame(1, $report['operational_summary']['cancelled_checkouts']);
        $this->assertSame(600.0, $report['operational_summary']['cancelled_value']);
        $this->assertSame(1, $report['operational_summary']['unpaid_checkouts']);
        $this->assertSame(300.0, $report['operational_summary']['unpaid_value']);
        $this->assertSame(1, $report['operational_summary']['partially_paid_checkouts']);
        $this->assertSame(100.0, $report['operational_summary']['partially_paid_amount']);
        $this->assertSame(2, $report['operational_summary']['fully_paid_not_delivered_checkouts']);

        $unpaid = $this->actingAs($this->admin)->get(route('admin.sales-report.index', [
            'range' => 'today',
            'payment_status' => 'unpaid',
        ]))->viewData('report');

        $this->assertSame(0.0, $unpaid['summary']['total']);
        $this->assertSame(2, $unpaid['operational_summary']['all_checkouts']);

        $delivered = $this->actingAs($this->admin)->get(route('admin.sales-report.index', [
            'range' => 'today',
            'status' => 'delivered',
        ]))->viewData('report');

        $this->assertSame(1000.0, $delivered['summary']['total']);
        $this->assertTrue($delivered['rows']->firstWhere('key', 'MIXED-CHECKOUT')['sale_recognized']);
    }

    public function test_report_uses_paid_amount_after_discount_and_excludes_cancelled_collections(): void
    {
        $discounted = $this->order(
            'HK-DISCOUNTED-PAID',
            'DISCOUNTED-PAID',
            'new',
            null,
            0,
            now(),
            'paid_in_full',
            20_000,
        );
        $discounted->update(['discount_cents' => 20_000]);
        $discounted->items()->create([
            'item_type' => 'story',
            'story_id' => $discounted->story_id,
            'title' => 'قصة بعد الخصم',
            'unit_price_cents' => 40_000,
            'quantity' => 1,
            'total_price_cents' => 40_000,
        ]);

        $cancelled = $this->order(
            'HK-CANCELLED-PAID',
            'CANCELLED-PAID',
            'cancelled',
            null,
            0,
            now(),
            'paid_in_full',
            10_000,
        );
        $cancelled->items()->create([
            'item_type' => 'story',
            'story_id' => $cancelled->story_id,
            'title' => 'قصة ملغاة مدفوعة',
            'unit_price_cents' => 10_000,
            'quantity' => 1,
            'total_price_cents' => 10_000,
        ]);

        $report = $this->actingAs($this->admin)->get(route('admin.sales-report.index', [
            'range' => 'today',
        ]))->viewData('report');

        $this->assertSame(200.0, $report['summary']['total']);
        $this->assertSame(200.0, $report['summary']['order_value']);
        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame(1, $report['operational_summary']['paid_checkouts']);
        $this->assertSame(200.0, $report['operational_summary']['paid_amount']);
        $this->assertSame(1, $report['operational_summary']['cancelled_checkouts']);
        $this->assertSame(100.0, $report['operational_summary']['cancelled_value']);
        $this->assertSame(100.0, $report['operational_summary']['cancelled_paid_amount']);
        $this->assertTrue($report['rows']->firstWhere('key', 'DISCOUNTED-PAID')['sale_recognized']);
        $this->assertFalse($report['rows']->firstWhere('key', 'CANCELLED-PAID')['sale_recognized']);
    }

    private function multiOrderCheckout(): array
    {
        $first = $this->order('HK-FIRST', 'CHECKOUT-GROUP', 'delivered', null, 50, now());
        $second = $this->order('HK-SECOND', 'CHECKOUT-GROUP', 'delivered', null, 50, now());
        $product = Product::create([
            'name_ar' => 'ملصق',
            'slug' => 'sales-report-poster',
            'price_cents' => 5000,
            'is_active' => true,
        ]);

        $first->items()->create([
            'item_type' => 'story',
            'story_id' => $first->story_id,
            'title' => 'مغامرة رنا',
            'unit_price_cents' => 29900,
            'quantity' => 1,
            'total_price_cents' => 29900,
        ]);
        $first->items()->create([
            'item_type' => 'product_add_on',
            'product_id' => $product->id,
            'title' => 'ملصق باسم الطفل',
            'sku' => 'POSTER-1',
            'unit_price_cents' => 5000,
            'quantity' => 1,
            'total_price_cents' => 5000,
        ]);
        $second->items()->create([
            'item_type' => 'story',
            'story_id' => $second->story_id,
            'title' => 'رحلة آدم',
            'unit_price_cents' => 39900,
            'quantity' => 1,
            'total_price_cents' => 39900,
        ]);

        return [$first, $second];
    }

    private function order(
        string $number,
        string $group,
        string $status,
        ?User $user,
        float $deliveryFee,
        Carbon $date,
        string $paymentStatus = 'paid_in_full',
        ?int $paidAmountCents = null,
    ): Order {
        $story = Story::create([
            'title' => 'قصة '.$number,
            'slug' => strtolower($number),
            'language' => 'ar',
            'price' => 299,
            'active' => true,
        ]);

        return Order::create([
            'order_number' => $number,
            'user_id' => $user?->id,
            'parent_name' => 'والدة رنا',
            'story_id' => $story->id,
            'child_name' => 'رنا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => [
                'phone' => '201000000000',
                'delivery_country_id' => $this->country->id,
                'delivery_governorate_id' => $this->governorate->id,
                'country' => $this->country->name,
                'governorate' => $this->governorate->name,
                'city' => 'مدينة نصر',
                'checkout_group' => $group,
                'delivery_fee' => $deliveryFee,
            ],
            'uploaded_photos' => [],
            'status' => $status,
            'payment_status' => $paymentStatus,
            'paid_amount_cents' => $paidAmountCents ?? ($paymentStatus === 'paid_in_full' ? 999_999 : 0),
            'payment_method' => $paymentStatus === 'unpaid' ? null : 'انستاباي',
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }
}
