<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Models\User;
use App\Services\Catalog\CatalogSalesRankingService;
use App\Services\Catalog\UnifiedStorefrontService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MobileCatalogSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_featured_products_are_pinned_before_best_sellers_across_pages_and_filters(): void
    {
        $this->seedCatalog();
        $featured = Product::orderByDesc('id')->limit(15)->pluck('id');
        Product::whereIn('id', $featured)->update(['is_featured' => true]);
        foreach ([[], ['type' => 'products'], ['q' => 'matching'], ['category' => 'product:sorting-products'], ['age' => '6-9'], ['personalization' => 'none']] as $filters) {
            $pages = [];
            foreach ([1, 2] as $page) {
                $pages[] = $this->assertMatchesWebsite($filters + ['sort' => 'best_selling', 'per_page' => 12, 'page' => $page]);
            }
            $combined = array_merge(...$pages);
            $wide = $this->getJson('/api/v1/catalog?'.http_build_query($filters + ['sort' => 'best_selling', 'per_page' => 24]))->assertOk()->json('data');
            $this->assertSame(array_column($wide, 'id'), $combined);
            $this->assertCount(24, array_unique($combined));
            $expectedFeatured = $featured->map(fn ($id) => 'product:'.$id)->all();
            $this->assertEqualsCanonicalizing($expectedFeatured, array_slice($combined, 0, 15));
            $this->assertSame([], array_values(array_intersect($expectedFeatured, array_slice($combined, 15))));
        }
        // Explicit other sorts are not overridden by featured placement.
        $cheapest = Product::orderBy('price_cents')->firstOrFail();
        $this->assertSame('product:'.$cheapest->id, $this->getJson('/api/v1/catalog?sort=price_asc')->assertOk()->json('data.0.id'));
        $this->assertMatchesWebsite(['sort' => 'best_selling', 'type' => 'stories']);
    }

    public function test_featured_does_not_override_visibility_or_search_and_can_be_removed(): void
    {
        $this->seedCatalog(7);
        $product = Product::where('slug', 'sorting-product-7')->firstOrFail(); // Lowest sales, not a bestseller.
        $normal = $this->getJson('/api/v1/catalog?sort=best_selling')->assertOk()->json('data');
        $product->update(['is_featured' => true]);
        $this->assertSame('product:'.$product->id, $this->getJson('/api/v1/catalog?sort=best_selling')->assertOk()->json('data.0.id'));
        $this->assertMatchesWebsite(['sort' => 'best_selling', 'q' => 'story']);
        $this->getJson('/api/v1/catalog?q=story&sort=best_selling')->assertOk()->assertJsonMissing(['id' => 'product:'.$product->id]);
        $product->update(['is_featured' => false]);
        $this->assertSame($normal, $this->getJson('/api/v1/catalog?sort=best_selling')->assertOk()->json('data'));
        $product->update(['is_featured' => true, 'is_active' => false]);
        $this->getJson('/api/v1/catalog?sort=best_selling')->assertOk()->assertJsonMissing(['id' => 'product:'.$product->id]);
    }

    public function test_existing_product_edit_permission_controls_featured_selection_and_persistence(): void
    {
        $this->seedCatalog(1);
        $product = Product::firstOrFail();
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->permissions()->sync(Permission::where('key', 'store.products.view')->pluck('id'));
        $payload = [
            'product_category_id' => $product->product_category_id, 'name_ar' => $product->name_ar, 'slug' => $product->slug,
            'price' => 51, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone',
            'personalization_mode' => 'none', 'inventory_mode' => 'no_tracking', 'is_active' => 1, 'is_featured' => 1,
        ];
        $this->actingAs($admin)->put(route('admin.products.update', $product), $payload)->assertForbidden();
        $this->assertFalse($product->fresh()->is_featured);
        $admin->permissions()->syncWithoutDetaching(Permission::where('key', 'store.products.update')->pluck('id'));
        $this->actingAs($admin->fresh())->put(route('admin.products.update', $product), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($product->fresh()->is_featured);
        $this->get(route('admin.products.edit', $product))->assertOk()->assertSee('منتج مميّز (Featured)');
        $this->get(route('admin.products.index'))->assertOk()->assertSee('★ منتج مميّز');
        unset($payload['is_featured']);
        $this->put(route('admin.products.update', $product), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse($product->fresh()->is_featured);
    }

    public function test_best_selling_matches_the_website_with_filters_and_across_two_pages(): void
    {
        $this->seedCatalog();

        foreach ([
            [], ['type' => 'stories'], ['type' => 'products'], ['type' => 'gifts'],
            ['type' => 'activities'], ['q' => 'matching'], ['age' => '6-9'],
            ['gender' => 'girl'], ['lang' => 'ar'],
            ['category' => 'story:sorting-stories'], ['category' => 'product:sorting-products'],
            ['personalization' => 'none'], ['personalization' => 'requires_child_photos'],
            ['type' => 'stories', 'category' => 'story:sorting-stories', 'gender' => 'boy', 'age' => '6-9'],
        ] as $filters) {
            $pages = [];
            foreach ([1, 2] as $page) {
                $query = $filters + ['sort' => 'best_selling', 'per_page' => 12, 'page' => $page];
                $pages[] = $this->assertMatchesWebsite($query);
            }
            $this->assertSame([], array_values(array_intersect(...$pages)));
            $combined = array_merge(...$pages);
            $wide = $this->getJson('/api/v1/catalog?'.http_build_query($filters + ['sort' => 'best_selling', 'per_page' => 24]))->assertOk();
            $this->assertSame(array_column($wide->json('data'), 'id'), $combined);
            $this->assertSame($combined, array_values(array_unique($combined)));
        }

        foreach ([1, 2] as $page) {
            $this->assertMatchesWebsite(['sort' => 'best_selling', 'per_page' => 20, 'page' => $page, 'locale' => 'en']);
        }
        $default = $this->getJson('/api/v1/catalog?per_page=24')->assertOk();
        $explicit = $this->getJson('/api/v1/catalog?per_page=24&sort=best_selling')->assertOk();
        $this->assertSame($explicit->json(), $default->json());
        $this->assertSame(['data', 'meta'], array_keys($explicit->json()));
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total', 'filters'], array_keys($explicit->json('meta')));
    }

    public function test_other_sorts_keep_matching_the_website_on_both_pages(): void
    {
        $this->seedCatalog();
        foreach (['most_viewed', 'featured', 'newest', 'price_asc', 'price_desc'] as $sort) {
            foreach ([1, 2] as $page) {
                $this->assertMatchesWebsite(['sort' => $sort, 'per_page' => 12, 'page' => $page]);
            }
        }
    }

    public function test_sales_counts_preserve_current_website_rules_and_legacy_fallback(): void
    {
        $this->seedCatalog(1);
        $story = Story::firstOrFail();
        $product = Product::firstOrFail();
        DB::table('orders')->delete();
        $this->order('legacy', $story->id);
        $modern = $this->order('modern', $story->id);
        $cancelled = $this->order('cancelled', null, 'cancelled');
        $deleted = $this->order('deleted', $story->id);
        $modern->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => 'Story', 'quantity' => 4, 'unit_price_cents' => 100, 'total_price_cents' => 400]);
        $modern->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => 'Product', 'quantity' => 3, 'unit_price_cents' => 100, 'total_price_cents' => 300]);
        $cancelled->items()->create(['item_type' => 'product_add_on', 'product_id' => $product->id, 'title' => 'Add-on', 'quantity' => 2, 'unit_price_cents' => 100, 'total_price_cents' => 200]);
        $deleted->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => 'Deleted', 'quantity' => 999, 'unit_price_cents' => 100, 'total_price_cents' => 99900]);
        DB::table('orders')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        $this->assertSame(['stories' => [$story->id => 5], 'products' => [$product->id => 5]], app(CatalogSalesRankingService::class)->counts(collect([$story->id]), collect([$product->id])));
        $this->assertMatchesWebsite(['sort' => 'best_selling']);
    }

    public function test_fully_tied_best_sellers_have_a_deterministic_order_independent_of_input_order(): void
    {
        $this->seedCatalog(28, true);
        Product::whereIn('id', Product::orderBy('id')->limit(16)->pluck('id'))->update(['is_featured' => true]);
        $service = app(UnifiedStorefrontService::class);
        $items = Story::with('categories')->get()->map(fn ($story) => $service->storyItem($story, 7))
            ->concat(Product::with('category')->get()->map(fn ($product) => $service->productItem($product, 7)));
        $method = new \ReflectionMethod($service, 'bestSellingSort');
        $expected = $method->invoke($service, $items)->pluck('id')->all();
        $reversed = $method->invoke($service, $items->reverse()->values())->pluck('id')->all();
        $this->assertSame($expected, $reversed, 'Fully tied items must not inherit unspecified database row order.');
    }

    private function assertMatchesWebsite(array $query): array
    {
        $query += ['per_page' => 12];
        $website = $this->get('/shop?'.http_build_query($query))->assertOk();
        $expected = collect($website->viewData('items')->items())->pluck('id')->all();
        $api = $this->getJson('/api/v1/catalog?'.http_build_query($query))->assertOk();
        $this->assertSame($expected, array_column($api->json('data'), 'id'), json_encode($query));
        $this->assertSame($website->viewData('items')->total(), $api->json('meta.total'));
        $items = collect($website->viewData('items')->items());
        if (($query['sort'] ?? null) === 'best_selling') {
            $priority = $items->map(fn ($item) => [$item->isFeatured ? 0 : 1, -$item->salesCount])->all();
            $sorted = $priority;
            sort($sorted);
            $this->assertSame($sorted, $priority);
        }

        return $expected;
    }

    private function seedCatalog(int $count = 28, bool $identical = false): void
    {
        $storyCategory = StoryCategory::create(['name' => 'Sorting', 'slug' => 'sorting-stories']);
        $productCategory = ProductCategory::create(['name_ar' => 'Sorting', 'name_en' => 'Sorting', 'slug' => 'sorting-products', 'is_active' => true, 'show_in_store' => true]);
        foreach (range(1, $count) as $index) {
            $story = Story::create(['title' => $identical ? 'Identical' : sprintf('matching story %02d', $index), 'slug' => 'sorting-story-'.$index, 'language' => 'ar', 'gender' => 'both', 'age_range' => '6-9', 'price' => 149, 'active' => true, 'created_at' => '2026-01-01 12:00:00']);
            $story->categories()->attach($storyCategory);
            $product = Product::create(['product_category_id' => $productCategory->id, 'name_ar' => $identical ? 'Identical' : sprintf('matching product %02d', $index), 'slug' => 'sorting-product-'.$index, 'price_cents' => 5000 + $index * 100, 'is_active' => true, 'is_featured' => false, 'sort_order' => 0, 'fulfillment_type' => 'physical', 'purchase_mode' => 'standalone', 'personalization_mode' => 'none', 'inventory_mode' => 'no_tracking', 'age_groups' => ['6-9'], 'created_at' => '2026-01-01 12:00:00']);
            $order = $this->order('fixture-'.$index, $story->id);
            $quantity = $index % 7 + 1;
            $order->items()->create(['item_type' => 'story', 'story_id' => $story->id, 'title' => $story->title, 'quantity' => $quantity, 'unit_price_cents' => 14900, 'total_price_cents' => $quantity * 14900]);
            $order->items()->create(['item_type' => 'product', 'product_id' => $product->id, 'title' => $product->name_ar, 'quantity' => $quantity, 'unit_price_cents' => $product->price_cents, 'total_price_cents' => $quantity * $product->price_cents]);
        }
    }

    private function order(string $suffix, ?int $storyId, string $status = 'new'): Order
    {
        return Order::create(['order_number' => 'SORT-'.$suffix, 'checkout_group_key' => 'SORT-'.$suffix, 'story_id' => $storyId, 'child_name' => 'Synthetic QA', 'child_age' => 7, 'child_gender' => 'boy', 'status' => $status, 'payment_status' => 'unpaid']);
    }
}
