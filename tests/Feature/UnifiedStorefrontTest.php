<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnifiedStorefrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_renders_active_stories_and_published_products_in_one_catalog(): void
    {
        $story = $this->story('space-hero', 'بطل الفضاء');
        $product = $this->product('maze-book', 'كتاب المتاهات', 90);
        $this->story('hidden-story', 'قصة مخفية', ['active' => false]);
        $this->product('hidden-product', 'منتج مخفي', 50, ['is_active' => false]);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertViewIs('front.shop.index')
            ->assertViewHas('items', fn ($items) => $items->total() === 2 && $items->perPage() === 24)
            ->assertSee($story->title)
            ->assertSee($product->name_ar)
            ->assertDontSee('قصة مخفية')
            ->assertDontSee('منتج مخفي')
            ->assertSee('data-catalog-type="story"', false)
            ->assertSee('data-catalog-type="product"', false);
    }

    public function test_default_featured_view_balances_stories_and_products_on_the_first_page(): void
    {
        foreach (range(1, 30) as $index) {
            $this->story('balanced-story-'.$index, 'قصة متوازنة '.$index);
        }

        $this->product('balanced-product-one', 'منتج متوازن أول', 80);
        $this->product('balanced-product-two', 'منتج متوازن ثان', 90);

        $response = $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('24 في الصفحة')
            ->assertSee('عرض')
            ->assertSee('من أصل')
            ->assertSee('نتيجة')
            ->assertDontSee('Showing');

        $items = collect($response->viewData('items')->items());
        $firstTypes = $items->take(4)->pluck('type')->values();

        $this->assertSame(24, $response->viewData('items')->perPage());
        $this->assertSame(24, $items->count());
        $this->assertContains('story', $firstTypes);
        $this->assertContains('product', $firstTypes);
        $this->assertNotSame($firstTypes[0], $firstTypes[1]);
        $this->assertNotSame($firstTypes[1], $firstTypes[2]);
    }

    public function test_page_size_can_be_changed_and_mobile_filters_only_open_for_active_criteria(): void
    {
        foreach (range(1, 25) as $index) {
            $this->story('page-size-story-'.$index, 'قصة حجم الصفحة '.$index);
        }

        $this->get(route('shop.index', ['per_page' => 20]))
            ->assertOk()
            ->assertViewHas('items', fn ($items) => $items->perPage() === 20 && count($items->items()) === 20)
            ->assertSee('data-mobile-store-filters data-expanded="false"', false);

        $this->get(route('shop.index', ['q' => 'حجم']))
            ->assertOk()
            ->assertSee('data-mobile-store-filters data-expanded="true"', false);
    }

    public function test_type_filters_keep_story_and_product_flows_separate_inside_the_same_page(): void
    {
        $story = $this->story('forest-hero', 'مغامرة الغابة');
        $product = $this->product('activity-book', 'كتاب النشاط', 80);

        $this->get(route('shop.index', ['type' => 'stories']))
            ->assertOk()
            ->assertSee($story->title)
            ->assertDontSee($product->name_ar)
            ->assertSee(route('stories.show', $story->slug), false);

        $this->get(route('shop.index', ['type' => 'products']))
            ->assertOk()
            ->assertDontSee($story->title)
            ->assertSee($product->name_ar)
            ->assertSee(route('shop.product.show', $product), false);
    }

    public function test_catalog_cards_use_assigned_categories_as_image_badges(): void
    {
        $storyCategory = StoryCategory::create(['name' => 'تطوير سلوك', 'slug' => 'behavior']);
        $story = $this->story('category-badge-story', 'قصة التصنيف');
        $story->categories()->attach($storyCategory);

        $productCategory = ProductCategory::create([
            'name_ar' => 'هدايا مخصصة',
            'slug' => 'category-badge-products',
            'is_active' => true,
            'show_in_store' => true,
        ]);
        $this->product('category-badge-product', 'منتج التصنيف', 75, [
            'product_category_id' => $productCategory->id,
        ]);

        $response = $this->get(route('shop.index'))->assertOk();
        $items = collect($response->viewData('items')->items());

        $this->assertSame('تطوير سلوك', $items->firstWhere('type', 'story')->badgeLabel);
        $this->assertSame('هدايا مخصصة', $items->firstWhere('type', 'product')->badgeLabel);
        $response
            ->assertSee('data-catalog-category-badge="تطوير سلوك"', false)
            ->assertSee('data-catalog-category-badge="هدايا مخصصة"', false);
    }

    public function test_uncategorized_story_keeps_the_generic_story_badge(): void
    {
        $this->story('uncategorized-story', 'قصة بلا تصنيف');

        $response = $this->get(route('shop.index'))->assertOk();
        $storyItem = collect($response->viewData('items')->items())->firstWhere('type', 'story');

        $this->assertNull($storyItem->category);
        $this->assertSame('قصة مخصصة', $storyItem->badgeLabel);
    }

    public function test_age_category_and_personalization_filters_apply_to_mixed_items(): void
    {
        $storyCategory = StoryCategory::create(['name' => 'مغامرات', 'slug' => 'adventures']);
        $youngStory = $this->story('young-adventure', 'مغامرة الصغار', ['age_range' => '3-6']);
        $youngStory->categories()->attach($storyCategory);
        $this->story('older-story', 'قصة الكبار', ['age_range' => '9-12']);

        $productCategory = ProductCategory::create([
            'name_ar' => 'هدايا مخصصة',
            'slug' => 'gifts-filter',
            'is_active' => true,
            'show_in_store' => true,
        ]);
        $gift = $this->product('linked-gift', 'بوستر القصة', 70, [
            'product_category_id' => $productCategory->id,
            'age_groups' => ['3-6'],
            'personalization_mode' => 'inherit_from_linked_story',
            'purchase_mode' => 'add_on_only',
        ]);
        $this->product('direct-gift', 'هدية مباشرة', 60, [
            'product_category_id' => $productCategory->id,
            'personalization_mode' => 'none',
        ]);

        $this->get(route('shop.index', ['age' => '3-6']))
            ->assertOk()
            ->assertSee($youngStory->title)
            ->assertSee($gift->name_ar)
            ->assertDontSee('قصة الكبار');

        $this->get(route('shop.index', ['category' => 'story:adventures']))
            ->assertOk()
            ->assertSee($youngStory->title)
            ->assertDontSee($gift->name_ar);

        $this->get(route('shop.index', ['personalization' => 'story_context']))
            ->assertOk()
            ->assertSee($gift->name_ar)
            ->assertDontSee($youngStory->title)
            ->assertDontSee('هدية مباشرة');
    }

    public function test_activity_and_gift_shortcuts_classify_products_without_new_database_columns(): void
    {
        $activity = $this->product('coloring-book', 'كتاب تلوين ممتع', 50, [], 'activities-learning');
        $gift = $this->product('hero-poster', 'بوستر البطل', 75, [], 'personalized-gifts');

        $this->get(route('shop.index', ['type' => 'activities']))
            ->assertOk()
            ->assertSee($activity->name_ar)
            ->assertDontSee($gift->name_ar);

        $this->get(route('shop.index', ['type' => 'gifts']))
            ->assertOk()
            ->assertSee($gift->name_ar)
            ->assertDontSee($activity->name_ar);
    }

    public function test_mixed_price_sorting_and_missing_image_placeholders_work(): void
    {
        $this->story('middle-price', 'القصة المتوسطة', ['price' => 100, 'cover_image' => null]);
        $this->product('lowest-price', 'المنتج الأقل', 50, ['featured_image' => null]);
        $this->product('highest-price', 'المنتج الأعلى', 150);

        $this->get(route('shop.index', ['sort' => 'price_asc']))
            ->assertOk()
            ->assertSeeInOrder(['المنتج الأقل', 'القصة المتوسطة', 'المنتج الأعلى'])
            ->assertSee('<p class="mt-2 text-xs font-black text-slate-400">HeroKid</p>', false)
            ->assertDontSee('src=""', false);
    }

    public function test_stories_index_renders_the_unified_store_with_story_filter_and_shop_canonical(): void
    {
        $story = $this->story('legacy-story-link', 'قصة الرابط القديم');
        $product = $this->product('not-on-legacy-index', 'منتج لا يظهر', 60);

        $this->get(route('stories.index'))
            ->assertOk()
            ->assertViewIs('front.shop.index')
            ->assertSee($story->title)
            ->assertDontSee($product->name_ar)
            ->assertSee('<link rel="canonical" href="'.Seo::url('/shop?type=stories').'">', false)
            ->assertSee('مكتبة القصص أصبحت جزءاً من متجر HeroKid الموحد');
    }

    public function test_store_navigation_footer_and_seo_present_one_public_store_identity(): void
    {
        $this->story('nav-story', 'قصة القائمة');

        $response = $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee('<title>متجر القصص والمنتجات | HeroKid</title>', false)
            ->assertSee('<link rel="canonical" href="'.Seo::url('/shop').'">', false)
            ->assertSee('Browse personalized children’s stories, activity books, coloring books, mazes, posters, and gifts from HeroKid.', false)
            ->assertDontSee('>القصص</a>', false)
            ->assertDontSee('>المتجر</a>', false);

        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), 'متجر القصص والمنتجات'));
    }

    public function test_storefront_is_publicly_cacheable_without_a_customer_session(): void
    {
        $response = $this->get(route('shop.index'))->assertOk();

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function test_detail_routes_remain_available_with_unified_store_breadcrumbs(): void
    {
        $story = $this->story('detail-story', 'قصة التفاصيل');
        $product = $this->product('detail-product', 'منتج التفاصيل', 100);

        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('العودة إلى متجر القصص والمنتجات')
            ->assertSee(route('shop.index'), false);

        $this->get(route('shop.product.show', $product))
            ->assertOk()
            ->assertSee('العودة إلى متجر القصص والمنتجات')
            ->assertSee(route('shop.index'), false);
    }

    public function test_story_and_direct_product_selected_from_store_can_coexist_in_cart(): void
    {
        Storage::fake('local');
        $story = $this->story('cart-story', 'قصة السلة');
        $product = $this->product('cart-product', 'منتج السلة', 85);

        $this->get(route('shop.index'))
            ->assertOk()
            ->assertSee(route('stories.show', $story->slug), false)
            ->assertSee(route('shop.product.show', $product), false);

        $this->post(route('cart.store', $story->slug), [
            'child_name' => 'ليلى',
            'child_age' => 6,
            'child_gender' => 'girl',
            'privacy_consent' => '1',
            'next' => 'cart',
            'photos' => [UploadedFile::fake()->create('child.jpg', 512, 'image/jpeg')],
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('cart.index'));

        $this->post(route('cart.products.store', $product), ['quantity' => 1])
            ->assertRedirect(route('cart.index'));

        $items = collect(session('cart.items'));
        $this->assertCount(2, $items);
        $this->assertNotNull($items->firstWhere('item_type', 'story'));
        $this->assertNotNull($items->firstWhere('item_type', 'product'));
    }

    public function test_catalog_query_count_does_not_grow_with_more_cards(): void
    {
        $storyCategory = StoryCategory::create(['name' => 'تعليم', 'slug' => 'learning-stories']);
        $productCategory = ProductCategory::create(['name_ar' => 'كتب أنشطة', 'slug' => 'query-products', 'is_active' => true, 'show_in_store' => true]);

        foreach (range(1, 15) as $index) {
            $story = $this->story('query-story-'.$index, 'قصة استعلام '.$index);
            $story->categories()->attach($storyCategory);
            $this->product('query-product-'.$index, 'منتج استعلام '.$index, 50 + $index, [
                'product_category_id' => $productCategory->id,
            ]);
        }

        Cache::forget('site_settings');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get(route('shop.index'))->assertOk();

        $this->assertLessThanOrEqual(12, count(DB::getQueryLog()), 'Unified catalog introduced per-card database queries.');
        DB::disableQueryLog();
    }

    public function test_sitemap_prioritizes_shop_without_removing_detail_urls(): void
    {
        $story = $this->story('sitemap-story', 'قصة الخريطة');
        $product = $this->product('sitemap-product', 'منتج الخريطة', 90);

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee(Seo::url('/shop'), false)
            ->assertDontSee('<loc>'.Seo::url('/stories').'</loc>', false)
            ->assertSee(Seo::url('/stories/'.$story->slug), false)
            ->assertSee(Seo::url('/shop/product/'.$product->slug), false);
    }

    private function story(string $slug, string $title, array $overrides = []): Story
    {
        return Story::create(array_merge([
            'title' => $title,
            'slug' => $slug,
            'short_desc' => 'وصف مختصر للقصة',
            'language' => 'ar',
            'gender' => 'both',
            'age_range' => '6-9',
            'price' => 149,
            'active' => true,
        ], $overrides));
    }

    private function product(string $slug, string $name, int $price, array $overrides = [], string $categorySlug = 'unified-products'): Product
    {
        $category = ProductCategory::firstOrCreate(
            ['slug' => $categorySlug],
            ['name_ar' => $categorySlug === 'personalized-gifts' ? 'هدايا مخصصة' : 'كتب أنشطة', 'is_active' => true, 'show_in_store' => true],
        );

        return Product::create(array_merge([
            'product_category_id' => $category->id,
            'name_ar' => $name,
            'slug' => $slug,
            'short_description_ar' => 'وصف مختصر للمنتج',
            'price_cents' => $price * 100,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ], $overrides));
    }
}
