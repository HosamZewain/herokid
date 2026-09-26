<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\MobilePromoCode;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteDiscountCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_create_and_edit_a_limited_website_code(): void
    {
        $admin = $this->admin(['store.discount_codes.view', 'store.discount_codes.manage']);

        $this->actingAs($admin)->get(route('admin.discount-codes.index'))
            ->assertOk()
            ->assertSee('أكواد الخصم');

        $this->actingAs($admin)->post(route('admin.discount-codes.store'), [
            'code' => 'hero20',
            'name' => 'عرض الموقع',
            'discount_type' => 'percent',
            'discount_value' => 20,
            'minimum_subtotal' => 300,
            'maximum_discount' => 100,
            'usage_limit' => 50,
            'per_user_limit' => 2,
            'website_enabled' => 1,
            'mobile_enabled' => 0,
            'is_active' => 1,
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addWeek()->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('admin.discount-codes.index'));

        $code = MobilePromoCode::query()->where('code', 'HERO20')->firstOrFail();
        $this->assertSame(2000, $code->discount_value);
        $this->assertSame(30000, $code->minimum_subtotal_cents);
        $this->assertSame(10000, $code->maximum_discount_cents);
        $this->assertTrue($code->website_enabled);
        $this->assertFalse($code->mobile_enabled);

        $this->actingAs($admin)->put(route('admin.discount-codes.update', $code), [
            'code' => 'hero25',
            'name' => 'عرض معدل',
            'discount_type' => 'percent',
            'discount_value' => 25,
            'minimum_subtotal' => 0,
            'usage_limit' => '',
            'per_user_limit' => '',
            'website_enabled' => 1,
            'mobile_enabled' => 1,
            'is_active' => 1,
        ])->assertRedirect(route('admin.discount-codes.index'));

        $this->assertDatabaseHas('mobile_promo_codes', [
            'id' => $code->id,
            'code' => 'HERO25',
            'discount_value' => 2500,
            'usage_limit' => null,
            'website_enabled' => true,
            'mobile_enabled' => true,
        ]);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'discount_code.created', 'subject_id' => $code->id]);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'discount_code.updated', 'subject_id' => $code->id]);
    }

    public function test_unknown_or_invalid_admin_configuration_is_rejected(): void
    {
        $admin = $this->admin(['store.discount_codes.view', 'store.discount_codes.manage']);

        $this->actingAs($admin)->post(route('admin.discount-codes.store'), [
            'code' => 'BAD',
            'discount_type' => 'percent',
            'discount_value' => 101,
            'website_enabled' => 1,
            'is_active' => 1,
        ])->assertSessionHasErrors('discount_value');

        $this->actingAs($admin)->post(route('admin.discount-codes.store'), [
            'code' => 'NOCHANNEL',
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'website_enabled' => 0,
            'mobile_enabled' => 0,
            'is_active' => 1,
        ])->assertSessionHasErrors('website_enabled');

        $this->assertDatabaseCount('mobile_promo_codes', 0);
    }

    public function test_view_only_admin_cannot_manage_codes(): void
    {
        $admin = $this->admin(['store.discount_codes.view']);

        $this->actingAs($admin)->get(route('admin.discount-codes.index'))->assertOk();
        $this->actingAs($admin)->post(route('admin.discount-codes.store'), [])->assertForbidden();
    }

    public function test_website_code_applies_to_products_not_delivery_and_is_redeemed_once(): void
    {
        $product = $this->product(50000);
        $code = $this->promo([
            'code' => 'SAVE10',
            'discount_type' => 'percent',
            'discount_value' => 1000,
            'minimum_subtotal_cents' => 30000,
            'usage_limit' => 5,
        ]);

        $this->withSession(['cart.items' => $this->cart($product)])
            ->post(route('cart.promo-code.store'), ['promo_code' => 'save10'])
            ->assertRedirect();
        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee('SAVE10')
            ->assertSee('data-cart-discount', false);

        [$country, $governorate] = $this->delivery(90);
        $this->post(route('checkout.store'), $this->checkoutPayload($country, $governorate, '01012345678'))
            ->assertRedirect(route('checkout.success'));

        $order = Order::query()->firstOrFail();
        $this->assertSame(5000, $order->discount_cents);
        $this->assertSame('كود خصم: SAVE10', $order->discount_reason);
        $this->assertSame(540.0, (float) $order->delivery_details['total']);
        $this->assertSame(90.0, (float) $order->delivery_details['delivery_fee']);
        $this->assertDatabaseHas('website_promo_code_redemptions', [
            'mobile_promo_code_id' => $code->id,
            'checkout_group_key' => $order->checkout_group_key,
            'discount_cents' => 5000,
        ]);
        $this->assertSame(1, $code->refresh()->used_count);
    }

    public function test_fixed_code_is_capped_by_subtotal_and_unlimited_usage_is_supported(): void
    {
        $product = $this->product(5000);
        $code = $this->promo([
            'code' => 'FREEITEMS',
            'discount_type' => 'fixed',
            'discount_value' => 10000,
            'usage_limit' => null,
        ]);

        $this->withSession(['cart.items' => $this->cart($product)])
            ->post(route('cart.promo-code.store'), ['promo_code' => 'FREEITEMS'])
            ->assertSessionHasNoErrors();
        [$country, $governorate] = $this->delivery(75);
        $this->post(route('checkout.store'), $this->checkoutPayload($country, $governorate, '01011111111'))
            ->assertRedirect(route('checkout.success'));

        $order = Order::query()->firstOrFail();
        $this->assertSame(5000, $order->discount_cents);
        $this->assertSame(75.0, (float) $order->delivery_details['total']);
        $this->assertNull($code->usage_limit);
    }

    public function test_expired_inactive_minimum_and_exhausted_codes_are_rejected(): void
    {
        $product = $this->product(10000);
        $cart = ['cart.items' => $this->cart($product)];

        foreach ([
            ['code' => 'EXPIRED', 'ends_at' => now()->subMinute()],
            ['code' => 'INACTIVE', 'is_active' => false],
            ['code' => 'MINIMUM', 'minimum_subtotal_cents' => 20000],
            ['code' => 'USEDUP', 'usage_limit' => 1, 'used_count' => 1],
        ] as $attributes) {
            $code = $this->promo($attributes);
            $this->withSession($cart)->post(route('cart.promo-code.store'), ['promo_code' => $code->code])
                ->assertSessionHasErrors('promo_code');
        }
    }

    public function test_per_customer_limit_is_enforced_using_normalized_phone(): void
    {
        $product = $this->product(10000);
        $code = $this->promo(['code' => 'ONCE', 'per_user_limit' => 1]);
        $phoneHash = hash('sha256', '201012345678');
        $this->getConnection()->table('website_promo_code_redemptions')->insert([
            'mobile_promo_code_id' => $code->id,
            'checkout_group_key' => 'CHK-OLD',
            'customer_phone_hash' => $phoneHash,
            'discount_cents' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withSession(['cart.items' => $this->cart($product)])
            ->post(route('cart.promo-code.store'), ['promo_code' => 'ONCE'])
            ->assertSessionHasNoErrors();
        [$country, $governorate] = $this->delivery(0);
        $this->post(route('checkout.store'), $this->checkoutPayload($country, $governorate, '01012345678'))
            ->assertSessionHasErrors('promo_code');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $code->refresh()->used_count);
    }

    public function test_existing_mobile_code_defaults_to_mobile_only(): void
    {
        $code = MobilePromoCode::query()->create([
            'code' => 'MOBILEONLY',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'is_active' => true,
        ])->refresh();

        $this->assertTrue($code->mobile_enabled);
        $this->assertFalse($code->website_enabled);
        $this->withSession(['cart.items' => $this->cart($this->product(10000))])
            ->post(route('cart.promo-code.store'), ['promo_code' => 'MOBILEONLY'])
            ->assertSessionHasErrors('promo_code');
    }

    private function admin(array $permissions): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->permissions()->sync(Permission::query()->whereIn('key', $permissions)->pluck('id'));

        return $admin;
    }

    private function promo(array $overrides = []): MobilePromoCode
    {
        return MobilePromoCode::query()->create(array_merge([
            'code' => 'PROMO',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'minimum_subtotal_cents' => 0,
            'is_active' => true,
            'website_enabled' => true,
            'mobile_enabled' => false,
        ], $overrides));
    }

    private function product(int $priceCents): Product
    {
        $category = ProductCategory::query()->create([
            'name_ar' => 'اختبار الخصم',
            'slug' => 'discount-test',
            'is_active' => true,
            'show_in_store' => true,
        ]);

        return Product::query()->create([
            'product_category_id' => $category->id,
            'name_ar' => 'منتج اختبار',
            'slug' => 'discount-product-'.$priceCents,
            'price_cents' => $priceCents,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function cart(Product $product): array
    {
        return ['product-key' => [
            'key' => 'product-key',
            'item_type' => 'product',
            'product_id' => $product->id,
            'product_title' => $product->name_ar,
            'product_slug' => $product->slug,
            'unit_price_cents' => $product->price_cents,
            'quantity' => 1,
            'line_total_cents' => $product->price_cents,
            'personalization_mode' => 'none',
        ]];
    }

    /** @return array{DeliveryCountry, DeliveryGovernorate} */
    private function delivery(float $fee): array
    {
        $country = DeliveryCountry::query()->where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::query()->where('delivery_country_id', $country->id)->firstOrFail();
        $governorate->update(['delivery_fee' => $fee]);

        return [$country, $governorate];
    }

    private function checkoutPayload(DeliveryCountry $country, DeliveryGovernorate $governorate, string $phone): array
    {
        return [
            'parent_name' => 'Discount Customer',
            'phone' => $phone,
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'Cairo',
            'street' => 'Test street 10',
        ];
    }
}
