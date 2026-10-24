<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\DashboardMonthStatistics;
use App\Support\OrderDateTime;
use App\Support\OrderStatusRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardMonthStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'Africa/Cairo')->utc());
    }

    private function order(string $key, int $stories = 0, int $products = 0, ?string $at = null): Order
    {
        $order = Order::create(['order_number' => 'HK-'.$key.'-'.Order::withTrashed()->count(), 'checkout_group_key' => $key,
            'status' => 'new', 'created_at' => $at ?? now(), 'delivery_details' => ['delivery_fee' => 10], 'uploaded_photos' => []]);
        foreach (['story' => $stories, 'product' => $products] as $type => $quantity) {
            if ($quantity > 0) {
                $order->items()->create(['item_type' => $type, 'title' => 'Test '.$type, 'quantity' => $quantity, 'unit_price_cents' => 10000, 'total_price_cents' => $quantity * 10000]);
            }
        }

        return $order;
    }

    private function stats(): array
    {
        return app(DashboardMonthStatistics::class)->summarize(OrderDateTime::display(now())->startOfDay());
    }

    public function test_counts_quantities_once_with_exclusive_categories_and_deleted_history(): void
    {
        $this->order('STORY', 3);
        $this->order('PRODUCT', 0, 4);
        $this->order('MIXED', 2, 5);
        $story = Story::create(['title' => 'Legacy', 'slug' => 'legacy-month', 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        $this->order('LEGACY')->update(['story_id' => $story->id]);
        $this->order('CANCELLED', 0, 2)->update(['status' => 'cancelled']);
        $this->order('DELETED', 0, 3)->delete();
        $this->order('REPLACED', 0, 9)->delete();
        $this->order('REPLACED', 0, 1);
        $this->order('PREVIOUS', 2, 0, '2026-09-30 10:00:00');
        $this->order('PREVIOUS', 2);
        $this->order('FUTURE', 3, 0, '2026-11-01 10:00:00');
        $this->order('MERGED', 0, 8)->delete();
        DB::table('order_group_merge_aliases')->insert(['source_checkout_group_key' => 'MERGED', 'target_checkout_group_key' => 'PRODUCT', 'reason' => 'Test merge', 'merged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $s = $this->stats();
        $this->assertSame(7, $s['checkouts']);
        $this->assertSame(21, $s['pieces']);
        $this->assertSame(6, $s['story_pieces']);
        $this->assertSame(15, $s['product_pieces']);
        $this->assertSame(2, $s['story_checkouts']);
        $this->assertSame(4, $s['product_checkouts']);
        $this->assertSame(1, $s['mixed_checkouts']);
        $this->assertSame(4, $s['story_only_pieces']);
        $this->assertSame(10, $s['product_only_pieces']);
        $this->assertSame(7, $s['mixed_pieces']);
        $this->assertSame(1, $s['cancelled_checkouts']);
        $this->assertSame(0.7, $s['daily_average']);
        $this->assertSame($s['checkouts'], $s['story_checkouts'] + $s['product_checkouts'] + $s['mixed_checkouts'] + $s['unclassified_checkouts']);
        $dashboard = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(7, $dashboard['today']['new_checkouts']);
        $this->assertSame(9, $dashboard['checkouts']['total']); // Includes previous and future purchases, not merged copies.
    }

    public function test_cairo_month_boundary_and_first_day_average(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Africa/Cairo')->utc());
        $boundary = OrderDateTime::utcStartOfDay('2026-10-01');
        $this->order('BEFORE', 1, 0, $boundary->subSecond()->format('Y-m-d H:i:s'));
        $this->order('AT', 2, 0, $boundary->format('Y-m-d H:i:s'));
        $s = $this->stats();
        $this->assertSame(1, $s['checkouts']);
        $this->assertSame(2, $s['pieces']);
        $this->assertSame(1, $s['elapsed_days']);
        $this->assertEquals(1, $s['daily_average']);
    }

    public function test_daily_categories_and_values_reconcile_with_month_including_mixed_and_unknown(): void
    {
        $this->order('STORY-DAILY', 3, 0, '2026-10-08 12:00:00');
        $this->order('PRODUCT-DAILY', 0, 4, '2026-10-09 12:00:00');
        $this->order('MIXED-DAILY', 2, 5)->update(['discount_cents' => 12000]);
        $this->order('UNKNOWN-DAILY')->update(['delivery_details' => ['item_price' => 25, 'delivery_fee' => 10]]);
        $s = app(AdminOrderGroupService::class)->dashboardStats();
        $days = collect($s['last_seven_days']);
        foreach ($days as $day) {
            $this->assertSame($day['new_checkouts'], $day['story_checkouts'] + $day['product_checkouts'] + $day['mixed_checkouts'] + $day['unclassified_checkouts']);
            $this->assertSame($day['total_value_cents'], $day['story_value_cents'] + $day['product_value_cents'] + $day['mixed_value_cents'] + $day['unclassified_value_cents']);
        }
        foreach (['story', 'product', 'mixed', 'unclassified'] as $category) {
            $this->assertSame($s['month'][$category.'_checkouts'], (int) $days->sum($category.'_checkouts'));
        }
        $today = $days->last();
        $this->assertSame(1, $today['mixed_checkouts']);
        $this->assertSame(59000, $today['mixed_value_cents']); // 700 - 120 + 10 shipping, once.
        $this->assertSame(1, $today['unclassified_checkouts']);
        $this->assertSame(3500, $today['unclassified_value_cents']);
        $this->assertSame($s['month']['value_cents'], (int) $days->sum('total_value_cents'));
        $this->assertSame($s['today']['order_value_cents'], $today['total_value_cents']);
    }

    public function test_overpayments_remain_separate_from_other_orders_outstanding_balances(): void
    {
        $this->order('OVERPAID', 1)->update(['paid_amount_cents' => 16000]);
        $this->order('UNPAID', 0, 1);
        $s = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(22000, $s['month']['value_cents']);
        $this->assertSame(16000, $s['month']['paid_balance_cents']);
        $this->assertSame(11000, $s['month']['outstanding_cents']);
        $this->assertSame(5000, $s['month']['excess_paid_cents']);
        $this->assertSame(5000, $s['operations']['excess_paid_cents']);
        $this->assertSame(0, $s['month']['payments_cents']); // A balance alone is not a dated payment.
    }

    public function test_legacy_price_is_kept_when_the_same_checkout_also_has_modern_items(): void
    {
        $story = Story::create(['title' => 'Legacy price', 'slug' => 'legacy-price-dashboard', 'language' => 'ar', 'gender' => 'both', 'price' => 150, 'active' => true]);
        $this->order('LEGACY-MIXED')->update(['story_id' => $story->id, 'delivery_details' => ['item_price' => 100, 'delivery_fee' => 10]]);
        $this->order('LEGACY-MIXED', 0, 2);
        $s = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(31000, $s['month']['value_cents']);
        $this->assertSame(31000, $s['today']['order_value_cents']);
        $this->assertSame(30000, $s['today']['average_order_cents']);
        $this->assertSame(31000, app(AdminOrderGroupService::class)->recent()->first()['total_cents']);
        $admin = User::factory()->create(['role' => 'admin']);
        $report = $this->actingAs($admin)->get(route('admin.order-report.index', ['lifecycle' => 'all']))->assertOk()->viewData('report');
        $this->assertSame(31000, $report['summary']['total_cents']);
        $this->assertSame(31000, $report['rows']->first()['total_cents']);
    }

    public function test_explicit_zero_priced_items_do_not_fall_back_to_catalog_price(): void
    {
        $story = Story::create(['title' => 'Free item', 'slug' => 'free-price-dashboard', 'language' => 'ar', 'gender' => 'both', 'price' => 150, 'active' => true]);
        $order = $this->order('FREE-STORY', 1);
        $order->update(['story_id' => $story->id]);
        $order->items()->update(['unit_price_cents' => 0, 'total_price_cents' => 0]);
        $this->assertSame(1000, $this->stats()['value_cents']);
        $this->assertSame(1000, app(AdminOrderGroupService::class)->recent()->first()['total_cents']);
    }

    public function test_positive_collection_count_matches_ledger_sign_not_event_label(): void
    {
        $order = $this->order('CASH-COUNT', 1);
        foreach ([10000, 2000, -3000, 0] as $amount) {
            OrderPaymentEvent::create(['event_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'checkout_group_key' => $order->checkout_group_key,
                'event_type' => 'payment_corrected', 'source' => 'test', 'previous_status' => 'unpaid', 'new_status' => 'partially_paid',
                'previous_paid_amount_cents' => 0, 'new_paid_amount_cents' => max(0, $amount), 'amount_delta_cents' => $amount,
                'affects_collection_stats' => true, 'occurred_at' => now()]);
        }
        $s = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(2, $s['today']['payment_checkouts']);
        $this->assertSame(9000, $s['today']['payments_cents']);
        $this->assertSame($s['month']['payments_cents'], $s['today']['payments_cents']);
        $this->assertSame(9000, (int) collect($s['today']['payment_events'])->sum('amount_delta_cents'));
    }

    public function test_registered_customer_count_excludes_staff_and_internal_guest_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'customer']);
        User::factory()->create(['role' => 'mobile_guest']);
        $this->actingAs($admin)->get(route('admin.dashboard.index'))->assertOk()->assertViewHas('totalUsers', 1);
    }

    public function test_recent_orders_show_story_quantity_rather_than_number_of_order_records(): void
    {
        $this->order('RECENT-QUANTITY', 3, 2);
        $this->order('RECENT-QUANTITY', 4);
        $row = app(AdminOrderGroupService::class)->recent()->first();
        $this->assertSame(2, $row['story_count']);
        $this->assertSame(7, $row['story_quantity']);
        $this->assertSame(2, $row['product_quantity']);
    }

    public function test_merged_source_copies_do_not_inflate_operational_counts_or_cancellation_activity(): void
    {
        $target = $this->order('TARGET-ACTIVE', 2);
        $source = $this->order('SOURCE-COPY', 1);
        $source->statusLogs()->create(['status_type' => 'order', 'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_group_merge_aliases')->insert(['source_checkout_group_key' => $source->checkout_group_key, 'target_checkout_group_key' => $target->checkout_group_key, 'reason' => 'Test', 'merged_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $s = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(1, $s['checkouts']['total']);
        $this->assertSame(1, $s['records']['total']);
        $this->assertSame(1, $s['checkouts']['new']);
        $this->assertSame(1, $s['operations']['active_checkouts']);
        $this->assertSame(1, $s['operations']['unassigned_checkouts']);
        $this->assertSame(0, (int) collect($s['last_seven_days'])->sum('cancelled_checkouts'));
    }

    public function test_cash_uses_payment_dates_even_for_old_orders_and_excludes_non_cash(): void
    {
        $old = $this->order('OLD-PAID', 1, 0, '2026-09-10 12:00:00');
        foreach ([[10000, true, '2026-10-02 12:00:00'], [-2000, true, '2026-10-03 12:00:00'], [9000, false, '2026-10-02 13:00:00'], [5000, true, '2026-09-30 12:00:00']] as [$amount, $cash, $at]) {
            OrderPaymentEvent::create(['event_uuid' => (string) Str::uuid(), 'order_id' => $old->id, 'checkout_group_key' => $old->checkout_group_key,
                'event_type' => $amount > 0 ? 'payment_received' : 'payment_corrected', 'source' => 'test',
                'previous_status' => 'unpaid', 'new_status' => 'partially_paid', 'previous_paid_amount_cents' => 0,
                'new_paid_amount_cents' => max(0, $amount), 'amount_delta_cents' => $amount,
                'affects_collection_stats' => $cash, 'occurred_at' => $at]);
        }
        $s = $this->stats();
        $this->assertSame(0, $s['checkouts']);
        $this->assertSame(8000, $s['payments_cents']);
        $this->assertSame(0, $s['paid_balance_cents']);
    }

    public function test_empty_month_and_statistics_permission(): void
    {
        $s = $this->stats();
        $this->assertSame(0, $s['pieces']);
        $this->assertEquals(0, $s['daily_average']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.dashboard.index'))->assertOk()->assertSee('أوردرات الشهر')->assertViewHas('monthStats');
        $admin->permissions()->detach(Permission::where('key', 'dashboard.statistics.view')->value('id'));
        $admin->unsetRelation('permissions');
        $this->actingAs($admin)->get(route('admin.dashboard.index'))->assertOk()->assertDontSee('أوردرات الشهر')->assertViewHas('monthStats', null);
    }

    public function test_shipping_behavior_counts_a_checkout_once_across_custom_statuses(): void
    {
        DB::table('order_status_definitions')->insert(['type' => 'order', 'key' => 'shipped_custom_month', 'label_ar' => 'شحن آخر', 'color' => 'blue', 'behavior' => 'shipped', 'is_active' => true, 'sort_order' => 999, 'created_at' => now(), 'updated_at' => now()]);
        $this->order('SHIPPING', 1)->update(['status' => 'shipped']);
        $this->order('SHIPPING', 1)->update(['status' => 'shipped_custom_month']);
        OrderStatusRegistry::clearCache();
        $this->assertSame(1, app(AdminOrderGroupService::class)->dashboardStats()['checkouts']['shipped']);
    }

    public function test_shipping_cards_read_independent_shipping_status_and_ignore_deleted_rows(): void
    {
        $this->order('SHIP', 1)->update(['shipping_status' => 'shipped']);
        $this->order('SHIP', 1)->update(['shipping_status' => 'shipped']);
        $this->order('DELIVERED', 1)->update(['status' => 'shipped', 'shipping_status' => 'delivered']); // Current shipping state wins over stale legacy status.
        $this->order('DELETED-SHIP', 1)->update(['shipping_status' => 'shipped']);
        Order::where('checkout_group_key', 'DELETED-SHIP')->delete();
        $s = app(AdminOrderGroupService::class)->dashboardStats();
        $this->assertSame(1, $s['checkouts']['shipped']);
        $this->assertSame(2, $s['records']['shipped']);
        $this->assertSame(1, $s['checkouts']['delivered']);
    }
}
