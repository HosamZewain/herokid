<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Services\SalesAssistant\SalesCart;
use App\Services\SalesAssistant\SalesCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SalesAssistantFixtures;
use Tests\TestCase;

class SalesAssistantCheckoutTest extends TestCase
{
    use RefreshDatabase, SalesAssistantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        config(['media.private_disk' => 'local']);
        $this->salesEnabled();
        $this->openSales();
    }

    private function addStory(string $name = 'نور تجريبي'): void
    {
        $story = $this->salesStory();
        $this->get(route('sales.item', ['kind' => 'story', 'id' => $story->id, '_sales_assistant_embed' => 1]))->assertOk()->assertSee('data-sales-embedded="1"', false)->assertDontSee('data-floating-whatsapp', false);
        $this->post(route('cart.store', $story->slug), ['child_name' => $name, 'child_age' => 6, 'child_gender' => 'girl', 'next' => 'cart', '_sales_assistant_embed' => 1,
            'photos' => [UploadedFile::fake()->image('synthetic-one.png'), UploadedFile::fake()->image('synthetic-two.png')]])->assertSessionHasNoErrors()->assertRedirect(route('cart.index').'?_sales_assistant_embed=1');
    }

    private function details(): array
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $gov = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();

        return ['parent_name' => 'Synthetic Parent', 'phone' => '01000000000', 'delivery_country_id' => $country->id, 'delivery_governorate_id' => $gov->id, 'city' => 'Test city', 'street' => 'Synthetic street', '_sales_assistant_embed' => 1];
    }

    private function review(array $details = []): array
    {
        $response = $this->post(route('checkout.store'), $details ?: $this->details())->assertOk()->assertViewIs('front.sales-assistant.review')->assertSee('تأكيد الطلب');
        $this->assertDatabaseCount('orders', 0);

        return [...$response->viewData('fields'), '_sales_assistant_embed' => 1, '_sales_assistant_quote' => $response->viewData('quote')];
    }

    public function test_two_children_use_native_forms_review_then_one_checkout_with_original_ad_attribution(): void
    {
        $this->withSession(['marketing_attribution' => ['utm_source' => 'tiktok', 'utm_medium' => 'paid_social', 'ttclid' => 'synthetic-click']]);
        $this->addStory('الطفلة الأولى');
        $story = Story::firstOrFail();
        $this->post(route('cart.store', $story->slug), ['child_name' => 'الطفلة الثانية', 'child_age' => 4, 'child_gender' => 'girl', 'next' => 'cart', '_sales_assistant_embed' => 1,
            'photos' => [UploadedFile::fake()->image('child2-a.png'), UploadedFile::fake()->image('child2-b.png')]])->assertRedirect();
        $cart = session('cart.items');
        $photos = array_map(fn ($i) => $i['uploaded_photos'], $cart);
        $this->assertNotEquals(...array_values($photos));
        $payload = $this->review();
        $this->post(route('checkout.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('checkout.success').'?_sales_assistant_embed=1');
        $this->post(route('checkout.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('orders', 2);
        $orders = Order::all();
        $this->assertSame(1, $orders->pluck('checkout_group_key')->unique()->count());
        $this->assertEqualsCanonicalizing(['الطفلة الأولى', 'الطفلة الثانية'], $orders->pluck('child_name')->all());
        foreach ($orders as $order) {
            $this->assertSame('website', $order->order_source);
            $this->assertSame('tiktok', data_get($order->delivery_details, 'marketing_attribution.utm_source'));
            $this->assertSame('new', $order->status);
            $this->assertEquals(0, $order->paid_amount);
        }
        $this->assertDatabaseCount('checkout_submissions', 1);
        $this->assertDatabaseHas('sales_assistant_events', ['type' => 'order_created']);
        $this->assertSame(1, DB::table('sales_assistant_events')->where('type', 'order_created')->count());
        Http::assertNothingSent();
    }

    public function test_changed_price_invalidates_confirmation_and_new_review_uses_current_price(): void
    {
        $this->addStory();
        $payload = $this->review();
        Story::firstOrFail()->update(['price' => 299]);
        $this->post(route('checkout.store'), $payload)->assertRedirect()->assertSessionHasErrors();
        $this->assertDatabaseCount('orders', 0);
        $payload = $this->review();
        $this->assertSame(299.0, (float) array_values(session('cart.items'))[0]['story_price']);
        $this->post(route('checkout.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_changed_child_or_delivery_details_and_expired_quote_require_fresh_review(): void
    {
        $this->addStory();
        $payload = $this->review();
        $payload['street'] = 'Changed synthetic street';
        $this->post(route('checkout.store'), $payload)->assertRedirect()->assertSessionHasErrors('sales_checkout');
        $payload = $this->review();
        $this->travel(16)->minutes();
        $this->post(route('checkout.store'), $payload)->assertRedirect()->assertSessionHasErrors('sales_checkout');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_missing_photo_blocks_confirmation_and_private_photos_never_appear_in_model_summary(): void
    {
        $this->addStory('اسم سري');
        $payload = $this->review();
        $cart = session('cart.items');
        $path = array_values($cart)[0]['uploaded_photos'][0];
        $json = json_encode(app(SalesCart::class)->summary(request()));
        $this->assertStringNotContainsString('اسم سري', $json);
        $this->assertStringNotContainsString($path, $json);
        $hash = hash('sha256', $path);
        $this->get(route('sales.photo', $hash))->assertOk();
        Storage::disk('local')->delete($path);
        $this->post(route('checkout.store'), $payload)->assertRedirect()->assertSessionHasErrors('sales_checkout');
        $this->assertDatabaseCount('orders', 0);
        $this->get(route('sales.photo', str_repeat('a', 64)))->assertNotFound();
    }

    public function test_another_session_cannot_view_cart_photos_or_confirm_quote(): void
    {
        $this->addStory();
        $payload = $this->review();
        $path = array_values(session('cart.items'))[0]['uploaded_photos'][0];
        $this->withCookie(config('session.cookie'), 'unrelated-session-id');
        $this->get(route('sales.photo', hash('sha256', $path)))->assertNotFound();
        $this->post(route('checkout.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_nonpersonalized_product_and_package_keep_native_cart_pricing_and_checkout(): void
    {
        $product = Product::create(['product_category_id' => ProductCategory::firstOrFail()->id, 'name_ar' => 'بازل تجريبي', 'slug' => 'synthetic-puzzle', 'price_cents' => 9900, 'is_active' => true, 'inventory_mode' => 'track_stock', 'stock_quantity' => 20, 'personalization_mode' => 'none', 'purchase_mode' => 'standalone']);
        $package = PricingPackage::create(['name' => 'باقة تجربة', 'slug' => 'synthetic-package', 'price' => 150, 'active' => true, 'show_in_store' => true, 'story_count' => 0, 'applies_to_all_stories' => true]);
        $package->items()->create(['product_id' => $product->id, 'quantity' => 2]);
        Cache::forget(SalesCatalog::CACHE_KEY);
        $this->get(route('sales.item', ['kind' => 'package', 'id' => $package->id, '_sales_assistant_embed' => 1]))->assertOk();
        $this->post(route('cart.packages.store', $package), ['_sales_assistant_embed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('cart.products.store', $product), ['quantity' => 1, '_sales_assistant_embed' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $payload = $this->review();
        $this->post(route('checkout.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(24900, Order::with('items')->get()->flatMap->items->sum('total_price_cents'));
        $this->assertSame(17, (int) $product->fresh()->stock_quantity);
    }

    public function test_cart_edit_preserves_line_child_photo_ownership_and_can_replace_one_photo(): void
    {
        $this->addStory('اسم الطفل الأول');
        $cart = session('cart.items');
        $key = array_key_first($cart);
        $paths = $cart[$key]['uploaded_photos'];
        $this->get(route('sales.cart-edit', ['_sales_assistant_embed' => 1]))->assertOk()->assertSee('اسم الطفل الأول');
        $this->post(route('sales.cart-update', $key), ['_sales_assistant_embed' => 1, 'child_name' => 'اسم مصحح', 'child_age' => 6, 'child_gender' => 'girl', 'story_language' => 'ar', 'keep' => [hash('sha256', $paths[0])],
            'photos' => [UploadedFile::fake()->image('replacement.png')]])->assertSessionHasNoErrors()->assertRedirect();
        $updated = session('cart.items');
        $this->assertSame([$key], array_keys($updated));
        $this->assertSame('اسم مصحح', $updated[$key]['child_name']);
        $this->assertCount(2, $updated[$key]['uploaded_photos']);
        $this->assertSame($paths[0], $updated[$key]['uploaded_photos'][0]);
        $this->assertNotSame($paths[1], $updated[$key]['uploaded_photos'][1]);
        $this->post(route('sales.cart-update', $key), ['_sales_assistant_embed' => 1, 'child_name' => 'تزوير', 'child_age' => 6, 'child_gender' => 'girl', 'story_language' => 'ar', 'keep' => [str_repeat('a', 64), hash('sha256', $paths[0])]])->assertSessionHasErrors('photos');
        $this->assertSame('اسم مصحح', session('cart.items')[$key]['child_name']);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_inactive_item_is_not_available_and_session_flags_do_not_embed_other_routes(): void
    {
        $story = $this->salesStory();
        $story->update(['active' => false]);
        Cache::forget(SalesCatalog::CACHE_KEY);
        $this->get(route('sales.item', ['kind' => 'story', 'id' => $story->id]))->assertNotFound();
        $this->get('/?_sales_assistant_embed=1')->assertOk()->assertSee('data-sales-embedded="0"', false);
    }
}
