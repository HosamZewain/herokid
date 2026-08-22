<?php

namespace Tests\Feature;

use App\Models\CustomerPackageView;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Models\VisitorCartItem;
use App\Services\Pricing\DefaultPackageDeduplicator;
use App\Services\Pricing\DefaultPackageInstaller;
use App\Services\Pricing\PackageAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchasablePackagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_discounted_packages_are_installed_from_current_catalog_prices(): void
    {
        $story = $this->story('package-reference-story', 'قصة مرجعية', 399);
        $category = ProductCategory::query()->firstOrFail();
        $coloring = Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب تلوين مخصص بصورة الطفل',
            'slug' => 'ktab-tloyn-mkhss-bsor-altfl',
            'price_cents' => 29900,
            'is_active' => true,
        ]);
        $maze = Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب المتاهات - المستوى الأول',
            'slug' => 'maze-book-level-1',
            'price_cents' => 17900,
            'is_active' => true,
        ]);
        Setting::updateOrCreate(['key' => 'story_global_price_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'story_regular_price'], ['value' => '399']);
        Setting::updateOrCreate(['key' => 'story_offer_enabled'], ['value' => '1']);
        Setting::updateOrCreate(['key' => 'story_offer_price'], ['value' => '349']);

        $result = app(DefaultPackageInstaller::class)->install();

        $this->assertCount(3, $result['installed']);
        $this->assertSame([], $result['skipped']);

        $storiesOnly = PricingPackage::where('slug', 'three-personalized-stories')->firstOrFail();
        $threeBundle = PricingPackage::where('slug', 'three-stories-coloring-maze')->firstOrFail();
        $fiveBundle = PricingPackage::where('slug', 'five-stories-coloring-maze')->firstOrFail();

        $this->assertSame('1047.00', $storiesOnly->regular_price);
        $this->assertSame('942.30', $storiesOnly->price);
        $this->assertSame('1525.00', $threeBundle->regular_price);
        $this->assertSame('1372.50', $threeBundle->price);
        $this->assertSame('2223.00', $fiveBundle->regular_price);
        $this->assertSame('2000.70', $fiveBundle->price);
        $this->assertSame(10, $fiveBundle->discountPercentage());
        $this->assertTrue($fiveBundle->show_on_homepage);
        $this->assertTrue($fiveBundle->show_in_store);
        $this->assertNotNull($fiveBundle->image_url);
        $this->assertSame([$coloring->id, $maze->id], $threeBundle->items()->pluck('product_id')->all());

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-home-section="pricing"', false)
            ->assertSee($storiesOnly->name)
            ->assertSee($threeBundle->name)
            ->assertSee($fiveBundle->name);

        $packagesPage = $this->get(route('packages'));
        $packagesPage
            ->assertOk()
            ->assertSee('باقات قصص الأطفال المخصصة | HeroKid')
            ->assertSee('canonical', false)
            ->assertSee('/packages', false)
            ->assertSee('خصم ١٠٪')
            ->assertSee($fiveBundle->image_url, false);
        $this->assertStringContainsString('s-maxage=0', (string) $packagesPage->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $packagesPage->headers->get('Cache-Control'));

        $this->get('/pricing')->assertRedirect('/packages')->assertStatus(301);

        $shopHtml = $this->get(route('shop.index'))->assertOk()->getContent();
        $this->assertGreaterThan(strpos($shopHtml, 'id="catalog-results"'), strpos($shopHtml, 'id="packages-title"'));
        $this->assertStringContainsString(route('packages'), $shopHtml);
    }

    public function test_default_installer_reuses_an_equivalent_admin_package_instead_of_publishing_a_duplicate(): void
    {
        $this->story('existing-package-reference-story', 'قصة مرجعية', 349);
        $category = ProductCategory::query()->firstOrFail();
        Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب تلوين مخصص بصورة الطفل',
            'slug' => 'ktab-tloyn-mkhss-bsor-altfl',
            'price_cents' => 29900,
            'is_active' => true,
        ]);
        Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'كتاب المتاهات - المستوى الأول',
            'slug' => 'maze-book-level-1',
            'price_cents' => 17900,
            'is_active' => true,
        ]);
        $adminPackage = PricingPackage::create([
            'name' => 'باقة ٣ قصص مخصصة',
            'slug' => 'bak-3-kss-mkhss',
            'price' => 899,
            'story_count' => 3,
            'active' => true,
            'show_in_store' => true,
            'show_on_homepage' => true,
        ]);

        app(DefaultPackageInstaller::class)->install();

        $this->assertDatabaseCount('pricing_packages', 3);
        $this->assertDatabaseMissing('pricing_packages', ['slug' => 'three-personalized-stories']);
        $this->assertDatabaseHas('pricing_packages', [
            'id' => $adminPackage->id,
            'price' => 899,
            'active' => true,
            'show_in_store' => true,
        ]);
    }

    public function test_generated_duplicate_is_made_private_without_deleting_the_admin_package(): void
    {
        $adminPackage = PricingPackage::create([
            'name' => 'باقة ٣ قصص مخصصة',
            'slug' => 'bak-3-kss-mkhss',
            'price' => 899,
            'story_count' => 3,
            'active' => true,
            'show_in_store' => true,
            'show_on_homepage' => true,
        ]);
        $generatedPackage = PricingPackage::create([
            'name' => 'باقة ٣ قصص مخصصة',
            'slug' => 'three-personalized-stories',
            'price' => 942.30,
            'story_count' => 3,
            'active' => true,
            'show_in_store' => true,
            'show_on_homepage' => true,
        ]);

        $deactivated = app(DefaultPackageDeduplicator::class)->deactivateGeneratedDuplicates();

        $this->assertSame([$generatedPackage->id], $deactivated);
        $this->assertDatabaseHas('pricing_packages', [
            'id' => $generatedPackage->id,
            'active' => false,
            'show_in_store' => false,
            'show_on_homepage' => false,
        ]);
        $this->assertDatabaseHas('pricing_packages', [
            'id' => $adminPackage->id,
            'active' => true,
            'show_in_store' => true,
            'show_on_homepage' => true,
        ]);
    }

    public function test_package_story_picker_shows_cover_thumbnail_with_each_story_name(): void
    {
        $story = Story::create([
            'title' => 'قصة الاختيار المصورة',
            'slug' => 'pictured-package-story',
            'cover_image' => 'stories/pictured-package-story.jpg',
            'price' => 349,
            'language' => 'ar',
            'active' => true,
        ]);
        $bestSeller = Story::create([
            'title' => 'قصة الأكثر طلبًا',
            'slug' => 'best-selling-package-story',
            'cover_image' => 'stories/best-selling-package-story.jpg',
            'price' => 349,
            'language' => 'ar',
            'active' => true,
        ]);
        Order::create([
            'order_number' => 'HK-PACKAGE-BEST-SELLER',
            'story_id' => $bestSeller->id,
            'child_name' => 'نور',
            'child_age' => 7,
            'child_gender' => 'girl',
            'status' => 'cancelled',
        ]);
        $package = PricingPackage::create([
            'name' => 'باقة اختيار القصص',
            'slug' => 'pictured-story-picker-package',
            'price' => 900,
            'story_count' => 3,
            'active' => true,
            'show_in_store' => true,
        ]);

        $this->get(route('shop.package.show', $package))
            ->assertOk()
            ->assertSee('data-package-story-dialog', false)
            ->assertSee('data-package-story-search', false)
            ->assertSee('ابحث باسم القصة...')
            ->assertSee('data-choose-story', false)
            ->assertSeeInOrder([$bestSeller->title, $story->title])
            ->assertSee($story->title)
            ->assertSee($story->cover_url, false);
    }

    public function test_package_page_has_product_seo_and_mobile_purchase_landmarks(): void
    {
        $story = $this->story('seo-package-story', 'قصة باقة السيو', 349);
        $package = PricingPackage::create([
            'name' => 'باقة أبطال HeroKid',
            'slug' => 'hero-kid-seo-package',
            'description' => 'ثلاث قصص مخصصة يختارها ولي الأمر لطفله.',
            'image_path' => 'images/packages/three-stories.webp',
            'price' => 900,
            'regular_price' => 1047,
            'story_count' => 3,
            'active' => true,
            'show_in_store' => true,
        ]);

        $response = $this->get(route('shop.package.show', $package));

        $response
            ->assertOk()
            ->assertSee('<meta property="og:type" content="product">', false)
            ->assertSee('<link rel="canonical"', false)
            ->assertSee('/shop/package/'.$package->slug, false)
            ->assertSee('باقة أبطال HeroKid — باقة قصص أطفال مخصصة | HeroKid')
            ->assertSee('https://schema.org/InStock', false)
            ->assertSee('"@type":"Product"', false)
            ->assertSee('"priceCurrency":"EGP"', false)
            ->assertSee('data-package-progress-text', false)
            ->assertSee('data-package-story-card', false)
            ->assertSee('fixed inset-x-2 bottom-2', false)
            ->assertSee('aria-labelledby="package-story-dialog-title"', false)
            ->assertSee('إضافة الباقة إلى السلة');

        $this->assertSame($story->id, $story->fresh()->id);
    }

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
            'features_raw' => '',
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
        $this->assertSame([], $package->features);
        $this->assertTrue($package->show_in_store);
        $this->assertDatabaseHas('pricing_package_products', [
            'pricing_package_id' => $package->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
    }

    public function test_admin_can_limit_a_package_to_selected_active_stories(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::whereIn('key', [
            'settings.pricing.view', 'settings.pricing.create',
        ])->pluck('id'));
        $football = $this->story('football-package-story', 'بطل كرة القدم', 349);
        $adventure = $this->story('adventure-package-story', 'مغامرة الغابة', 349);

        $this->actingAs($admin)->post(route('admin.pricing.store'), [
            'name' => 'باقة كرة القدم',
            'price' => 900,
            'currency' => 'ج.م',
            'story_count' => 3,
            'story_scope' => 'specific',
            'story_ids' => [$football->id],
            'active' => 1,
            'show_in_store' => 1,
            'sort_order' => 1,
        ])->assertRedirect(route('admin.pricing.index'));

        $package = PricingPackage::where('name', 'باقة كرة القدم')->firstOrFail();
        $this->assertFalse($package->applies_to_all_stories);
        $this->assertSame([$football->id], $package->eligibleStories()->pluck('stories.id')->all());

        $this->get(route('shop.package.show', $package))
            ->assertOk()
            ->assertSee($football->title)
            ->assertDontSee($adventure->title);
    }

    public function test_package_cart_rejects_a_story_outside_the_selected_scope(): void
    {
        $allowed = $this->story('allowed-package-story', 'القصة المتاحة', 349);
        $blocked = $this->story('blocked-package-story', 'القصة غير المتاحة', 349);
        $package = PricingPackage::create([
            'name' => 'باقة محددة',
            'slug' => 'restricted-story-package',
            'price' => 300,
            'story_count' => 1,
            'applies_to_all_stories' => false,
            'active' => true,
            'show_in_store' => true,
        ]);
        $package->eligibleStories()->sync([$allowed->id]);

        $this->from(route('shop.package.show', $package))
            ->post(route('cart.packages.store', $package), [
                'stories' => [[
                    'story_id' => $blocked->id,
                    'child_name' => 'نور',
                    'child_age' => 7,
                    'child_gender' => 'girl',
                ]],
            ])
            ->assertRedirect(route('shop.package.show', $package))
            ->assertSessionHasErrors('stories.0.story_id');

        $this->assertEmpty(session('cart.items', []));
    }

    public function test_package_page_visit_is_recorded_and_admin_shows_unique_purchase_count(): void
    {
        $package = PricingPackage::create([
            'name' => 'باقة الإحصائيات',
            'slug' => 'analytics-package',
            'price' => 900,
            'story_count' => 1,
            'active' => true,
            'show_in_store' => true,
        ]);
        $story = $this->story('analytics-package-story', 'قصة الإحصائيات', 349);

        $this->get(route('shop.package.show', $package))->assertOk();
        $this->assertDatabaseHas('customer_package_views', [
            'pricing_package_id' => $package->id,
        ]);

        $firstOrder = Order::create([
            'order_number' => 'HK-PACKAGE-ANALYTICS-1',
            'checkout_group_key' => 'PACKAGE-GROUP-1',
            'story_id' => $story->id,
            'child_name' => 'نور',
            'child_age' => 7,
            'child_gender' => 'girl',
            'status' => 'new',
        ]);
        $secondOrder = Order::create([
            'order_number' => 'HK-PACKAGE-ANALYTICS-2',
            'checkout_group_key' => 'PACKAGE-GROUP-1',
            'story_id' => $story->id,
            'child_name' => 'نور',
            'child_age' => 7,
            'child_gender' => 'girl',
            'status' => 'new',
        ]);
        foreach ([$firstOrder, $secondOrder] as $order) {
            OrderItem::create([
                'order_id' => $order->id,
                'item_type' => 'story',
                'story_id' => $story->id,
                'title' => $story->title,
                'unit_price_cents' => 45_000,
                'quantity' => 1,
                'total_price_cents' => 45_000,
                'item_snapshot' => ['package' => [
                    'id' => $package->id,
                    'instance_key' => 'PACKAGE-INSTANCE-1',
                ]],
            ]);
        }

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::where('key', 'settings.pricing.view')->pluck('id'));
        $this->actingAs($admin)->get(route('admin.pricing.index'))
            ->assertOk()
            ->assertSee($package->name)
            ->assertSeeInOrder(['الزيارات', 'مرات الشراء']);

        $this->assertSame(1, CustomerPackageView::where('pricing_package_id', $package->id)->count());
        $this->assertSame(1, (int) $package->fresh()->loadCount('views')->views_count);
        $this->assertSame(1, app(PackageAnalyticsService::class)->purchaseCounts(collect([$package->id]))[$package->id]);
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

    public function test_three_story_package_keeps_the_package_price_through_cart_checkout_and_orders(): void
    {
        Storage::fake('local');
        $stories = collect([
            $this->story('three-package-one', 'قصة الباقة الأولى', 400),
            $this->story('three-package-two', 'قصة الباقة الثانية', 400),
            $this->story('three-package-three', 'قصة الباقة الثالثة', 400),
        ]);
        $package = PricingPackage::create([
            'name' => 'باقة ثلاث قصص',
            'slug' => 'three-story-package',
            'price' => 900,
            'story_count' => 3,
            'active' => true,
            'show_in_store' => true,
        ]);

        $this->get(route('shop.package.show', $package))
            ->assertOk()
            ->assertSee('القصة 1')
            ->assertSee('القصة 2')
            ->assertSee('القصة 3')
            ->assertSee('سعر الباقة النهائي');

        $this->post(route('cart.packages.store', $package), [
            'stories' => $stories->values()->map(fn (Story $story, int $index): array => [
                'story_id' => $story->id,
                'child_name' => $index < 2 ? 'ليلى' : 'عمر',
                'child_age' => $index < 2 ? 6 : 8,
                'child_gender' => $index < 2 ? 'girl' : 'boy',
                'photos' => [
                    $this->photo("package-child-{$index}-1.png"),
                    $this->photo("package-child-{$index}-2.png"),
                ],
            ])->all(),
        ])->assertRedirect(route('cart.index'));

        $packageItem = collect(session('cart.items'))->sole();
        $this->assertSame(90_000, $packageItem['line_total_cents']);
        $this->assertSame(120_000, $packageItem['regular_total_cents']);
        $this->assertCount(3, $packageItem['package_stories']);
        $this->assertSame(90_000, collect($packageItem['package_stories'])->sum(fn (array $story): int => (int) round($story['story_price'] * 100)));

        $this->get(route('cart.index'))
            ->assertOk()
            ->assertViewHas('subtotal', 900.0);

        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();
        $this->post(route('checkout.store'), [
            'parent_name' => 'ولي أمر الباقة',
            'phone' => '01000000000',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع الاختبار',
            'address_details' => 'عمارة ١ شقة ٢',
        ])->assertRedirect(route('checkout.success'));

        $orders = Order::with('items')->orderBy('id')->get();
        $this->assertCount(3, $orders);
        $this->assertCount(1, $orders->pluck('checkout_group_key')->unique());
        $this->assertSame(['ليلى', 'ليلى', 'عمر'], $orders->pluck('child_name')->all());
        $this->assertSame(90_000, $orders->flatMap->items->sum('total_price_cents'));
        $this->assertTrue($orders->every(fn (Order $order): bool => (float) data_get($order->delivery_details, 'subtotal') === 900.0));
        $this->assertTrue($orders->flatMap->items->every(fn ($item): bool => data_get($item->item_snapshot, 'package.id') === $package->id));
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
