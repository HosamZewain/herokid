<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Permission;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCartItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchasablePackagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_build_package_from_story_count_and_store_products_with_custom_price(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::whereIn('key', [
            'settings.pricing.view', 'settings.pricing.create',
        ])->pluck('id'));
        $category = ProductCategory::query()->firstOrFail();
        $product = Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب التلوين',
            'slug' => 'admin-package-coloring',
            'price_cents' => 29900,
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.pricing.create'))
            ->assertOk()
            ->assertSee('محتويات الباقة')
            ->assertSee($product->name_ar);

        $this->actingAs($admin)->post(route('admin.pricing.store'), [
            'name' => 'باقة القصة والتلوين',
            'price' => 499,
            'currency' => 'ج.م',
            'story_count' => 1,
            'products' => [$product->id => ['quantity' => 1]],
            'active' => 1,
            'show_in_store' => 1,
            'show_on_homepage' => 1,
            'sort_order' => 1,
        ])->assertRedirect(route('admin.pricing.index'));

        $package = PricingPackage::where('name', 'باقة القصة والتلوين')->firstOrFail();
        $this->assertSame(1, $package->story_count);
        $this->assertSame('499.00', $package->price);
        $this->assertTrue($package->show_in_store);
        $this->assertDatabaseHas('pricing_package_products', [
            'pricing_package_id' => $package->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    public function test_package_is_a_separate_store_and_homepage_section(): void
    {
        $package = PricingPackage::create([
            'name' => 'باقة المغامرين',
            'slug' => 'adventurers-package',
            'price' => 699,
            'story_count' => 2,
            'active' => true,
            'show_in_store' => true,
            'show_on_homepage' => true,
        ]);

        $this->get(route('home'))->assertOk()->assertSee($package->name)->assertSee(route('shop.package.show', $package), false);
        $this->get(route('shop.index'))->assertOk()->assertSee('باقات القصص والأنشطة')->assertSee($package->name);
    }

    public function test_multi_story_package_uses_entered_price_and_converts_to_normal_orders_and_products(): void
    {
        Storage::fake('local');
        $storyOne = $this->story('package-story-one', 'القصة الأولى', 400);
        $storyTwo = $this->story('package-story-two', 'القصة الثانية', 400);
        $category = ProductCategory::query()->firstOrFail();
        $product = Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب المتاهات',
            'slug' => 'package-maze-book',
            'price_cents' => 20000,
            'is_active' => true,
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 10,
        ]);
        $package = PricingPackage::create([
            'name' => 'باقة قصتين ومتاهات',
            'slug' => 'two-stories-maze',
            'price' => 800,
            'story_count' => 2,
            'active' => true,
            'show_in_store' => true,
            'show_on_homepage' => true,
        ]);
        $package->items()->create(['product_id' => $product->id, 'quantity' => 1, 'sort_order' => 0]);

        $this->post(route('cart.packages.store', $package), [
            'stories' => [
                ['story_id' => $storyOne->id, 'child_name' => 'ليلى', 'child_age' => 6, 'child_gender' => 'girl', 'photos' => [$this->photo('leila-1.png'), $this->photo('leila-2.png')]],
                ['story_id' => $storyTwo->id, 'child_name' => 'عمر', 'child_age' => 8, 'child_gender' => 'boy', 'photos' => [$this->photo('omar-1.png'), $this->photo('omar-2.png')]],
            ],
        ])->assertRedirect(route('cart.index'));

        $cart = session('cart.items');
        $this->assertCount(1, $cart);
        $cartItem = collect($cart)->first();
        $this->assertSame('package', $cartItem['item_type']);
        $this->assertSame(80000, $cartItem['line_total_cents']);
        $this->assertSame(100000, $cartItem['regular_total_cents']);
        $this->assertCount(2, $cartItem['package_stories']);
        $this->assertCount(1, $cartItem['package_products']);

        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $this->post(route('checkout.store'), [
            'parent_name' => 'أحمد محمد',
            'phone' => '01000000000',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع الاختبار',
            'address_details' => 'عمارة ١ شقة ٢',
        ])->assertRedirect(route('checkout.success'));

        $orders = Order::with('items')->orderBy('id')->get();
        $this->assertCount(2, $orders);
        $this->assertSame($orders[0]->checkout_group_key, $orders[1]->checkout_group_key);
        $this->assertSame(['ليلى', 'عمر'], $orders->pluck('child_name')->all());
        $this->assertSame(80000, $orders->flatMap->items->sum('total_price_cents'));
        $this->assertCount(1, $orders->flatMap->items->where('item_type', 'product'));
        $this->assertSame($package->id, data_get($orders->first()->items->first()->item_snapshot, 'package.id'));
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertNull(session('cart.items'));
    }

    public function test_package_requires_two_photos_for_every_story(): void
    {
        Storage::fake('local');
        $story = $this->story('package-photo-story', 'قصة الصور', 349);
        $package = PricingPackage::create([
            'name' => 'باقة قصة', 'slug' => 'one-story-package', 'price' => 300,
            'story_count' => 1, 'active' => true, 'show_in_store' => true,
        ]);

        $this->from(route('shop.package.show', $package))->post(route('cart.packages.store', $package), [
            'stories' => [[
                'story_id' => $story->id,
                'child_name' => 'سارة',
                'child_age' => 5,
                'child_gender' => 'girl',
                'photos' => [$this->photo('only-one.png')],
            ]],
        ])->assertRedirect(route('shop.package.show', $package))->assertSessionHasErrors('stories.0.photos');

        $this->assertNull(session('cart.items'));
    }

    public function test_package_accepts_individually_uploaded_mobile_photos_without_large_form_upload(): void
    {
        Storage::fake('local');
        $story = $this->story('async-package-story', 'قصة الرفع المنفصل', 349);
        $package = PricingPackage::create([
            'name' => 'باقة الرفع', 'slug' => 'async-upload-package', 'price' => 320,
            'story_count' => 1, 'active' => true, 'show_in_store' => true,
        ]);

        $session = $this->getJson(route('photo-uploads.session'))->assertOk()->json();
        $firstId = $this->postJson(route('photo-uploads.store'), [
            'photo' => $this->photo('mobile-1.png'),
            'upload_session_token' => $session['upload_session_token'],
            'upload_batch_token' => $session['upload_batch_token'],
        ])->assertCreated()->json('id');
        $secondId = $this->postJson(route('photo-uploads.store'), [
            'photo' => $this->photo('mobile-2.png'),
            'upload_session_token' => $session['upload_session_token'],
            'upload_batch_token' => $session['upload_batch_token'],
        ])->assertCreated()->json('id');

        $this->post(route('cart.packages.store', $package), [
            'upload_session_token' => $session['upload_session_token'],
            'stories' => [[
                'story_id' => $story->id,
                'child_name' => 'نور',
                'child_age' => 7,
                'child_gender' => 'girl',
                'photo_upload_ids' => [$firstId, $secondId],
            ]],
        ])->assertRedirect(route('cart.index'));

        $packageItem = collect(session('cart.items'))->first();
        $this->assertCount(2, $packageItem['package_stories'][0]['uploaded_photos']);
        $this->assertDatabaseCount('temporary_photo_uploads', 2);
        $this->assertDatabaseMissing('temporary_photo_uploads', ['status' => 'uploaded']);
        $this->assertStringNotContainsString('temporary-uploads', json_encode(VisitorCartItem::firstOrFail()->item_snapshot));
    }

    private function story(string $slug, string $title, int $price): Story
    {
        return Story::create(['title' => $title, 'slug' => $slug, 'price' => $price, 'language' => 'ar', 'active' => true]);
    }

    private function photo(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'package-photo-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
