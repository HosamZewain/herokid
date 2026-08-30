<?php

namespace Tests\Feature;

use App\Models\ChildProfile;
use App\Models\ChildProfilePhoto;
use App\Models\CustomerAddress;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\MobilePromoCode;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileCommerceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::query()->updateOrCreate(['key' => 'story_global_price_enabled'], ['value' => '0']);
    }

    public function test_cart_uses_server_prices_is_idempotent_and_keeps_personalization_encrypted(): void
    {
        $user = User::factory()->create();
        $child = ChildProfile::create(['user_id' => $user->id, 'name' => 'Mariam', 'age' => 6, 'gender' => 'girl']);
        $photos = $this->photos($child);
        $story = $this->story('mobile-cart-story', 149);
        Sanctum::actingAs($user, ['mobile']);
        $key = (string) Str::uuid();
        $payload = [
            'item_type' => 'story',
            'story_id' => $story->id,
            'child_profile_id' => $child->uuid,
            'child_photo_ids' => $photos,
            'dedication' => 'إلى بطلتنا مريم',
            'idempotency_key' => $key,
            'client_price' => 1,
        ];

        $this->postJson('/api/v1/cart/items', $payload)
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 149)
            ->assertJsonPath('data.totals.subtotal', 149);
        $this->postJson('/api/v1/cart/items', $payload)
            ->assertCreated()
            ->assertJsonCount(1, 'data.items');

        $raw = (string) $this->getConnection()->table('mobile_cart_items')->value('personalization');
        $this->assertStringNotContainsString('إلى بطلتنا مريم', $raw);
        $this->assertDatabaseCount('mobile_cart_items', 1);
    }

    public function test_cash_on_delivery_checkout_creates_existing_orders_once_and_copies_private_photos(): void
    {
        $user = User::factory()->create(['name' => 'Parent']);
        $child = ChildProfile::create(['user_id' => $user->id, 'name' => 'Omar', 'age' => 7, 'gender' => 'boy', 'interests' => ['space']]);
        $photos = $this->photos($child);
        $story = $this->story('mobile-checkout-story', 200);
        $address = $this->address($user);
        $promo = MobilePromoCode::create([
            'code' => 'HERO25',
            'discount_type' => 'fixed',
            'discount_value' => 2500,
            'minimum_subtotal_cents' => 10000,
            'usage_limit' => 10,
            'per_user_limit' => 1,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/cart/items', [
            'item_type' => 'story',
            'story_id' => $story->id,
            'child_profile_id' => $child->uuid,
            'child_photo_ids' => $photos,
            'dedication' => 'إلى عمر',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $this->putJson('/api/v1/cart/promo-code', ['code' => 'hero25'])
            ->assertOk()->assertJsonPath('data.totals.discount', 25);

        $key = (string) Str::uuid();
        $payload = $this->checkoutPayload($address, $key, 'cash_on_delivery');
        $first = $this->postJson('/api/v1/checkout', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payment.requires_online_action', false)
            ->assertJsonCount(1, 'data.orders');
        $orderId = $first->json('data.orders.0.id');

        $this->postJson('/api/v1/checkout', $payload)
            ->assertCreated()
            ->assertJsonPath('data.orders.0.id', $orderId);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => $user->id,
            'order_source' => 'mobile',
            'discount_cents' => 2500,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery',
        ]);
        $order = Order::findOrFail($orderId);
        $this->assertCount(2, $order->uploaded_photos);
        foreach ($order->uploaded_photos as $path) {
            Storage::disk('local')->assertExists($path);
            $this->assertStringStartsWith('orders/photos/'.$order->id.'/mobile/', $path);
        }
        $this->assertDatabaseHas('consent_records', ['user_id' => $user->id, 'child_profile_id' => $child->id, 'consent_type' => 'order_image_processing', 'granted' => true]);
        $this->assertDatabaseHas('consent_records', ['user_id' => $user->id, 'consent_type' => 'checkout_terms', 'granted' => true]);
        $this->assertDatabaseHas('mobile_promo_code_redemptions', ['mobile_promo_code_id' => $promo->id, 'user_id' => $user->id, 'discount_cents' => 2500]);
        $this->assertSame(1, $promo->refresh()->used_count);
    }

    public function test_unconfigured_online_payment_never_creates_or_marks_an_order_paid(): void
    {
        $user = User::factory()->create();
        $child = ChildProfile::create(['user_id' => $user->id, 'name' => 'Laila', 'age' => 5]);
        $story = $this->story('mobile-online-payment-story', 180);
        $address = $this->address($user);
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/cart/items', [
            'item_type' => 'story',
            'story_id' => $story->id,
            'child_profile_id' => $child->uuid,
            'child_photo_ids' => $this->photos($child),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, (string) Str::uuid(), 'card'))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'payment_configuration_required')
            ->assertJsonPath('data.payment.status', 'configuration_required')
            ->assertJsonPath('data.payment.checkout_url', null)
            ->assertJsonCount(0, 'data.orders');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('mobile_payment_intents', ['user_id' => $user->id, 'method' => 'card', 'status' => 'configuration_required']);
    }

    public function test_product_stock_is_checked_and_decremented_only_during_checkout(): void
    {
        $user = User::factory()->create();
        $address = $this->address($user);
        $product = $this->product('mobile-maze', 5000, 2);
        Sanctum::actingAs($user, ['mobile']);
        $created = $this->postJson('/api/v1/cart/items', [
            'item_type' => 'product',
            'product_id' => $product->id,
            'quantity' => 2,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated()->assertJsonPath('data.totals.subtotal', 100);
        $this->assertSame(2, $product->refresh()->stock_quantity);
        $this->patchJson('/api/v1/cart/items/'.$created->json('data.items.0.id'), ['quantity' => 3])->assertUnprocessable();

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, (string) Str::uuid(), 'cash_on_delivery'))->assertCreated();
        $this->assertSame(0, $product->refresh()->stock_quantity);
        $this->assertDatabaseHas('order_items', ['product_id' => $product->id, 'quantity' => 2, 'total_price_cents' => 10000]);
    }

    public function test_mobile_product_variants_expose_media_and_keep_the_cart_snapshot_at_checkout(): void
    {
        $user = User::factory()->create();
        $address = $this->address($user);
        $product = $this->product('mobile-variant-product', 5000, 2);
        $product->update([
            'featured_image' => 'store/products/mobile-base.jpg',
            'gallery_images' => ['store/products/mobile-base-side.jpg'],
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'الحجم الكبير',
            'sku' => 'MOBILE-LARGE',
            'image' => 'store/products/variants/mobile-large.jpg',
            'gallery_images' => ['store/products/variants/gallery/mobile-large-side.jpg'],
            'attributes' => ['مقاس A3', 'إطار أبيض'],
            'price_override_cents' => 7500,
            'stock_quantity' => 2,
            'is_active' => true,
        ]);

        Sanctum::actingAs($user, ['mobile']);
        $this->getJson('/api/v1/catalog/product/'.$product->slug)
            ->assertOk()
            ->assertJsonPath('data.details.variants.0.name_ar', 'الحجم الكبير')
            ->assertJsonPath('data.details.variants.0.image_url', $variant->image_url)
            ->assertJsonPath('data.details.variants.0.gallery_images.0', $variant->gallery_image_urls[0])
            ->assertJsonPath('data.details.variants.0.attributes.0', 'مقاس A3')
            ->assertJsonPath('data.details.variants.0.available', true);

        $this->postJson('/api/v1/cart/items', [
            'item_type' => 'product',
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 1,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();

        $variant->update([
            'name_ar' => 'اسم جديد بعد السلة',
            'sku' => 'MOBILE-CHANGED',
            'image' => 'store/products/variants/mobile-changed.jpg',
            'gallery_images' => [],
            'attributes' => ['صفة جديدة'],
        ]);

        $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, (string) Str::uuid(), 'cash_on_delivery'))
            ->assertCreated();

        $item = Order::with('items')->firstOrFail()->items->firstOrFail();
        $this->assertSame('الحجم الكبير', $item->variant_snapshot['name_ar']);
        $this->assertSame('MOBILE-LARGE', $item->variant_snapshot['sku']);
        $this->assertSame('store/products/variants/mobile-large.jpg', $item->variant_snapshot['image']);
        $this->assertSame(['مقاس A3', 'إطار أبيض'], $item->variant_snapshot['attributes']);
    }

    public function test_delivered_story_order_can_be_reordered_idempotently_with_reusable_photos(): void
    {
        $user = User::factory()->create();
        $child = ChildProfile::create(['user_id' => $user->id, 'name' => 'Nour', 'age' => 8, 'is_active' => true]);
        $photos = $this->photos($child);
        $story = $this->story('mobile-reorder-story', 175);
        $address = $this->address($user);
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/cart/items', [
            'item_type' => 'story', 'story_id' => $story->id, 'child_profile_id' => $child->uuid,
            'child_photo_ids' => $photos, 'dedication' => 'For Nour', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $orderId = $this->postJson('/api/v1/checkout', $this->checkoutPayload($address, (string) Str::uuid(), 'cash_on_delivery'))
            ->assertCreated()->json('data.orders.0.id');
        Order::query()->whereKey($orderId)->update(['status' => 'delivered']);
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/orders/'.$orderId.'/reorder', ['idempotency_key' => $key])
            ->assertCreated()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.child.id', $child->uuid);
        $this->postJson('/api/v1/orders/'.$orderId.'/reorder', ['idempotency_key' => $key])
            ->assertCreated()->assertJsonCount(1, 'data.items');
    }

    /** @return array<int, string> */
    private function photos(ChildProfile $child): array
    {
        return collect(['one', 'two'])->map(function (string $suffix, int $index) use ($child): string {
            $path = 'mobile/children/'.$child->uuid.'/'.$suffix.'.png';
            Storage::disk('local')->put($path, 'private-image-'.$suffix);

            return ChildProfilePhoto::create([
                'child_profile_id' => $child->id,
                'disk' => 'local',
                'path' => $path,
                'original_filename' => $suffix.'.png',
                'mime_type' => 'image/png',
                'file_size' => 20,
                'checksum' => hash('sha256', 'private-image-'.$suffix),
                'sort_order' => $index,
                'status' => 'active',
                'reuse_consent_at' => now(),
            ])->uuid;
        })->all();
    }

    private function story(string $slug, int $price): Story
    {
        return Story::create([
            'title' => 'قصة '.$slug,
            'slug' => $slug,
            'short_desc' => 'قصة مخصصة',
            'language' => 'ar',
            'gender' => 'both',
            'age_range' => '6-9',
            'price' => $price,
            'active' => true,
        ]);
    }

    private function address(User $user): CustomerAddress
    {
        $country = DeliveryCountry::query()->where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::query()->where('delivery_country_id', $country->id)->firstOrFail();
        $governorate->update(['delivery_fee' => 50, 'active' => true]);

        return CustomerAddress::create([
            'user_id' => $user->id,
            'recipient_name' => $user->name,
            'phone' => '01012345678',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع HeroKid',
            'details' => 'عمارة 1',
            'is_default' => true,
        ]);
    }

    private function product(string $slug, int $priceCents, int $stock): Product
    {
        $category = ProductCategory::create(['name_ar' => 'أنشطة', 'slug' => $slug.'-category', 'is_active' => true, 'show_in_store' => true]);

        return Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب متاهات',
            'slug' => $slug,
            'price_cents' => $priceCents,
            'is_active' => true,
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'track_stock',
            'stock_quantity' => $stock,
        ]);
    }

    private function checkoutPayload(CustomerAddress $address, string $key, string $method): array
    {
        return [
            'address_id' => $address->uuid,
            'payment_method' => $method,
            'terms_accepted' => true,
            'terms_document_version' => '2026-08-03',
            'image_processing_consent' => true,
            'consent_document_version' => '2026-08-03',
            'idempotency_key' => $key,
        ];
    }
}
