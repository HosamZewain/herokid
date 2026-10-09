<?php

namespace Tests\Feature;

use App\Models\CustomerAddress;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileCheckoutQuoteTest extends TestCase
{
    use RefreshDatabase;

    private function setupCart(): array
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $governorate->update(['delivery_fee' => 50, 'active' => true]);
        $address = CustomerAddress::create(['user_id' => $user->id, 'recipient_name' => 'QA Parent', 'phone' => '01012345678',
            'delivery_country_id' => $country->id, 'delivery_governorate_id' => $governorate->id, 'city' => 'QA City', 'street' => 'QA Street', 'details' => '1']);
        $category = ProductCategory::create(['name_ar' => 'أنشطة', 'slug' => 'quote-category', 'is_active' => true, 'show_in_store' => true]);
        $product = Product::create(['product_category_id' => $category->id, 'name_ar' => 'QA Product', 'slug' => 'quote-product', 'price_cents' => 10000,
            'is_active' => true, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone', 'personalization_mode' => 'none', 'inventory_mode' => 'no_tracking']);
        $this->postJson('/api/v1/cart/items', ['item_type' => 'product', 'product_id' => $product->id, 'quantity' => 2, 'idempotency_key' => (string) Str::uuid()])->assertCreated();

        return [$user, $address, $governorate, $product];
    }

    private function checkoutPayload(CustomerAddress $address, string $fingerprint): array
    {
        return ['address_id' => $address->uuid, 'payment_method' => 'cash_on_delivery', 'terms_accepted' => true,
            'terms_document_version' => 'test', 'image_processing_consent' => false, 'consent_document_version' => 'test',
            'idempotency_key' => (string) Str::uuid(), 'quote_fingerprint' => $fingerprint];
    }

    public function test_quote_shows_delivery_before_confirmation_without_creating_orders_or_consents(): void
    {
        [, $address] = $this->setupCart();
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()
            ->assertJsonPath('data.totals.subtotal', 200)->assertJsonPath('data.totals.delivery', 50)->assertJsonPath('data.totals.total', 250)
            ->assertJsonPath('data.payment_methods', ['cash_on_delivery'])->assertJsonCount(1, 'data.items');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('mobile_checkout_attempts', 0);
        $this->assertDatabaseCount('consent_records', 0);
    }

    public function test_changed_delivery_or_product_price_requires_new_review_and_does_not_create_an_order(): void
    {
        [, $address, $governorate, $product] = $this->setupCart();
        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()->json('data.fingerprint');
        $governorate->update(['delivery_fee' => 75]);
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, $quote))->assertUnprocessable()->assertJsonValidationErrors('quote_fingerprint');
        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()->json('data.fingerprint');
        $product->update(['price_cents' => 15000]);
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, $quote))->assertUnprocessable()->assertJsonValidationErrors('quote_fingerprint');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('mobile_checkout_attempts', 0);
    }

    public function test_current_quote_can_be_confirmed_and_retry_returns_the_same_completed_order(): void
    {
        [, $address] = $this->setupCart();
        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()->json('data.fingerprint');
        $payload = $this->checkoutPayload($address, $quote);
        $order = $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.totals.total', 250)->json('data.orders.0.id');
        $this->postJson('/api/v1/checkout', $payload)->assertCreated()->assertJsonPath('data.orders.0.id', $order);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_quote_cannot_use_another_customers_address(): void
    {
        [, $address] = $this->setupCart();
        Sanctum::actingAs(User::factory()->create(), ['mobile']);
        $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertUnprocessable()->assertJsonValidationErrors('address_id');
    }

    public function test_missing_quote_cannot_silently_confirm_even_if_the_price_has_not_changed(): void
    {
        [, $address] = $this->setupCart();
        $payload = $this->checkoutPayload($address, str_repeat('0', 64));
        unset($payload['quote_fingerprint']);
        $this->postJson('/api/v1/checkout', $payload)->assertUnprocessable()->assertJsonValidationErrors('quote_fingerprint');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('consent_records', 0);
        $this->assertDatabaseCount('mobile_checkout_attempts', 0);
    }

    public function test_address_and_admin_personalization_changes_require_review_again(): void
    {
        [, $address, , $product] = $this->setupCart();
        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()->json('data.fingerprint');
        $address->update(['street' => 'A different address']);
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, $quote))
            ->assertUnprocessable()->assertJsonValidationErrors('quote_fingerprint');
        $quote = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()->json('data.fingerprint');
        $product->update(['personalization_mode' => 'collect_child_details']);
        $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, $quote))
            ->assertUnprocessable()->assertJsonValidationErrors('quote_fingerprint');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_new_commerce_routes_exist_and_require_authentication(): void
    {
        $this->postJson('/api/v1/cart/items/batch', [])->assertUnauthorized();
        $this->postJson('/api/v1/checkout/quote', [])->assertUnauthorized();
    }
}
