<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\CustomerProductView;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Catalog\UnifiedStorefrontService;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request, UnifiedStorefrontService $storefront)
    {
        return $this->listing($request, $storefront);
    }

    public function category(Request $request, ProductCategory $category, UnifiedStorefrontService $storefront)
    {
        abort_unless(setting('shop_enabled', '1') === '1', 404);
        abort_unless($category->is_active && $category->show_in_store, 404);

        return $this->listing($request, $storefront, $category);
    }

    public function show(Request $request, Product $product)
    {
        abort_unless(setting('shop_enabled', '1') === '1', 404);
        abort_unless($product->is_active && $product->category?->is_active && $product->category?->show_in_store, 404);

        CustomerProductView::create([
            'user_id' => $request->user()?->id,
            'product_id' => $product->id,
            'session_id' => $request->session()->getId(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'viewed_at' => now(),
        ]);

        $product->load(['category', 'activeVariants']);
        $storyItems = collect(session('cart.items', []))
            ->filter(fn (array $item) => ($item['item_type'] ?? 'story') === 'story')
            ->values();
        $relatedProducts = Product::query()
            ->with('category')
            ->publiclyVisible()
            ->where('id', '!=', $product->id)
            ->where('product_category_id', $product->product_category_id)
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->take(4)
            ->get();

        return view('front.shop.show', compact('product', 'storyItems', 'relatedProducts'));
    }

    private function listing(Request $request, UnifiedStorefrontService $storefront, ?ProductCategory $category = null)
    {
        if ($category) {
            $request->merge([
                'type' => 'products',
                'category' => 'product:'.$category->slug,
            ]);
        }

        return view('front.shop.index', array_merge(
            $storefront->storefront($request, productsEnabled: setting('shop_enabled', '1') === '1'),
            [
                'currentCategory' => $category,
                'isStoriesAlias' => false,
                'packages' => PricingPackage::active()->purchasable()->where('show_in_store', true)->with(['items.product', 'items.variant', 'eligibleStories'])->ordered()->get()->filter->availableForPurchase(),
            ],
        ));
    }
}
