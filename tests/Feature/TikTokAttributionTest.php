<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\VisitorCart;
use App\Services\Cart\CartTrackingService;
use App\Support\MarketingAttribution;
use App\Support\RequestSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TikTokAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.tiktok_pixel.id' => 'DBMCCQRC77UFMFQAAO30']);
    }

    public function test_tiktok_click_is_recorded_without_cart_and_survives_navigation_without_exposing_click_ids(): void
    {
        $this->get('/shop?ttclid=synthetic-private-click&utm_campaign=campaign-a')->assertOk()->assertSee('ttq.load("DBMCCQRC77UFMFQAAO30")', false);
        $this->get('/shop')->assertOk();
        $this->assertDatabaseCount('visitor_carts', 0);
        $this->assertDatabaseCount('storefront_visits', 1);
        $this->assertDatabaseHas('storefront_visits', ['source' => 'tiktok', 'medium' => 'paid_social', 'landing_path' => '/shop']);
        $this->assertSame('synthetic-private-click', session('marketing_attribution.ttclid'));
        $this->assertStringNotContainsString('synthetic-private-click', json_encode(DB::table('storefront_visits')->first()));
        $this->assertStringNotContainsString('utm_campaign', DB::table('storefront_visits')->value('landing_path'));
    }

    public function test_local_visit_uses_current_tiktok_click_even_when_cart_retains_meta_first_touch(): void
    {
        $this->get('/shop?utm_source=meta&utm_medium=paid_social&ad_id=meta-one')->assertOk();
        $this->get('/shop?ttclid=tiktok-one')->assertOk();
        $this->assertSame('meta', session('marketing_attribution.utm_source'));
        $this->assertArrayNotHasKey('ttclid', session('marketing_attribution'));
        $this->assertDatabaseCount('storefront_visits', 2);
        $this->assertDatabaseHas('storefront_visits', ['source_type' => 'tiktok_ad']);
    }

    public function test_session_expires_after_inactivity_and_cairo_date_report_does_not_require_ga4(): void
    {
        $this->travelTo(now()->setTimezone('Africa/Cairo')->setTime(0, 5));
        $this->get('/shop?ttclid=tiktok-one')->assertOk();
        $this->travel(31)->minutes();
        $this->get('/shop')->assertOk();
        $this->assertDatabaseCount('storefront_visits', 2);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/analytics?range=today')
            ->assertOk()->assertSee('مصادر زيارات المتجر')->assertSee('إعلان تيك توك')->assertDontSee('tiktok-one');
    }

    public function test_admin_bot_prefetch_and_opt_out_do_not_count_and_admin_has_no_pixel(): void
    {
        $this->withHeader('User-Agent', 'Example crawler')->get('/shop')->assertOk();
        $this->withHeader('User-Agent', 'Browser')->withHeader('Sec-Purpose', 'prefetch')->get('/shop')->assertOk();
        $this->withHeader('Sec-Purpose', 'navigate')->withHeader('DNT', '1')->get('/shop')->assertOk()->assertDontSee('analytics.tiktok.com');
        $this->withHeader('DNT', '0')->actingAs(User::factory()->create(['role' => 'admin']))->get('/shop')->assertOk()->assertDontSee('analytics.tiktok.com');
        $this->assertDatabaseCount('storefront_visits', 0);
    }

    public function test_pixel_can_be_disabled_and_private_pages_never_include_it(): void
    {
        Setting::create(['key' => 'tiktok_pixel_id', 'value' => '']);
        $this->get('/shop')->assertOk()->assertDontSee('analytics.tiktok.com');
        Setting::where('key', 'tiktok_pixel_id')->update(['value' => 'DBMCCQRC77UFMFQAAO30']);
        RequestSettings::forget();
        $this->get('/login')->assertOk()->assertDontSee('analytics.tiktok.com');
    }

    public function test_tiktok_click_is_saved_on_cart_and_exposed_only_as_safe_source_on_order(): void
    {
        $this->get('/shop?ttclid=test-private-click')->assertOk();
        $request = request();
        app(CartTrackingService::class)->recordCheckoutStarted($request);
        $cart = VisitorCart::firstOrFail();
        $this->assertSame('tiktok', $cart->utm_source);
        $this->assertSame('test-private-click', $cart->ttclid);
        $order = new Order(['order_source' => 'website', 'delivery_details' => ['marketing_attribution' => session('marketing_attribution')]]);
        $source = MarketingAttribution::forOrder($order);
        $this->assertSame('إعلان تيك توك', $source['label']);
        $this->assertStringNotContainsString('test-private-click', json_encode($source));
    }

    public function test_unauthorized_user_cannot_read_visit_report(): void
    {
        $this->get('/admin/analytics')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'user']))->get('/admin/analytics')->assertForbidden();
    }

    public function test_unpaid_checkout_emits_order_placement_once_without_asserting_payment_or_sending_customer_fields(): void
    {
        $order = Order::create(['order_number' => 'TEST-TIKTOK', 'checkout_group_key' => 'TEST-TIKTOK-GROUP',
            'parent_name' => 'Synthetic Parent', 'status' => 'new', 'uploaded_photos' => [],
            'delivery_details' => ['phone' => '201000000000', 'total' => 399, 'delivery_fee' => 50]]);
        $this->withSession(['checkout.last_order_ids' => [$order->id], 'meta.purchase_event' => [
            'event_id' => 'synthetic-once', 'value' => 399, 'content_ids' => ['product-2'], 'num_items' => 1, 'order_id' => 'TEST-TIKTOK-GROUP',
        ]])->get('/checkout/success')->assertOk()->assertSee("window.ttq.track('PlaceAnOrder'", false)
            ->assertDontSee("ttq.track('Purchase'", false)->assertDontSee("ttq.track('CompletePayment'", false);
        $this->get('/checkout/success')->assertOk()->assertDontSee("window.ttq.track('PlaceAnOrder'", false);
    }

    public function test_only_successful_cart_addition_flash_triggers_add_to_cart_and_is_consumed_once(): void
    {
        $this->withSession(['tiktok.cart_added' => true])->get('/shop')->assertOk()->assertSee("ttq.track('AddToCart')", false);
        $this->get('/shop')->assertOk()->assertDontSee("ttq.track('AddToCart')", false);
    }

    public function test_unresolved_tiktok_macros_and_invalid_clicks_are_not_treated_as_ads(): void
    {
        $this->get('/shop?ttclid=__CLICKID__&utm_source[]=tiktok')->assertOk();
        $this->assertDatabaseHas('storefront_visits', ['source_type' => 'direct']);
        $this->assertArrayNotHasKey('ttclid', session('marketing_attribution'));
    }
}
