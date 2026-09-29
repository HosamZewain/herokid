<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Cart\WebsiteCartPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProductSaleScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_sale_is_active_only_inside_its_configured_window(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');
        $product = $this->product([
            'price_cents' => 29000,
            'sale_price_cents' => 19900,
            'sale_starts_at' => now()->subMinute(),
            'sale_ends_at' => now()->addHour(),
        ]);

        $this->assertTrue($product->hasActiveSale());
        $this->assertSame(19900, $product->effectivePriceCents());

        Carbon::setTestNow('2026-09-29 13:00:00');
        $this->assertFalse($product->fresh()->hasActiveSale());
        $this->assertSame(29000, $product->fresh()->effectivePriceCents());
    }

    public function test_scheduled_sale_does_not_start_early(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');
        $product = $this->product([
            'price_cents' => 29000,
            'sale_price_cents' => 19900,
            'sale_starts_at' => now()->addMinute(),
            'sale_ends_at' => now()->addHour(),
        ]);

        $this->assertFalse($product->hasActiveSale());
        $this->assertSame(29000, $product->effectivePriceCents());
    }

    public function test_variant_adjustment_uses_sale_while_override_remains_authoritative(): void
    {
        $product = $this->activeSaleProduct();
        $adjusted = $product->variants()->create([
            'name_ar' => 'كبير',
            'price_adjustment_cents' => 2000,
            'is_active' => true,
        ]);
        $override = $product->variants()->create([
            'name_ar' => 'خاص',
            'price_override_cents' => 41000,
            'is_active' => true,
        ]);

        $this->assertSame(21900, $product->effectivePriceCents($adjusted));
        $this->assertSame(31000, $product->regularPriceCents($adjusted));
        $this->assertTrue($product->hasActiveSaleForVariant($adjusted));
        $this->assertSame(41000, $product->effectivePriceCents($override));
        $this->assertFalse($product->hasActiveSaleForVariant($override));
    }

    public function test_admin_can_save_a_cairo_timed_sale(): void
    {
        $product = $this->product();

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->put(route('admin.products.update', $product), $this->adminPayload([
                'sale_price' => 199,
                'sale_starts_at' => '2026-09-29T16:30',
                'sale_ends_at' => '2026-09-30T16:30',
            ]))
            ->assertRedirect(route('admin.products.edit', ['product' => 'sale-product-admin']));

        $product->refresh();
        $this->assertSame(19900, $product->sale_price_cents);
        $this->assertSame('2026-09-29 13:30:00', $product->sale_starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 13:30:00', $product->sale_ends_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_admin_rejects_invalid_sale_price_and_window(): void
    {
        $product = $this->product();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->from(route('admin.products.edit', $product))
            ->put(route('admin.products.update', $product), $this->adminPayload([
                'sale_price' => 300,
                'sale_starts_at' => '2026-09-30T16:30',
                'sale_ends_at' => '2026-09-29T16:30',
            ]))
            ->assertRedirect(route('admin.products.edit', $product))
            ->assertSessionHasErrors(['sale_price', 'sale_ends_at']);

        $this->assertNull($product->fresh()->sale_price_cents);
    }

    public function test_product_page_shows_original_price_discount_and_countdown_for_active_sale(): void
    {
        $product = $this->activeSaleProduct();

        $response = $this->get(route('shop.product.show', $product))->assertOk();

        $response->assertSee('١٩٩ ج.م')
            ->assertSee('بدلًا من ٢٩٠ ج.م')
            ->assertSee('ينتهي العرض خلال')
            ->assertSee('data-sale-countdown', false);
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function test_expired_sale_page_and_cart_use_regular_price(): void
    {
        $product = $this->product([
            'price_cents' => 29000,
            'sale_price_cents' => 19900,
            'sale_starts_at' => now()->subDays(2),
            'sale_ends_at' => now()->subDay(),
        ]);

        $this->get(route('shop.product.show', $product))
            ->assertOk()
            ->assertSee('٢٩٠ ج.م')
            ->assertDontSee('data-sale-ends-at=', false);

        $this->post(route('cart.products.store', $product), ['quantity' => 2])
            ->assertRedirect(route('cart.index'));

        $item = collect(session('cart.items'))->first();
        $this->assertSame(29000, $item['unit_price_cents']);
        $this->assertSame(58000, $item['line_total_cents']);
    }

    public function test_active_sale_price_is_used_through_checkout(): void
    {
        $product = $this->activeSaleProduct();
        [$country, $governorate] = $this->deliveryLocation();

        $this->post(route('cart.products.store', $product), ['quantity' => 2]);
        $this->get(route('cart.index'))->assertOk()->assertSee('٣٩٨ ج.م');
        $this->post(route('checkout.store'), $this->checkoutPayload($country, $governorate))
            ->assertRedirect(route('checkout.success'));

        $item = Order::with('items')->firstOrFail()->items->firstOrFail();
        $this->assertSame(19900, $item->unit_price_cents);
        $this->assertSame(39800, $item->total_price_cents);
    }

    public function test_cart_refreshes_an_expired_sale_before_checkout_and_purchase_can_continue(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');
        $product = $this->activeSaleProduct(now()->addMinute());
        [$country, $governorate] = $this->deliveryLocation();
        $this->post(route('cart.products.store', $product), ['quantity' => 1]);
        $this->assertSame(19900, collect(session('cart.items'))->first()['unit_price_cents']);

        Carbon::setTestNow('2026-09-29 12:02:00');
        $this->get(route('cart.index'))->assertOk()->assertSee('٢٩٠ ج.م');
        $this->assertSame(29000, collect(session('cart.items'))->first()['unit_price_cents']);

        $this->post(route('checkout.store'), $this->checkoutPayload($country, $governorate))
            ->assertRedirect(route('checkout.success'));
        $this->assertSame(29000, Order::with('items')->firstOrFail()->items->firstOrFail()->unit_price_cents);
    }

    public function test_checkout_redirects_safely_if_sale_expires_after_cart_page_was_loaded(): void
    {
        Carbon::setTestNow('2026-09-29 12:00:00');
        $product = $this->activeSaleProduct(now()->addMinute());
        [$country, $governorate] = $this->deliveryLocation();
        $this->post(route('cart.products.store', $product), ['quantity' => 1]);

        Carbon::setTestNow('2026-09-29 12:02:00');
        $this->post(route('checkout.store'), $this->checkoutPayload($country, $governorate))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(29000, collect(session('cart.items'))->first()['unit_price_cents']);
    }

    public function test_package_price_snapshot_is_not_repriced_from_product_catalog(): void
    {
        $product = $this->activeSaleProduct();
        $cart = [
            'package-product' => [
                'item_type' => 'product',
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price_cents' => 12345,
                'line_total_cents' => 12345,
                'package_snapshot' => ['package_id' => 10],
            ],
        ];

        $this->assertSame($cart, app(WebsiteCartPricingService::class)->refresh($cart));
    }

    private function activeSaleProduct($endsAt = null): Product
    {
        return $this->product([
            'price_cents' => 29000,
            'sale_price_cents' => 19900,
            'sale_starts_at' => now()->subHour(),
            'sale_ends_at' => $endsAt ?: now()->addDay(),
        ]);
    }

    private function product(array $overrides = []): Product
    {
        $category = ProductCategory::firstOrCreate(
            ['slug' => 'sale-tests'],
            ['name_ar' => 'منتجات التخفيض', 'is_active' => true, 'show_in_store' => true],
        );

        return Product::create(array_merge([
            'product_category_id' => $category->id,
            'name_ar' => 'منتج التخفيض',
            'slug' => 'sale-product-'.uniqid(),
            'price_cents' => 29000,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ], $overrides));
    }

    private function adminPayload(array $overrides = []): array
    {
        return array_merge([
            'product_category_id' => ProductCategory::firstOrFail()->id,
            'name_ar' => 'منتج التخفيض',
            'name_en' => 'Sale product',
            'slug' => 'sale-product-admin',
            'price' => 290,
            'sale_price' => null,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
            'is_active' => 1,
        ], $overrides);
    }

    /** @return array{DeliveryCountry, DeliveryGovernorate} */
    private function deliveryLocation(): array
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)
            ->where('name', 'القاهرة')
            ->firstOrFail();

        return [$country, $governorate];
    }

    private function checkoutPayload(DeliveryCountry $country, DeliveryGovernorate $governorate): array
    {
        return [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'Cairo',
            'street' => 'Street 1',
            'address_details' => 'Building 2',
        ];
    }
}
