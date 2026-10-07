<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\FaqItem;
use App\Models\HomepageStoreSection;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\Story;
use App\Models\Testimonial;
use App\Services\Catalog\CatalogSalesRankingService;
use App\Services\Catalog\UnifiedStorefrontService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(CatalogSalesRankingService $ranking, UnifiedStorefrontService $storefront): View
    {
        $featuredStories = new Collection;
        if (homepage_section_enabled('hero') || homepage_section_enabled('stories')) {
            $activeStories = Story::where('active', true)->with('categories')->get();
            $salesCounts = $ranking->counts($activeStories->pluck('id'), collect())['stories'];
            $featuredStories = $activeStories->sortBy(fn (Story $story): array => [
                -($salesCounts[$story->id] ?? 0), $story->title,
            ])->take(8)->values();
        }

        $shopEnabled = setting('shop_enabled', '1') === '1';
        $featuredProducts = $shopEnabled && homepage_section_enabled('store')
            ? Product::publiclyVisible()->with('category')->orderByDesc('is_featured')
                ->orderBy('sort_order')->latest()->take(4)->get()
            : collect();

        // Reuse the storefront's price, visibility and detail-link contract. No sample SKUs.
        $homeCatalogItems = collect();
        if (homepage_section_enabled('store')) {
            $homeCatalogItems = $featuredProducts->map(fn (Product $product) => $storefront->productItem($product));
        }
        if (homepage_section_enabled('stories')) {
            // Reserve one slot for stories on a mixed homepage, and gracefully fill an empty store.
            $homeCatalogItems = $homeCatalogItems->take($featuredStories->isNotEmpty() ? 3 : 4)
                ->concat($featuredStories->take(4 - min(3, $homeCatalogItems->count()))
                    ->map(fn (Story $story) => $storefront->storyItem($story)));
        }
        $homeCatalogItems = $homeCatalogItems->take(4)->values();

        $faqs = homepage_section_enabled('faq')
            ? FaqItem::where('active', true)->orderBy('sort_order')->take(5)->get() : collect();
        $testimonials = homepage_section_enabled('testimonials')
            ? Testimonial::where('active', true)->orderBy('sort_order')->get() : collect();
        $packages = homepage_section_enabled('pricing')
            ? PricingPackage::active()->purchasable()->where('show_on_homepage', true)->where('show_in_store', true)
                ->with(['items.product', 'items.variant', 'eligibleStories'])->ordered()->get()
                ->filter->availableForPurchase()->take(5)->values() : collect();
        $storeSections = homepage_section_enabled('store') && $shopEnabled
            ? HomepageStoreSection::query()
                ->with(['category.activeProducts' => fn ($query) => $query->orderByDesc('is_featured')->orderBy('sort_order')->latest()])
                ->where('is_active', true)->orderBy('sort_order')->get()
                ->filter(fn ($section) => $section->category && $section->category->activeProducts->isNotEmpty())
            : collect();

        return view('welcome', compact('featuredStories', 'featuredProducts', 'homeCatalogItems', 'shopEnabled',
            'faqs', 'testimonials', 'packages', 'storeSections'));
    }
}
