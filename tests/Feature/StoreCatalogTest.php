<?php

namespace Tests\Feature;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\HomepageStoreSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_activate_deactivate_and_reorder_categories(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.product-categories.store'), [
            'name_ar' => 'كتب تدريب',
            'name_en' => 'Practice Books',
            'slug' => 'practice-books',
            'sort_order' => 70,
            'is_active' => 1,
            'show_in_store' => 1,
        ])->assertRedirect(route('admin.product-categories.index'));

        $category = ProductCategory::where('slug', 'practice-books')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.product-categories.update', $category), [
            'name_ar' => 'كتب تدريب جديدة',
            'name_en' => 'Practice Books',
            'slug' => 'practice-books',
            'sort_order' => 5,
            'is_active' => 0,
            'show_in_store' => 0,
        ])->assertRedirect(route('admin.product-categories.index'));

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'name_ar' => 'كتب تدريب جديدة',
            'sort_order' => 5,
            'is_active' => false,
            'show_in_store' => false,
        ]);
    }

    public function test_admin_can_create_product_and_public_filters_hide_inactive_products(): void
    {
        $admin = $this->admin();
        $category = ProductCategory::create(['name_ar' => 'كتب ألغاز', 'slug' => 'puzzles', 'is_active' => true, 'show_in_store' => true]);

        $this->actingAs($admin)->post(route('admin.products.store'), [
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب متاهات',
            'name_en' => 'Maze Book',
            'slug' => 'maze-book',
            'price' => 120,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
            'age_groups' => ['6-9'],
            'is_active' => 1,
        ])->assertRedirect();

        Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'منتج مخفي',
            'slug' => 'hidden-product',
            'price_cents' => 10000,
            'is_active' => false,
        ]);

        $this->get(route('shop.category', $category))
            ->assertOk()
            ->assertSee('كتاب متاهات')
            ->assertDontSee('منتج مخفي');

        $this->get(route('shop.index', ['age' => '6-9']))
            ->assertOk()
            ->assertSee('كتاب متاهات');
    }

    public function test_products_without_age_groups_are_treated_as_all_ages(): void
    {
        $category = ProductCategory::create(['name_ar' => 'قصص', 'slug' => 'public-ready', 'is_active' => true, 'show_in_store' => true]);
        Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'قصة لكل الأعمار',
            'slug' => 'all-ages-story',
            'price_cents' => 9000,
            'is_active' => true,
            'age_groups' => [],
        ]);

        $this->get(route('shop.index', ['age' => '9-12']))
            ->assertOk()
            ->assertSee('قصة لكل الأعمار');
    }

    public function test_regular_products_can_be_added_to_cart_without_child_data_and_variant_price_is_used(): void
    {
        $product = $this->product('poster', 100);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'A3',
            'price_override_cents' => 15000,
            'is_active' => true,
        ]);

        $this->post(route('cart.products.store', $product), [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart.index'));

        $item = collect(session('cart.items'))->first();
        $this->assertSame('product', $item['item_type']);
        $this->assertSame(30000, $item['line_total_cents']);
        $this->assertSame('A3', $item['variant_name']);
    }

    public function test_meta_pixel_tracks_product_views_and_product_add_to_cart(): void
    {
        config(['services.meta_pixel.id' => '1241523867742555']);
        $product = $this->product('pixel-poster', 100);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name_ar' => 'A3',
            'price_override_cents' => 15000,
            'is_active' => true,
        ]);

        $this->get(route('shop.product.show', $product))
            ->assertOk()
            ->assertSee("fbq('track', 'ViewContent'", false)
            ->assertSee('"content_ids":["product:'.$product->id.'"]', false)
            ->assertSee('"value":100', false);

        $this->post(route('cart.products.store', $product), [
            'variant_id' => $variant->id,
            'quantity' => 2,
        ])->assertRedirect(route('cart.index'));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertSee("fbq('track', 'AddToCart'", false)
            ->assertSee('"content_ids":["product:'.$product->id.':variant:'.$variant->id.'"]', false)
            ->assertSee('"value":300', false);
    }

    public function test_personalized_addons_require_and_inherit_story_context(): void
    {
        $addon = $this->product('hero-poster', 75, [
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
        ]);

        $this->post(route('cart.products.store', $addon))
            ->assertSessionHas('error');

        $story = $this->story('moon-story', 'رحلة القمر');
        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'مراد'),
        ]]);

        $this->post(route('cart.products.store', $addon))
            ->assertRedirect(route('cart.index'));

        $addonItem = collect(session('cart.items'))->firstWhere('item_type', 'product_add_on');
        $this->assertSame('story-key', $addonItem['linked_story_key']);
        $this->assertSame('مراد', $addonItem['linked_story_snapshot']['child_name']);
    }

    public function test_multiple_story_cart_requires_explicit_addon_child_selection(): void
    {
        $addon = $this->product('stickers', 50, [
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
        ]);
        $storyOne = $this->story('story-one', 'قصة أولى');
        $storyTwo = $this->story('story-two', 'قصة ثانية');

        $this->withSession(['cart.items' => [
            'one' => $this->storyCartItem('one', $storyOne, 'رينا'),
            'two' => $this->storyCartItem('two', $storyTwo, 'سليم'),
        ]]);

        $this->post(route('cart.products.store', $addon))
            ->assertSessionHasErrors('linked_story_key');

        $this->post(route('cart.products.store', $addon), ['linked_story_key' => 'two'])
            ->assertRedirect(route('cart.index'));

        $this->assertSame('سليم', collect(session('cart.items'))->firstWhere('item_type', 'product_add_on')['linked_story_snapshot']['child_name']);
    }

    public function test_removing_story_removes_linked_addons(): void
    {
        $story = $this->story('hero-story', 'قصة البطل');

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
            'addon-key' => [
                'key' => 'addon-key',
                'item_type' => 'product_add_on',
                'product_id' => 99,
                'product_title' => 'بوستر',
                'linked_story_key' => 'story-key',
                'line_total_cents' => 5000,
                'quantity' => 1,
            ],
        ]]);

        $this->delete(route('cart.destroy', 'story-key'))->assertRedirect(route('cart.index'));

        $this->assertSame([], session('cart.items'));
    }

    public function test_stock_limits_are_respected(): void
    {
        $product = $this->product('limited', 80, [
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 1,
        ]);

        $this->post(route('cart.products.store', $product), ['quantity' => 2])
            ->assertSessionHas('error');
    }

    public function test_checkout_preserves_product_snapshots_and_linked_addons(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $story = $this->story('production-story', 'قصة الإنتاج');
        $addon = $this->product('certificate', 60, [
            'purchase_mode' => 'add_on_only',
            'personalization_mode' => 'inherit_from_linked_story',
        ]);
        $regular = $this->product('maze-book-order', 90);

        $this->withSession(['cart.items' => [
            'story-key' => $this->storyCartItem('story-key', $story, 'رينا'),
        ]]);
        $this->post(route('cart.products.store', $addon), ['linked_story_key' => 'story-key']);
        $this->post(route('cart.products.store', $regular), ['quantity' => 1]);

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $order = Order::with('items')->firstOrFail();
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'item_type' => 'story', 'title' => 'قصة الإنتاج']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'item_type' => 'product_add_on', 'title' => 'certificate']);
        $this->assertDatabaseHas('order_items', ['item_type' => 'product', 'title' => 'maze-book-order']);
        $this->assertNotNull($order->items->firstWhere('item_type', 'product_add_on')->linked_order_item_id);
    }

    public function test_regular_product_only_cart_can_checkout_without_story_or_child_data(): void
    {
        $egypt = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $cairo = DeliveryGovernorate::where('delivery_country_id', $egypt->id)->where('name', 'القاهرة')->firstOrFail();
        $product = $this->product('ready-activity-book', 110);

        $this->post(route('cart.products.store', $product), ['quantity' => 1])
            ->assertRedirect(route('cart.index'));

        $this->post(route('checkout.store'), $this->checkoutPayload($egypt, $cairo))
            ->assertRedirect(route('checkout.success'));

        $order = Order::with('items')->firstOrFail();
        $this->assertNull($order->story_id);
        $this->assertNull($order->child_name);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_type' => 'product',
            'title' => 'ready-activity-book',
            'total_price_cents' => 11000,
        ]);
    }

    public function test_homepage_sections_render_only_when_category_has_active_products(): void
    {
        $visibleCategory = ProductCategory::create(['name_ar' => 'قسم ظاهر', 'slug' => 'visible-home', 'is_active' => true, 'show_in_store' => true]);
        $emptyCategory = ProductCategory::create(['name_ar' => 'قسم فارغ', 'slug' => 'empty-home', 'is_active' => true, 'show_in_store' => true]);
        HomepageStoreSection::create(['product_category_id' => $visibleCategory->id, 'title_ar' => 'قسم منتجات ظاهر', 'max_products' => 4, 'is_active' => true]);
        HomepageStoreSection::create(['product_category_id' => $emptyCategory->id, 'title_ar' => 'قسم منتجات فارغ', 'max_products' => 4, 'is_active' => true]);
        Product::create(['product_category_id' => $visibleCategory->id, 'name_ar' => 'منتج رئيسي', 'slug' => 'home-product', 'price_cents' => 10000, 'is_active' => true]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('قسم منتجات ظاهر')
            ->assertSee('منتج رئيسي')
            ->assertDontSee('قسم منتجات فارغ');
    }

    public function test_public_users_cannot_access_admin_store_management(): void
    {
        $this->get(route('admin.products.index'))->assertRedirect(route('login'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function product(string $slug, int $price, array $overrides = []): Product
    {
        $category = ProductCategory::firstOrCreate(
            ['slug' => 'test-store-category'],
            ['name_ar' => 'تصنيف اختبار', 'is_active' => true, 'show_in_store' => true]
        );

        return Product::create(array_merge([
            'product_category_id' => $category->id,
            'name_ar' => $slug,
            'slug' => $slug,
            'price_cents' => $price * 100,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ], $overrides));
    }

    private function story(string $slug, string $title): Story
    {
        return Story::create([
            'title' => $title,
            'slug' => $slug,
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 149,
            'active' => true,
        ]);
    }

    private function storyCartItem(string $key, Story $story, string $childName): array
    {
        return [
            'key' => $key,
            'item_type' => 'story',
            'story_id' => $story->id,
            'story_title' => $story->title,
            'story_slug' => $story->slug,
            'story_price' => (float) $story->price,
            'child_name' => $childName,
            'child_age' => 6,
            'child_gender' => 'girl',
            'interests' => 'الرسم',
            'gift_note' => 'إهداء',
            'parent_notes' => 'ملاحظة',
            'uploaded_photos' => ['orders/cart/test/child.png'],
        ];
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
