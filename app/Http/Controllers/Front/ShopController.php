<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\CustomerProductView;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Catalog\UnifiedStorefrontService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Support\ProductPersonalizationSchema;
use App\Support\StoryAgeOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function show(Request $request, Product $product, TemporaryPhotoUploadService $uploads)
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

        $personalizationSchema = ProductPersonalizationSchema::forProduct($product);
        $personalizationFields = ProductPersonalizationSchema::enabledFields($personalizationSchema);
        $photoField = $personalizationFields['photos'] ?? null;
        $photoUploadConfig = null;

        if ($photoField) {
            $uploadSession = $uploads->ensureSession($request);
            $photoUploadConfig = [
                'sessionToken' => $uploadSession['token'],
                'batchToken' => Str::random(48),
                'uploadUrl' => route('photo-uploads.store'),
                'previewUrlTemplate' => route('photo-uploads.show', ['publicId' => '__ID__']),
                'deleteUrlTemplate' => route('photo-uploads.destroy', ['publicId' => '__ID__']),
                'maxFiles' => (int) $photoField['max_files'],
                'minimumFiles' => $photoField['required'] ? (int) $photoField['min_files'] : 0,
                'maxSizeMb' => (int) config('photo_uploads.max_size_mb', 15),
                'concurrency' => (int) config('photo_uploads.concurrency', 2),
                'maxLongEdge' => (int) config('photo_uploads.max_long_edge', 2560),
                'jpegQuality' => (int) config('photo_uploads.jpeg_quality', 90),
                'storageKey' => 'herokid:product:'.$product->slug.':photo-upload-ids',
                'readyLabel' => 'إضافة المنتج للسلة',
                'clearStorageOnSubmit' => true,
            ];
        }

        return view('front.shop.show', compact(
            'product',
            'storyItems',
            'relatedProducts',
            'photoUploadConfig',
            'personalizationSchema',
            'personalizationFields',
        ) + ['ageOptions' => StoryAgeOptions::forPersonalization()]);
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
