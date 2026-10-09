<?php

namespace Tests\Feature;

use App\Models\ChildProfile;
use App\Models\ChildProfilePhoto;
use App\Models\CustomerAddress;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\MobileCartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\User;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileProductPersonalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Setting::query()->updateOrCreate(['key' => 'cash_on_delivery_enabled'], ['value' => '1']);
    }

    public function test_product_schema_is_enforced_and_one_photo_is_valid_unlike_a_story(): void
    {
        $user = User::factory()->create();
        $child = $this->child($user, 'مريم محمد');
        $photo = $this->photo($child, 'front');
        $product = $this->product();
        Sanctum::actingAs($user, ['mobile']);
        $data = $this->item($product, $child, [$photo->uuid]);
        $invalid = $data;
        unset($invalid['personalization']['school_name']);
        $this->postJson('/api/v1/cart/items', $invalid)->assertUnprocessable()->assertJsonValidationErrors('personalization.school_name');
        $response = $this->postJson('/api/v1/cart/items', $data)->assertCreated()
            ->assertJsonPath('data.items.0.personalization.personalization_snapshot.school_name', 'مدرسة الأمل')
            ->assertJsonPath('data.items.0.personalization.personalization_snapshot.child_age', null)
            ->assertJsonPath('data.items.0.unit_price', 345);
        $this->postJson('/api/v1/cart/items', $data)->assertCreated()->assertJsonCount(1, 'data.items');
        $this->assertNotEmpty($response->json('data.items.0.child.id'));
        $raw = (string) $this->getConnection()->table('mobile_cart_items')->value('personalization');
        $this->assertStringNotContainsString('مدرسة الأمل', $raw);
    }

    public function test_product_photos_must_belong_to_the_selected_child_and_account(): void
    {
        $user = User::factory()->create();
        $child = $this->child($user, 'First child');
        $sibling = $this->child($user, 'Sibling');
        $outsider = $this->child(User::factory()->create(), 'Other account');
        $product = $this->product();
        Sanctum::actingAs($user, ['mobile']);
        foreach ([$sibling, $outsider] as $wrongChild) {
            $photo = $this->photo($wrongChild, 'private');
            $this->postJson('/api/v1/cart/items', $this->item($product, $child, [$photo->uuid]))
                ->assertUnprocessable()->assertJsonValidationErrors('child_photo_ids');
        }
        $this->assertDatabaseCount('mobile_cart_items', 0);
    }

    public function test_no_photo_schema_needs_neither_photos_nor_a_profile_and_ignores_disabled_fields(): void
    {
        $user = User::factory()->create();
        $product = $this->product(false);
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/cart/items', [
            'item_type' => 'product', 'product_id' => $product->id, 'idempotency_key' => (string) Str::uuid(),
            'personalization' => ['child_name' => 'الاسم المطلوب', 'school_name' => 'مدرسة الأمل', 'child_age' => 99, 'arbitrary_field' => 'not stored'],
        ])->assertCreated()->assertJsonPath('data.items.0.personalization.personalization_snapshot.child_age', null);
        $snapshot = MobileCartItem::firstOrFail()->personalization['personalization_snapshot'];
        $this->assertSame('الاسم المطلوب', $snapshot['child_name']);
        $this->assertArrayNotHasKey('arbitrary_field', $snapshot);
        $this->checkout($user)->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame('الاسم المطلوب', $order->child_name);
        $this->assertSame([], $order->uploaded_photos);
    }

    public function test_two_children_create_separate_production_orders_with_exact_fields_and_ordered_private_photos(): void
    {
        $user = User::factory()->create();
        $first = $this->child($user, 'مريم محمد');
        $second = $this->child($user, 'عمر أحمد');
        $front = $this->photo($first, 'front');
        $side = $this->photo($first, 'side');
        $other = $this->photo($second, 'other-child');
        $product = $this->product();
        $product->update(['production_prompt_template' => 'Produce {{child_full_name}} at {{school_name}} / {{class_name}}']);
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/cart/items', $this->item($product, $first, [$side->uuid, $front->uuid]))->assertCreated();
        $this->postJson('/api/v1/cart/items', $this->item($product, $second, [$other->uuid]))->assertCreated();
        $first->update(['name' => 'Changed reusable profile']);
        $response = $this->checkout($user)->assertCreated()->assertJsonCount(2, 'data.orders');
        $order = Order::findOrFail($response->json('data.orders.0.id'));
        $this->assertSame('مريم محمد', $order->child_name);
        $this->assertSame('مدرسة الأمل', $order->items->first()->personalization_snapshot['school_name']);
        $this->assertSame('KG-2 Blue', $order->items->first()->personalization_snapshot['class_name']);
        $this->assertSame('side', Storage::disk('local')->get($order->uploaded_photos[0]));
        $this->assertSame('front', Storage::disk('local')->get($order->uploaded_photos[1]));
        $otherOrder = Order::findOrFail($response->json('data.orders.1.id'));
        $this->assertSame('عمر أحمد', $otherOrder->child_name);
        $this->assertSame('other-child', Storage::disk('local')->get($otherOrder->uploaded_photos[0]));
        $this->assertSame($order->checkout_group_key, $otherOrder->checkout_group_key);
        $this->assertSame(740, $response->json('data.totals.total'));

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true, 'agent_api_enabled' => true]);
        Sanctum::actingAs($admin, ['agent', 'agent:orders.read']);
        $studio = $this->getJson('/api/agent/studio/orders/'.$order->order_number)->assertOk()->assertJsonCount(2, 'production_units');
        $this->assertSame([$order->id, $otherOrder->id], array_column($studio->json('production_units'), 'order_id'));
        $this->assertCount(2, $studio->json('production_units.0.reference_files'));
        $this->assertCount(1, $studio->json('production_units.1.reference_files'));
        $this->assertSame('مريم محمد', collect($studio->json('production_units.0.personalization'))->firstWhere('key', 'child_name')['value']);
        $this->assertSame('عمر أحمد', collect($studio->json('production_units.1.personalization'))->firstWhere('key', 'child_name')['value']);
        $this->actingAs($admin, 'web')->get('/admin/orders/groups/'.$order->id)->assertOk()
            ->assertSee('مدرسة الأمل')->assertSee('KG-2 Blue')->assertSee('مريم محمد')->assertSee('عمر أحمد');
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame('new', $otherOrder->fresh()->status);
    }

    public function test_deleted_photo_between_cart_and_checkout_fails_without_leaving_orders_or_copied_files(): void
    {
        $user = User::factory()->create();
        $child = $this->child($user, 'مريم');
        $photo = $this->photo($child, 'front');
        $product = $this->product();
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/cart/items', $this->item($product, $child, [$photo->uuid]))->assertCreated();
        $photo->update(['status' => 'deleted', 'deleted_at' => now()]);
        $this->checkout($user)->assertUnprocessable()->assertJsonValidationErrors('child_photo_ids');
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('orders/photos'));
    }

    private function product(bool $photos = true): Product
    {
        $category = ProductCategory::create(['name_ar' => 'هدايا', 'slug' => 'personalization-'.Str::random(8), 'is_active' => true, 'show_in_store' => true]);

        return Product::create([
            'product_category_id' => $category->id, 'name_ar' => 'ستيكر المدرسة', 'slug' => 'sticker-'.Str::random(8),
            'price_cents' => 34500, 'is_active' => true, 'purchase_mode' => 'standalone', 'personalization_mode' => 'collect_child_details',
            'inventory_mode' => 'no_tracking', 'fulfillment_type' => 'physical',
            'personalization_fields' => ProductPersonalizationSchema::fromAdminInput([
                'child_name' => ['enabled' => true, 'required' => true], 'school_name' => ['enabled' => true, 'required' => true],
                'class_name' => ['enabled' => true, 'required' => false],
                'photos' => ['enabled' => $photos, 'required' => $photos, 'min_files' => 1, 'max_files' => 3],
            ]),
        ]);
    }

    public function test_product_reorder_reuses_the_exact_snapshot_and_photo_order_once(): void
    {
        $user = User::factory()->create();
        $child = $this->child($user, 'Exact child');
        $front = $this->photo($child, 'front');
        $side = $this->photo($child, 'side');
        $product = $this->product();
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/cart/items/batch', ['items' => [$this->item($product, $child, [$side->uuid, $front->uuid])]])->assertCreated();
        $order = Order::findOrFail($this->checkout($user)->assertCreated()->json('data.orders.0.id'));
        $order->update(['status' => 'delivered']);
        $child->update(['name' => 'Changed profile, not the purchased name']);
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/orders/'.$order->id.'/reorder', ['idempotency_key' => $key])->assertCreated();
        $this->postJson('/api/v1/orders/'.$order->id.'/reorder', ['idempotency_key' => $key])->assertCreated()->assertJsonCount(1, 'data.items');
        $item = $user->mobileCarts()->where('status', 'active')->firstOrFail()->items()->firstOrFail();
        $this->assertSame([$side->uuid, $front->uuid], $item->personalization['photo_ids']);
        $this->assertSame('Exact child', $item->personalization['personalization_snapshot']['child_name']);
        $this->assertSame('KG-2 Blue', $item->personalization['personalization_snapshot']['class_name']);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_batch_is_atomic_and_retry_adds_each_child_only_once(): void
    {
        $user = User::factory()->create();
        $first = $this->child($user, 'First child');
        $second = $this->child($user, 'Second child');
        $product = $this->product();
        Sanctum::actingAs($user, ['mobile']);
        $items = [$this->item($product, $first, [$this->photo($first, 'first')->uuid]),
            $this->item($product, $second, [$this->photo($second, 'second')->uuid])];
        $invalid = $items;
        unset($invalid[1]['personalization']['school_name']);
        $this->postJson('/api/v1/cart/items/batch', ['items' => $invalid])->assertUnprocessable()
            ->assertJsonValidationErrors('items.1.personalization.school_name');
        $this->assertDatabaseCount('mobile_cart_items', 0);
        $duplicateKeys = $items;
        $duplicateKeys[1]['idempotency_key'] = $duplicateKeys[0]['idempotency_key'];
        $this->postJson('/api/v1/cart/items/batch', ['items' => $duplicateKeys])->assertUnprocessable()
            ->assertJsonValidationErrors('items.1.idempotency_key');
        $this->assertDatabaseCount('mobile_cart_items', 0);
        $this->postJson('/api/v1/cart/items/batch', ['items' => $items])->assertCreated()->assertJsonCount(2, 'data.items');
        $this->postJson('/api/v1/cart/items/batch', ['items' => $items])->assertCreated()->assertJsonCount(2, 'data.items');
        $this->assertDatabaseCount('mobile_cart_items', 2);
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $address = CustomerAddress::create(['user_id' => $user->id, 'recipient_name' => $user->name, 'phone' => '01012345678',
            'delivery_country_id' => $country->id, 'delivery_governorate_id' => $governorate->id, 'city' => 'QA', 'street' => 'QA', 'details' => '1']);
        $fingerprint = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()->assertJsonPath('data.requires_image_consent', true)->json('data.fingerprint');
        $this->postJson('/api/v1/checkout', ['address_id' => $address->uuid, 'payment_method' => 'cash_on_delivery',
            'terms_accepted' => true, 'terms_document_version' => 'test', 'image_processing_consent' => false,
            'consent_document_version' => 'test', 'idempotency_key' => (string) Str::uuid(), 'quote_fingerprint' => $fingerprint])
            ->assertUnprocessable()->assertJsonValidationErrors('image_processing_consent');
        $this->assertDatabaseCount('orders', 0);
    }

    private function child(User $user, string $name): ChildProfile
    {
        return ChildProfile::create(['user_id' => $user->id, 'name' => $name, 'age' => 6, 'gender' => 'girl', 'is_active' => true]);
    }

    private function photo(ChildProfile $child, string $content): ChildProfilePhoto
    {
        $path = 'child-photos/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, $content);

        return ChildProfilePhoto::create(['child_profile_id' => $child->id, 'disk' => 'local', 'path' => $path,
            'mime_type' => 'image/jpeg', 'original_filename' => 'test.jpg', 'file_size' => strlen($content),
            'checksum' => hash('sha256', $content), 'status' => 'active', 'reuse_consent_at' => now()]);
    }

    private function item(Product $product, ChildProfile $child, array $photos): array
    {
        return ['item_type' => 'product', 'product_id' => $product->id, 'child_profile_id' => $child->uuid,
            'child_photo_ids' => $photos, 'idempotency_key' => (string) Str::uuid(),
            'personalization' => ['child_name' => $child->name, 'school_name' => 'مدرسة الأمل', 'class_name' => 'KG-2 Blue', 'child_age' => 99]];
    }

    private function checkout(User $user): TestResponse
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $governorate->update(['delivery_fee' => 50, 'active' => true]);
        $address = CustomerAddress::create(['user_id' => $user->id, 'recipient_name' => $user->name, 'phone' => '01012345678',
            'delivery_country_id' => $country->id, 'delivery_governorate_id' => $governorate->id, 'city' => 'القاهرة', 'street' => 'Test street', 'details' => '1']);
        $fingerprint = $this->postJson('/api/v1/checkout/quote', ['address_id' => $address->uuid])->assertOk()->json('data.fingerprint');

        return $this->postJson('/api/v1/checkout', ['address_id' => $address->uuid, 'payment_method' => 'cash_on_delivery',
            'terms_accepted' => true, 'terms_document_version' => 'test-v1', 'image_processing_consent' => true,
            'consent_document_version' => 'test-v1', 'idempotency_key' => (string) Str::uuid(), 'quote_fingerprint' => $fingerprint]);
    }
}
