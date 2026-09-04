<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Support\OrderDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_report_groups_checkouts_and_calculates_actual_paid_cancelled_and_remaining_amounts(): void
    {
        $product = Product::create([
            'name_ar' => 'ستيكر مدرسي',
            'slug' => 'report-sticker',
            'price_cents' => 20_000,
            'is_active' => true,
        ]);
        $productOrder = $this->order('REPORT-PRODUCT', 'new', 'whatsapp', 'partially_paid', 15_000, 5_000);
        $productOrder->update(['discount_cents' => 5_000, 'payment_method' => 'انستاباي']);
        $productOrder->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 20_000,
            'quantity' => 2,
            'total_price_cents' => 40_000,
        ]);

        $story = Story::create([
            'title' => 'قصة ملغاة',
            'slug' => 'cancelled-report-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 300,
            'active' => true,
        ]);
        $storyOrder = $this->order('REPORT-STORY', 'cancelled', 'phone', 'unpaid', 0, 5_000, $story);
        $storyOrder->items()->create([
            'item_type' => 'story',
            'story_id' => $story->id,
            'title' => $story->title,
            'unit_price_cents' => 30_000,
            'quantity' => 1,
            'total_price_cents' => 30_000,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.order-report.index'));

        $response->assertOk()
            ->assertSee('تقرير الطلبات')
            ->assertSee('المدفوع فعليًا')
            ->assertSee('ستيكر مدرسي')
            ->assertSee('قصة ملغاة');

        $summary = $response->viewData('report')['summary'];
        $this->assertSame(2, $summary['checkouts']);
        $this->assertSame(75_000, $summary['total_cents']);
        $this->assertSame(15_000, $summary['paid_amount_cents']);
        $this->assertSame(60_000, $summary['remaining_amount_cents']);
        $this->assertSame(1, $summary['cancelled_checkouts']);
        $this->assertSame(35_000, $summary['cancelled_value_cents']);
        $this->assertSame(2, $summary['products']);
        $this->assertSame(1, $summary['stories']);
    }

    public function test_report_filters_type_source_status_payment_method_and_dates(): void
    {
        $product = Product::create([
            'name_ar' => 'منتج التقرير',
            'slug' => 'filtered-report-product',
            'price_cents' => 10_000,
            'is_active' => true,
        ]);
        $included = $this->order('FILTER-IN', 'new', 'whatsapp', 'partially_paid', 5_000, 0);
        $included->update(['payment_method' => 'انستاباي']);
        $included->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 10_000,
            'quantity' => 1,
            'total_price_cents' => 10_000,
        ]);

        $excluded = $this->order('FILTER-OUT', 'new', 'phone', 'unpaid', 0, 0);
        $excluded->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => 'منتج آخر',
            'unit_price_cents' => 8_000,
            'quantity' => 1,
            'total_price_cents' => 8_000,
        ]);

        $report = $this->actingAs($this->admin)->get(route('admin.order-report.index', [
            'catalog_type' => 'products',
            'lifecycle' => 'active',
            'status' => 'new',
            'payment_status' => 'partially_paid',
            'order_source' => 'whatsapp',
            'payment_method' => 'انستاباي',
            'from' => OrderDateTime::display(now())->toDateString(),
            'to' => OrderDateTime::display(now())->toDateString(),
            'q' => 'FILTER-IN',
        ]))->assertOk()->viewData('report');

        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame('FILTER-IN', $report['rows']->first()['key']);
    }

    public function test_report_permission_navigation_and_filtered_csv_export(): void
    {
        $included = $this->order('CSV-IN', 'new', 'website', 'unpaid', 0, 0);
        $included->items()->create([
            'item_type' => 'product',
            'title' => 'منتج CSV',
            'unit_price_cents' => 12_000,
            'quantity' => 1,
            'total_price_cents' => 12_000,
        ]);
        $excluded = $this->order('CSV-OUT', 'cancelled', 'website', 'unpaid', 0, 0);
        $excluded->items()->create([
            'item_type' => 'product',
            'title' => 'منتج ملغي',
            'unit_price_cents' => 9_000,
            'quantity' => 1,
            'total_price_cents' => 9_000,
        ]);

        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');
        $this->actingAs($limited)->get(route('admin.order-report.index'))->assertForbidden();
        $this->actingAs($limited)->get(route('admin.orders.index'))->assertOk()->assertDontSee('تقرير الطلبات');

        $response = $this->actingAs($this->admin)->get(route('admin.order-report.export', [
            'lifecycle' => 'active',
            'q' => 'CSV',
        ]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('CSV-IN', $csv);
        $this->assertStringNotContainsString('CSV-OUT', $csv);
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'order_report.exported',
        ]);
    }

    private function order(
        string $group,
        string $status,
        string $source,
        string $paymentStatus,
        int $paidAmountCents,
        int $deliveryCents,
        ?Story $story = null,
    ): Order {
        return Order::create([
            'order_number' => 'HK-'.$group,
            'checkout_group_key' => $group,
            'parent_name' => 'عميل '.$group,
            'story_id' => $story?->id,
            'child_name' => $story ? 'طفل التقرير' : null,
            'child_age' => $story ? 7 : null,
            'child_gender' => $story ? 'boy' : null,
            'language' => 'ar',
            'delivery_details' => [
                'checkout_group' => $group,
                'phone' => '201001234567',
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'street' => 'شارع التقرير',
                'delivery_fee' => $deliveryCents / 100,
            ],
            'uploaded_photos' => [],
            'status' => $status,
            'payment_status' => $paymentStatus,
            'paid_amount_cents' => $paidAmountCents,
            'order_source' => $source,
        ]);
    }
}
