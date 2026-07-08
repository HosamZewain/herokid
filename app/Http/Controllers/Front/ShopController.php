<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(setting('shop_enabled', '1') === '1', 404);

        return $this->listing($request);
    }

    public function category(Request $request, ProductCategory $category)
    {
        abort_unless(setting('shop_enabled', '1') === '1', 404);
        abort_unless($category->is_active && $category->show_in_store, 404);

        return $this->listing($request, $category);
    }

    public function show(Product $product)
    {
        abort_unless(setting('shop_enabled', '1') === '1', 404);
        abort_unless($product->is_active && $product->category?->is_active && $product->category?->show_in_store, 404);

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

        $facebookViewContentEvent = [
            'event_id' => 'hk-view-product-'.(string) Str::uuid(),
            'data' => [
                'content_type' => 'product',
                'content_ids' => ['product:'.$product->id],
                'contents' => [[
                    'id' => 'product:'.$product->id,
                    'quantity' => 1,
                    'item_price' => round($product->effectivePrice(), 2),
                ]],
                'content_name' => $product->name_ar,
                'content_category' => $product->category?->name_ar,
                'value' => round($product->effectivePrice(), 2),
                'currency' => 'EGP',
            ],
        ];

        return view('front.shop.show', compact('product', 'storyItems', 'relatedProducts', 'facebookViewContentEvent'));
    }

    private function listing(Request $request, ?ProductCategory $category = null)
    {
        $categories = ProductCategory::query()
            ->where('is_active', true)
            ->where('show_in_store', true)
            ->whereHas('activeProducts')
            ->orderBy('sort_order')
            ->get();

        $products = Product::query()
            ->with('category')
            ->publiclyVisible()
            ->when($category, fn (Builder $query) => $query->where('product_category_id', $category->id))
            ->when($request->filled('category'), fn (Builder $query) => $query->whereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('slug', $request->category)))
            ->forAgeGroup($request->input('age'))
            ->when($request->filled('personalization'), fn (Builder $query) => $query->where('personalization_mode', $request->personalization))
            ->when($request->input('availability') === 'available', fn (Builder $query) => $query->where(function (Builder $builder) {
                $builder->where('inventory_mode', '!=', 'track_stock')->orWhere('stock_quantity', '>', 0)->orWhereNull('stock_quantity');
            }));

        match ($request->input('sort', 'featured')) {
            'newest' => $products->latest(),
            'price_asc' => $products->orderByRaw('coalesce(sale_price_cents, price_cents) asc'),
            'price_desc' => $products->orderByRaw('coalesce(sale_price_cents, price_cents) desc'),
            default => $products->orderByDesc('is_featured')->orderBy('sort_order')->latest(),
        };

        return view('front.shop.index', [
            'categories' => $categories,
            'products' => $products->paginate(12)->withQueryString(),
            'currentCategory' => $category,
        ]);
    }
}
