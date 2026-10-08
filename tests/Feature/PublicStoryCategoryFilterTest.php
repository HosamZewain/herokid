<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\StoryCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicStoryCategoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private StoryCategory $primary;

    private StoryCategory $secondary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primary = StoryCategory::create(['name' => 'مغامرات', 'slug' => 'adventures']);
        $this->secondary = StoryCategory::create(['name' => 'مصريات', 'slug' => 'msryat']);
    }

    public function test_reported_stories_url_matches_secondary_category_without_changing_card_badge(): void
    {
        $story = $this->story('egyptian-story');
        $response = $this->get('/stories?per_page=24&sort=best_selling&q=&age=&category=story%3Amsryat&gender=')
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1);
        $item = $response->viewData('items')->items()[0];
        $this->assertSame('story:'.$story->id, $item->id);
        $this->assertSame('adventures', $item->categorySlug);
        $this->assertSame('مغامرات', $item->badgeLabel);
        $this->assertSame(24, $response->viewData('items')->perPage());
    }

    public function test_primary_category_still_matches_multi_category_story(): void
    {
        $story = $this->story('primary-category-story');
        $this->get(route('stories.index', ['category' => 'story:adventures']))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1 && $items->items()[0]->id === 'story:'.$story->id);
    }

    public function test_secondary_category_matches_in_unified_shop_too(): void
    {
        $story = $this->story('unified-secondary-story');
        $this->product();
        $this->get(route('shop.index', ['category' => 'story:msryat']))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1 && $items->items()[0]->id === 'story:'.$story->id);
    }

    public function test_legacy_unprefixed_category_matches_stories_and_products_with_same_slug(): void
    {
        $story = $this->story('legacy-secondary-story');
        $product = $this->product();
        $response = $this->get(route('shop.index', ['category' => 'msryat']))->assertOk();
        $this->assertEqualsCanonicalizing(['story:'.$story->id, 'product:'.$product->id], collect($response->viewData('items')->items())->pluck('id')->all());
    }

    public function test_product_category_prefix_remains_exclusive_to_products(): void
    {
        $this->story('not-a-product');
        $product = $this->product();
        $this->get(route('shop.index', ['category' => 'product:msryat']))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1 && $items->items()[0]->id === 'product:'.$product->id);
    }

    public function test_category_never_exposes_inactive_or_unrelated_stories(): void
    {
        $active = $this->story('visible-category-story');
        $this->story('inactive-category-story', ['active' => false]);
        $unrelated = $this->story('unrelated-category-story');
        $unrelated->categories()->sync([$this->primary->id]);
        $this->get(route('stories.index', ['category' => 'story:msryat']))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1 && $items->items()[0]->id === 'story:'.$active->id);
    }

    public function test_category_combines_with_search_age_and_gender_filters(): void
    {
        $matching = $this->story('matching-category-story', ['title' => 'قصة مصرية مناسبة', 'gender' => 'girl', 'age_range' => '3-6']);
        $this->story('wrong-gender', ['title' => 'قصة مصرية أخرى', 'gender' => 'boy', 'age_range' => '3-6']);
        $this->story('wrong-age', ['title' => 'قصة مصرية أكبر', 'gender' => 'girl', 'age_range' => '9-12']);
        $this->story('wrong-search', ['title' => 'قصة مختلفة', 'gender' => 'girl', 'age_range' => '3-6']);
        $this->get(route('stories.index', ['category' => 'story:msryat', 'q' => 'مصرية', 'age' => '3-6', 'gender' => 'girl']))
            ->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1 && $items->items()[0]->id === 'story:'.$matching->id);
    }

    public function test_secondary_category_pagination_counts_each_story_only_once(): void
    {
        foreach (range(1, 25) as $index) {
            $this->story('paginated-story-'.$index);
        }
        $first = $this->get(route('stories.index', ['category' => 'story:msryat', 'sort' => 'newest']))->assertOk()->viewData('items');
        $second = $this->get(route('stories.index', ['category' => 'story:msryat', 'sort' => 'newest', 'page' => 2]))->assertOk()->viewData('items');
        $this->assertSame(25, $first->total());
        $this->assertCount(24, $first->items());
        $this->assertCount(1, $second->items());
        $ids = collect($first->items())->concat($second->items())->pluck('id');
        $this->assertCount(25, $ids->unique());
        $this->assertStringContainsString('category=story%3Amsryat', $first->nextPageUrl());
    }

    public function test_unknown_category_is_empty_and_unfiltered_stories_still_include_uncategorized(): void
    {
        $story = $this->story('uncategorized-story');
        $story->categories()->detach();
        $this->get(route('stories.index', ['category' => 'story:missing']))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 0);
        $this->get(route('stories.index'))->assertOk()->assertViewHas('items', fn ($items) => $items->total() === 1);
    }

    private function story(string $slug, array $overrides = []): Story
    {
        $story = Story::create(array_merge(['title' => 'قصة اختبار '.$slug, 'slug' => $slug, 'language' => 'ar', 'gender' => 'both', 'price' => 149, 'active' => true], $overrides));
        $story->categories()->attach([$this->primary->id, $this->secondary->id]);

        return $story;
    }

    private function product(): Product
    {
        $category = ProductCategory::create(['name_ar' => 'منتجات مصريات', 'slug' => 'msryat', 'is_active' => true, 'show_in_store' => true]);

        return Product::create(['product_category_id' => $category->id, 'name_ar' => 'منتج اختبار التصنيف', 'slug' => 'egyptian-product',
            'price_cents' => 9000, 'is_active' => true, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone',
            'personalization_mode' => 'none', 'inventory_mode' => 'no_tracking']);
    }
}
