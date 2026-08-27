<?php

namespace Tests\Feature;

use App\Models\CustomerProductView;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_index_displays_views_and_total_sold_quantity_from_the_database(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $category = ProductCategory::create([
            'name_ar' => 'منتجات الاختبار',
            'slug' => 'admin-product-analytics',
            'is_active' => true,
            'show_in_store' => true,
        ]);
        $product = Product::create([
            'product_category_id' => $category->id,
            'name_ar' => 'منتج الإحصاءات',
            'slug' => 'analytics-product',
            'price_cents' => 19500,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ]);

        foreach (range(1, 3) as $index) {
            CustomerProductView::create([
                'product_id' => $product->id,
                'session_id' => 'admin-product-view-'.$index,
                'viewed_at' => now(),
            ]);
        }

        $firstOrder = Order::create(['order_number' => 'HK-PRODUCT-STATS-1']);
        $secondOrder = Order::create(['order_number' => 'HK-PRODUCT-STATS-2']);

        $firstOrder->items()->create($this->productItem($product, 'product', 3));
        $secondOrder->items()->create($this->productItem($product, 'product', 3));

        $deletedOrder = Order::create(['order_number' => 'HK-PRODUCT-STATS-DELETED']);
        $deletedOrder->items()->create($this->productItem($product, 'product'));
        $deletedOrder->delete();

        $this->actingAs($admin)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee('المشاهدات')
            ->assertSee('الكمية المباعة')
            ->assertViewHas('products', function ($products) use ($product): bool {
                $listedProduct = $products->firstWhere('id', $product->id);

                return $listedProduct !== null
                    && (int) $listedProduct->views_count === 3
                    && (int) $listedProduct->sold_quantity === 6;
            });
    }

    /** @return array<string, mixed> */
    private function productItem(Product $product, string $type, int $quantity = 1): array
    {
        return [
            'item_type' => $type,
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => $product->price_cents,
            'quantity' => $quantity,
            'total_price_cents' => $product->price_cents * $quantity,
        ];
    }
}
