<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Story;
use App\Services\Catalog\UnifiedStorefrontService;
use App\Services\Mobile\MobileAnalyticsRecorder;
use App\Services\Mobile\MobileCatalogPresenter;
use App\Support\Seo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

class CatalogController extends Controller
{
    public function index(Request $request, UnifiedStorefrontService $catalog, MobileCatalogPresenter $presenter): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:all,stories,products,gifts,activities'],
            'category' => ['nullable', 'string', 'max:160'],
            'age' => ['nullable', 'string', 'max:80'],
            'gender' => ['nullable', 'in:boy,girl'],
            'lang' => ['nullable', 'in:ar,en'],
            'locale' => ['nullable', 'in:ar,en'],
            'personalization' => ['nullable', 'in:none,story_context,requires_child_photos'],
            'sort' => ['nullable', 'in:featured,newest,price_asc,price_desc'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'in:12,20,24,30'],
        ]);

        if (isset($validated['locale'])) {
            App::setLocale($validated['locale']);
            $validated['lang'] ??= $validated['locale'];
        }
        $request->merge($validated);
        $result = $catalog->storefront($request, true, 20);
        $paginator = $result['items'];

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($item) => $presenter->item($item))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filters' => [
                    'age_ranges' => $result['ageRanges']->values(),
                    'story_categories' => $result['storyCategories']->map->only(['name', 'slug'])->values(),
                    'product_categories' => $result['productCategories']->map(fn ($category) => [
                        'name' => app()->getLocale() === 'en' ? ($category->name_en ?: $category->name_ar) : $category->name_ar,
                        'slug' => $category->slug,
                    ])->values(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $type, string $slug, MobileCatalogPresenter $presenter, MobileAnalyticsRecorder $analytics): JsonResponse
    {
        $locale = in_array($request->query('locale'), ['ar', 'en'], true) ? $request->query('locale') : 'ar';
        App::setLocale($locale);
        $catalogRequest = Request::create('/api/v1/catalog', 'GET', [
            'type' => $type === 'story' ? 'stories' : 'products',
            'per_page' => 30,
            'lang' => $type === 'story' ? $locale : null,
        ]);
        $result = app(UnifiedStorefrontService::class)->storefront($catalogRequest, true, 30);
        $item = collect($result['items']->items())->first(fn ($candidate) => $candidate->slug === $slug);

        abort_unless($item, 404);
        $payload = $presenter->item($item);
        $analytics->record($request, 'product_viewed', ['item_type' => $type, 'item_id' => (int) str($item->id)->after(':')->toString(), 'item_slug' => $slug]);

        if ($type === 'story') {
            $story = Story::query()->where('active', true)->where('slug', $slug)->firstOrFail();
            $payload['details'] = [
                'language' => $story->language,
                'lesson' => $story->lesson_value,
                'gender' => $story->gender,
                'gallery_images' => $story->gallery_images ?? [],
            ];
        } else {
            $product = Product::query()->publiclyVisible()->with('activeVariants')->where('slug', $slug)->firstOrFail();
            $payload['details'] = [
                'features' => $product->features ?? [],
                'gallery_images' => $product->gallery_images ?? [],
                'production_lead_time_days' => $product->production_lead_time_days,
                'variants' => $product->activeVariants->map(fn ($variant): array => [
                    'id' => $variant->id,
                    'name_ar' => $variant->name_ar,
                    'name_en' => $variant->name_en,
                    'sku' => $variant->sku,
                    'image_url' => $variant->image_url ?: $product->featured_image_url,
                    'gallery_images' => $variant->gallery_image_urls !== []
                        ? $variant->gallery_image_urls
                        : collect($product->gallery_images ?? [])
                            ->map(fn (string $image): string => Seo::imageUrl(Storage::disk('public')->url($image)))
                            ->values()
                            ->all(),
                    'attributes' => $variant->attributes ?? [],
                    'price_amount' => $product->effectivePriceCents($variant) / 100,
                    'available' => $product->hasStock(1, $variant),
                ])->values(),
            ];
        }

        return response()->json(['data' => $payload]);
    }
}
