<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCart;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\MarketingAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderMarketingAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_later_click_cannot_fill_missing_names_or_ids_from_a_different_first_campaign(): void
    {
        $this->get(route('home', ['utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'first']))->assertOk();
        $this->get(route('home', ['utm_source' => 'meta', 'utm_medium' => 'paid_social', 'utm_campaign' => 'second', 'ad_name' => 'Second ad', 'ad_id' => 'second-id']))->assertOk();
        $data = session('marketing_attribution');
        $this->assertSame('first', $data['utm_campaign']);
        $this->assertArrayNotHasKey('ad_name', $data);
        $this->assertArrayNotHasKey('ad_id', $data);
    }

    public function test_invalid_query_values_do_not_break_visits_or_poison_later_valid_tracking(): void
    {
        $this->get(route('home', ['utm_source' => ['meta'], 'ad_name' => '{{ad.name}}', 'token' => 'not-attribution']))->assertOk();
        $this->get(route('home', ['utm_source' => 'meta', 'utm_medium' => 'paid_social', 'ad_name' => 'Valid ad']))->assertOk();
        $this->assertSame('Valid ad', session('marketing_attribution.ad_name'));
        $this->assertArrayNotHasKey('token', session('marketing_attribution'));
    }

    public function test_order_list_group_and_story_details_display_full_stored_attribution_without_mutating_order(): void
    {
        $order = $this->order($this->attribution());
        $before = $order->fresh()->getRawOriginal();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['admin.orders.index', 'admin.orders.groups.show', 'admin.orders.show'] as $route) {
            $params = $route === 'admin.orders.index' ? [] : [$order];
            $response = $this->get(route($route, $params))->assertOk()->assertSee('إعلان ميتا')->assertSee('إعلان تجريبي')->assertSee('حملة تجريبية');
            if ($route !== 'admin.orders.index') {
                $response->assertSee('مجموعة تجريبية')->assertSee('cmp-10')->assertSee('set-20')->assertSee('ad-30')->assertSee('video_a');
            }
        }
        $this->assertSame($before, $order->fresh()->getRawOriginal());
        $this->assertSame('website', $order->fresh()->order_source);
    }

    public function test_legacy_orders_can_display_converted_cart_evidence_without_rewriting_snapshots(): void
    {
        $order = $this->order([]);
        VisitorCart::create(['cart_identifier' => (string) Str::uuid(), 'related_order_id' => $order->id, 'status' => 'converted', ...$this->attribution()]);
        $group = app(AdminOrderGroupService::class)->findByRepresentative($order->id);
        $this->assertSame('إعلان تجريبي', $group['marketing_sources'][0]['ad_name']);
        $this->assertSame([], $order->fresh()->delivery_details['marketing_attribution']);
    }

    public function test_legacy_carts_without_new_names_remain_valid_and_are_eager_loaded_once_for_the_order_list(): void
    {
        foreach (range(1, 3) as $number) {
            $order = $this->order([]);
            $cart = VisitorCart::create(['cart_identifier' => (string) Str::uuid(), 'related_order_id' => $order->id,
                'status' => 'converted', 'utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'Old tracking']);
            $this->assertNull($cart->fresh()->ad_name);
            $this->assertNull($cart->fresh()->campaign_name);
            $this->assertNull($cart->fresh()->adset_name);
        }
        DB::enableQueryLog();
        try {
            $result = app(AdminOrderGroupService::class)->paginate(Request::create('/admin/orders'), false);
            $this->assertCount(3, $result['groups']);
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'visitor_carts'));
            $this->assertCount(1, $queries);
            foreach ($result['groups'] as $group) {
                $this->assertSame('meta_ad', $group['marketing_sources'][0]['type']);
                $this->assertNull($group['marketing_sources'][0]['ad_name']);
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_order_snapshot_remains_authoritative_over_cart(): void
    {
        $order = $this->order($this->attribution());
        VisitorCart::create(['cart_identifier' => (string) Str::uuid(), 'related_order_id' => $order->id, 'ad_name' => 'Wrong later ad', 'utm_source' => 'meta', 'utm_medium' => 'paid_social']);
        $this->assertSame('إعلان تجريبي', MarketingAttribution::forOrder($order)['ad_name']);
    }

    public function test_legacy_missing_data_is_unknown_not_direct_and_new_untracked_landing_is_labelled_explicitly(): void
    {
        $unknown = $this->order([]);
        $direct = $this->order(['landing_url' => 'https://hero-kid.com/shop']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.orders.index'))
            ->assertOk()->assertSee('غير معروف — لا توجد بيانات تتبع')->assertSee('مباشر / بدون تتبع');
        $this->assertSame('unknown', MarketingAttribution::forOrder($unknown)['type']);
        $this->assertSame('direct', MarketingAttribution::forOrder($direct)['type']);
    }

    public function test_html_in_names_is_inert_and_private_url_parameters_are_not_shown(): void
    {
        $order = $this->order([...$this->attribution(), 'ad_name' => '<script>alert("ad")</script>', 'landing_url' => 'https://hero-kid.com/private/secret-path?token=secret-query']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.orders.groups.show', $order))
            ->assertOk()->assertSee('<script>alert("ad")</script>')->assertDontSee('<script>alert("ad")</script>', false)
            ->assertDontSee('secret-path')->assertDontSee('secret-query');
    }

    public function test_group_deduplicates_same_checkout_evidence_but_does_not_hide_distinct_sources(): void
    {
        $first = $this->order($this->attribution());
        $second = $this->order($this->attribution(), $first->checkout_group_key);
        $service = app(AdminOrderGroupService::class);
        $this->assertCount(1, $service->findByRepresentative($first->id)['marketing_sources']);
        $details = $second->delivery_details;
        $details['marketing_attribution']['ad_name'] = 'إعلان آخر';
        $second->update(['delivery_details' => $details]);
        $this->assertCount(2, $service->findByRepresentative($first->id)['marketing_sources']);
    }

    public function test_csv_keeps_original_channel_column_and_appends_full_attribution_safely(): void
    {
        $this->order([...$this->attribution(), 'ad_name' => '=FORMULA()']);
        $csv = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.orders.export'))->assertOk()->streamedContent();
        $rows = array_map(fn (string $line): array => str_getcsv($line), explode("\n", trim($csv)));
        $this->assertContains('الموقع', $rows[1]);
        $this->assertContains('إعلان ميتا', $rows[1]);
        $this->assertContains("'=FORMULA()", $rows[1]);
        $this->assertContains('اسم مجموعة الإعلانات', $rows[0]);
        $this->assertCount(count($rows[0]), $rows[1]);
    }

    public function test_attribution_is_not_publicly_accessible_without_order_permission(): void
    {
        $order = $this->order($this->attribution());
        $this->actingAs(User::factory()->create(['role' => 'user']))->get(route('admin.orders.groups.show', $order))->assertForbidden();
    }

    private function attribution(): array
    {
        return ['utm_source' => 'facebook', 'utm_medium' => 'paid_social', 'utm_campaign' => 'campaign_tracking', 'utm_content' => 'video_a', 'utm_term' => 'parents',
            'campaign_name' => 'حملة تجريبية', 'adset_name' => 'مجموعة تجريبية', 'ad_name' => 'إعلان تجريبي',
            'campaign_id' => 'cmp-10', 'adset_id' => 'set-20', 'ad_id' => 'ad-30',
            'fbclid' => 'test-click', 'landing_url' => 'https://hero-kid.com/shop', 'referrer' => 'https://facebook.com/'];
    }

    private function order(array $attribution, ?string $group = null): Order
    {
        $story = Story::create(['title' => 'قصة اختبار المصدر', 'slug' => 'source-'.Str::uuid(), 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);

        return Order::create(['order_number' => 'TEST-'.Str::uuid(), 'checkout_group_key' => $group ?: 'GROUP-'.Str::uuid(), 'story_id' => $story->id,
            'child_name' => 'طفل اختبار', 'child_age' => 6, 'child_gender' => 'girl', 'parent_name' => 'ولي أمر اختبار', 'language' => 'ar',
            'order_source' => 'website', 'status' => 'new', 'delivery_details' => ['marketing_attribution' => $attribution], 'uploaded_photos' => []]);
    }
}
