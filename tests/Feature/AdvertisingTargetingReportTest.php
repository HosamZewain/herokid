<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCart;
use App\Services\Sales\AdvertisingTargetingReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdvertisingTargetingReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function order(string $key, array $attributes = [], array $delivery = []): Order
    {
        $order = Order::create($attributes + ['order_number' => 'TEST-'.fake()->uuid(), 'checkout_group_key' => $key,
            'status' => 'new', 'payment_status' => 'unpaid', 'paid_amount_cents' => 0, 'parent_name' => 'Private Synthetic Parent',
            'delivery_details' => $delivery + ['country' => 'مصر', 'governorate' => 'القاهرة', 'phone' => '01000000000',
                'delivery_fee' => 50, 'bosta_city_id' => 'cairo', 'bosta_zone_id' => 'nasr', 'bosta_zone_other_name' => 'مدينة نصر',
                'bosta_district_id' => 'district-one', 'bosta_district_other_name' => 'الحي الأول', 'address' => 'Private Street Address']]);
        $order->items()->create(['item_type' => 'product', 'title' => 'ملصقات اختبار', 'quantity' => 2,
            'unit_price_cents' => 10000, 'total_price_cents' => 20000]);

        return $order;
    }

    private function report(array $query = [], bool $export = false): array
    {
        return app(AdvertisingTargetingReportService::class)->report(Request::create('/admin/advertising-report', 'GET', $query + ['range' => 'today']), $export);
    }

    public function test_mixed_checkout_is_counted_once_and_discount_shipping_and_quantities_are_correct(): void
    {
        $first = $this->order('MIXED', ['discount_cents' => 5000, 'paid_amount_cents' => 15000]);
        $second = $this->order('MIXED', ['discount_cents' => 5000, 'paid_amount_cents' => 15000]);
        $second->items()->update(['item_type' => 'story', 'title' => 'قصة اختبار', 'quantity' => 1]);
        $report = $this->report();
        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame(35000, $report['summary']['net_cents']);
        $this->assertSame(35000, $report['summary']['average_cents']);
        $this->assertSame(15000, $report['summary']['collected_cents']);
        $this->assertSame(3, $report['summary']['quantity']);
        $this->assertSame(35000, $report['items']->sum('net_cents'));
        $this->assertSame(40000, $report['items']->sum('gross_cents'));
        $this->assertSame(1, $report['areas']->total());
        $this->assertNotSame($first->id, $second->id);
    }

    public function test_granularity_and_area_drilldown_preserve_parent_geography(): void
    {
        $this->order('ONE');
        $this->order('TWO', [], ['bosta_district_id' => 'district-two', 'bosta_district_other_name' => 'الحي الثاني']);
        $this->order('THREE', [], ['governorate' => 'الجيزة', 'bosta_city_id' => 'giza']);
        $report = $this->report();
        $this->assertSame(3, $report['areas']->total());
        $this->assertSame(2, $this->report(['level' => 'city'])['areas']->total());
        $this->assertSame(2, $this->report(['level' => 'governorate'])['areas']->total());
        $selected = $this->report(['area' => $report['areas']->first()['key']]);
        $this->assertSame(1, $selected['summary']['checkouts']);
        $this->assertSame(2, $selected['items']->sum('quantity'));
        $this->assertStringContainsString('الحي', $selected['selected_area']['label']);
    }

    public function test_search_filters_city_governorate_or_district_and_invalid_selection_never_broadens(): void
    {
        $this->order('ONE');
        $this->order('TWO', [], ['bosta_zone_other_name' => 'العبور', 'bosta_zone_id' => 'obour']);
        $this->assertSame(1, $this->report(['location' => 'مدينة نصر'])['summary']['checkouts']);
        $this->assertSame(2, $this->report(['location' => 'القاهرة'])['summary']['checkouts']);
        $this->assertSame(0, $this->report(['location' => '%'])['summary']['checkouts']);
        $this->assertSame(0, $this->report(['area' => 'invalid'])['summary']['checkouts']);
        $this->assertSame(0, $this->report(['item' => 'product:999999'])['summary']['checkouts']);
    }

    public function test_legacy_and_missing_geography_remain_explicit_without_private_address_parsing(): void
    {
        $this->order('LEGACY', [], ['bosta_city_id' => null, 'bosta_zone_id' => null, 'bosta_zone_other_name' => null,
            'bosta_district_id' => null, 'bosta_district_other_name' => null, 'city' => 'مدينة قديمة']);
        $this->order('UNKNOWN', ['delivery_details' => []]);
        $report = $this->report();
        $this->assertSame(0, $report['coverage']['structured_district']);
        $this->assertSame(0, $report['coverage']['structured_city']);
        $this->assertTrue($report['areas']->getCollection()->contains(fn (array $row): bool => $row['legacy'] && str_contains($row['label'], 'مدينة قديمة')));
        $this->assertTrue($report['areas']->getCollection()->contains(fn (array $row): bool => str_contains($row['label'], 'دولة غير محددة')));
        $this->assertStringNotContainsString('Private Street', json_encode($report));
    }

    public function test_ids_group_translated_labels_and_same_names_with_different_ids_stay_separate(): void
    {
        $this->order('AR');
        $this->order('EN', [], ['bosta_district_other_name' => null, 'bosta_district_name' => 'District One']);
        $this->order('OTHER', [], ['bosta_district_id' => 'different-id']);
        $this->assertSame(2, $this->report()['areas']->total());
        $this->assertSame(2, $this->report()['areas']->first()['checkouts']);
    }

    public function test_cancelled_and_deleted_purchases_are_excluded_and_partial_payment_is_not_full_revenue(): void
    {
        $this->order('UNPAID');
        $this->order('PARTIAL', ['paid_amount_cents' => 3000, 'payment_status' => 'partially_paid']);
        $this->order('CANCELLED', ['status' => 'cancelled', 'paid_amount_cents' => 25000]);
        $this->order('DELETED')->delete();
        $report = $this->report();
        $this->assertSame(2, $report['summary']['checkouts']);
        $this->assertSame(40000, $report['summary']['net_cents']);
        $this->assertSame(3000, $report['summary']['collected_cents']);
        $this->assertSame(1, $report['coverage']['cancelled']);
        $paid = $this->report(['basis' => 'collected']);
        $this->assertSame(1, $paid['summary']['checkouts']);
        $this->assertSame(20000, $paid['summary']['net_cents']);
        $this->assertSame(3000, $paid['summary']['collected_cents']);
    }

    public function test_original_purchase_date_includes_deleted_history_and_uses_cairo_day_boundaries(): void
    {
        $old = $this->order('OLD', ['created_at' => '2026-10-06 10:00:00']);
        $old->delete();
        $this->order('OLD');
        $this->order('CAIRO-TODAY', ['created_at' => '2026-10-07 21:30:00']);
        $this->order('CAIRO-YESTERDAY', ['created_at' => '2026-10-07 20:30:00']);
        $this->assertSame(1, $this->report()['summary']['checkouts']);
        $this->assertSame(1, $this->report(['range' => 'yesterday'])['summary']['checkouts']);
        $this->assertSame(3, $this->report(['range' => 'custom', 'start_date' => '2026-10-06', 'end_date' => '2026-10-08'])['summary']['checkouts']);
    }

    public function test_repeat_customers_are_counted_per_period_and_not_per_order_record(): void
    {
        $this->order('ONE');
        $this->order('ONE');
        $this->order('TWO');
        $this->order('THREE', [], ['phone' => '01111111111']);
        $this->order('OLD', ['created_at' => '2026-09-01 08:00:00']);
        $this->assertSame(3, $this->report()['summary']['checkouts']);
        $this->assertSame(2, $this->report()['summary']['customers']);
        $this->assertSame(1, $this->report()['summary']['repeat_customers']);
    }

    public function test_product_identity_is_not_title_and_filter_selects_whole_basket_without_losing_discount(): void
    {
        $one = Product::create(['name_ar' => 'اسم مكرر', 'slug' => 'test-one', 'price_cents' => 10000]);
        $two = Product::create(['name_ar' => 'اسم مكرر', 'slug' => 'test-two', 'price_cents' => 10000]);
        $order = $this->order('PRODUCTS', ['discount_cents' => 10000]);
        $order->items()->update(['product_id' => $one->id, 'title' => 'اسم مكرر']);
        $order->items()->create(['item_type' => 'product', 'product_id' => $two->id, 'title' => 'اسم مكرر', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
        $one->update(['name_ar' => 'اسم جديد', 'price_cents' => 99999]);
        $report = $this->report(['item' => 'product:'.$one->id]);
        $this->assertSame(2, $report['items']->total());
        $this->assertSame(1, $report['summary']['checkouts']);
        $this->assertSame(20000, $report['summary']['net_cents']);
        $this->assertSame(20000, $report['items']->sum('net_cents'));
        $this->assertSame('اسم مكرر', $report['items']->first()['title']);
    }

    public function test_cent_allocation_and_discount_larger_than_items_reconcile_exactly(): void
    {
        $order = $this->order('CENTS', ['discount_cents' => 1]);
        $order->items()->update(['total_price_cents' => 1, 'unit_price_cents' => 1, 'quantity' => 1]);
        foreach (['Two', 'Three'] as $title) {
            $order->items()->create(['item_type' => 'product', 'title' => $title, 'quantity' => 1, 'unit_price_cents' => 1, 'total_price_cents' => 1]);
        }
        $this->assertSame(2, $this->report()['items']->sum('net_cents'));
        $order->update(['discount_cents' => 99999]);
        $this->assertSame(0, $this->report()['summary']['net_cents']);
        $this->assertSame(0, $this->report()['items']->sum('net_cents'));
    }

    public function test_legacy_story_without_item_rows_is_included(): void
    {
        $story = Story::create(['title' => 'قصة قديمة', 'slug' => 'legacy-report-story', 'price' => 199, 'language' => 'ar']);
        $order = $this->order('LEGACY-STORY', ['story_id' => $story->id]);
        $order->items()->delete();
        $this->assertSame('story:'.$story->id, $this->report()['items']->first()['key']);
        $this->assertSame(19900, $this->report()['summary']['net_cents']);
    }

    public function test_saved_campaign_evidence_and_cart_fallback_are_used_without_guessing_direct(): void
    {
        $this->order('SNAPSHOT', [], ['marketing_attribution' => ['utm_source' => 'facebook', 'utm_medium' => 'paid_social',
            'campaign_name' => 'حملة المدارس', 'campaign_id' => '123', 'adset_name' => 'مجموعة مدينة نصر', 'ad_name' => 'إعلان الاستيكر', 'ad_id' => '456']]);
        $cartOrder = $this->order('CART');
        VisitorCart::create(['cart_identifier' => fake()->uuid(), 'status' => 'converted', 'related_order_id' => $cartOrder->id,
            'utm_source' => 'instagram', 'utm_medium' => 'paid_social', 'utm_campaign' => 'summer', 'ad_name' => 'Cart Ad',
            'last_activity_at' => now(), 'currency' => 'EGP', 'item_count' => 1]);
        $this->order('UNKNOWN');
        $report = $this->report();
        $this->assertSame(3, $report['campaigns']->total());
        $this->assertSame('إعلان الاستيكر', $report['campaigns']->getCollection()->firstWhere('campaign_id', '123')['ad_name']);
        $this->assertSame('Cart Ad', $report['campaigns']->getCollection()->firstWhere('campaign', 'summer')['ad_name']);
        $this->assertSame(1, $report['coverage']['unknown_attribution']);
        $this->assertSame(0, $report['campaigns']->getCollection()->where('type', 'direct')->count());
    }

    public function test_later_checkout_record_can_supply_saved_attribution_and_snapshot_beats_cart(): void
    {
        $first = $this->order('MIXED');
        VisitorCart::create(['cart_identifier' => fake()->uuid(), 'status' => 'converted', 'related_order_id' => $first->id,
            'utm_source' => 'old-source', 'last_activity_at' => now(), 'currency' => 'EGP', 'item_count' => 1]);
        $this->order('MIXED', [], ['marketing_attribution' => ['utm_source' => 'facebook', 'ad_name' => 'Saved Ad', 'ad_id' => '789']]);
        $this->assertSame('Saved Ad', $this->report()['campaigns']->first()['ad_name']);
    }

    public function test_empty_page_and_malformed_optional_parameters_are_safe(): void
    {
        $report = $this->report(['level' => ['bad'], 'area' => ['bad'], 'item' => ['bad'], 'location' => ['bad'], 'start_date' => ['bad']]);
        $this->assertSame(0, $report['summary']['checkouts']);
        $this->assertSame(0, $report['summary']['average_cents']);
        $this->assertSame('district', $report['level']);
        $this->actingAs($this->admin)->get(route('admin.advertising-report.index'))->assertOk()->assertSee('لا توجد عمليات شراء');
    }

    public function test_page_export_and_navigation_require_sales_report_permission(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');
        $this->actingAs($limited)->get(route('admin.advertising-report.index'))->assertForbidden();
        $this->get(route('admin.advertising-report.export'))->assertForbidden();
        $this->get(route('admin.orders.index'))->assertOk()->assertDontSee('تقرير استهداف الإعلانات');
        $this->actingAs($this->admin)->get(route('admin.advertising-report.index'))->assertOk()->assertSee('تقرير استهداف الإعلانات');
    }

    public function test_page_and_exports_are_aggregate_only_escaped_and_private(): void
    {
        $this->order('PRIVATE', [], ['governorate' => ' =Dangerous', 'marketing_attribution' => ['utm_source' => 'facebook', 'ad_name' => '<script>unsafe</script>']]);
        $response = $this->actingAs($this->admin)->get(route('admin.advertising-report.index'))->assertOk();
        $response->assertDontSee('Private Synthetic Parent')->assertDontSee('01000000000')->assertDontSee('Private Street Address')
            ->assertSee('&lt;script&gt;unsafe&lt;/script&gt;', false)->assertDontSee('<script>unsafe</script>', false);
        foreach (['areas', 'items', 'campaigns'] as $section) {
            $csv = $this->get(route('admin.advertising-report.export', ['section' => $section]))->assertOk();
            $this->assertStringContainsString('no-store', (string) $csv->headers->get('Cache-Control'));
            $content = $csv->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
            $this->assertStringNotContainsString('01000000000', $content);
            $this->assertStringNotContainsString('Private Synthetic Parent', $content);
        }
    }

    public function test_read_does_not_mutate_order_items_payments_or_assignments(): void
    {
        $order = $this->order('READ-ONLY');
        $before = $order->fresh()->getAttributes();
        $items = $order->items()->get()->toArray();
        $events = DB::table('order_payment_events')->count();
        $assignments = DB::table('order_group_assignments')->count();
        $this->actingAs($this->admin)->get(route('admin.advertising-report.index'))->assertOk();
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($items, $order->items()->get()->toArray());
        $this->assertSame($events, DB::table('order_payment_events')->count());
        $this->assertSame($assignments, DB::table('order_group_assignments')->count());
    }

    public function test_large_fixture_is_batched_paginated_and_full_export_is_not_limited_to_page(): void
    {
        foreach (range(1, 31) as $index) {
            $this->order('BULK-'.$index, [], ['bosta_district_id' => 'district-'.$index]);
        }
        DB::enableQueryLog();
        $report = $this->report(['page' => 2]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(20, count($queries), 'No one-query-per-checkout or per-area hydration.');
        $this->assertSame(31, $report['areas']->total());
        $this->assertCount(6, $report['areas']->items());
        $this->assertCount(31, $this->report([], true)['areas']);
        $this->assertSame(31, $report['summary']['checkouts']);
    }
}
