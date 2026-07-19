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
            ->assertSee('ملاحظة محاسبية')
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

        $cancelled = $this->order('HK-CANCELLED', 'CANCELLED-GROUP', 'cancelled', null, 50, now()->subDay());
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

        $all = $this->actingAs($this->admin)->get(route('admin.sales-report.index', ['status' => 'all']))->viewData('report');
        $this->assertSame(2, $all['summary']['checkouts']);
        $this->assertSame(1048.0, $all['summary']['total']);

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

    private function multiOrderCheckout(): array
    {
        $first = $this->order('HK-FIRST', 'CHECKOUT-GROUP', 'delivered', null, 50, now());
        $second = $this->order('HK-SECOND', 'CHECKOUT-GROUP', 'shipped', null, 50, now());
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

    private function order(string $number, string $group, string $status, ?User $user, float $deliveryFee, Carbon $date): Order
    {
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
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }
}
