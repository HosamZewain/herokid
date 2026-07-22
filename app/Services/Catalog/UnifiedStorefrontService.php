<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Services\Pricing\StoryPricingService;
use App\ViewModels\Catalog\UnifiedCatalogItem;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class UnifiedStorefrontService
{
    public function __construct(private readonly StoryPricingService $storyPricing) {}

    /**
     * @return array{
     *     items: LengthAwarePaginator,
     *     storyCategories: Collection<int, StoryCategory>,
     *     productCategories: Collection<int, ProductCategory>,
     *     ageRanges: Collection<int, string>,
     *     totalStories: int,
     *     totalProducts: int
     * }
     */
    public function storefront(Request $request, bool $productsEnabled = true, int $defaultPerPage = 24): array
    {
        $type = $this->validatedType($request->input('type'));
        $needsStories = in_array($type, ['all', 'stories'], true);
        $needsProducts = $productsEnabled && in_array($type, ['all', 'products', 'gifts', 'activities'], true);

        $stories = $needsStories
            ? Story::query()
                ->with('categories:id,name,slug')
                ->where('active', true)
                ->get()
                ->map(fn (Story $story) => $this->storyItem($story))
            : collect();

        $products = $needsProducts
            ? Product::query()
                ->with('category:id,name_ar,slug,is_active,show_in_store')
                ->publiclyVisible()
                ->get()
                ->map(fn (Product $product) => $this->productItem($product))
            : collect();

        $items = $stories->concat($products);

        if (in_array($type, ['gifts', 'activities'], true)) {
            $items = $items->where('section', $type);
        }

        $items = $this->filter($items, $request);
        $items = $this->sort($items, $request->input('sort', setting('unified_store_default_sort', 'featured')));

        $perPage = in_array((int) $request->input('per_page'), [12, 20, 24, 30], true)
            ? (int) $request->input('per_page')
            : $defaultPerPage;
        $page = max(1, (int) $request->input('page', 1));
        $paginator = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $storyCategories = StoryCategory::query()
            ->whereHas('stories', fn ($query) => $query->where('active', true))
            ->withCount(['stories' => fn ($query) => $query->where('active', true)])
            ->orderBy('name')
            ->get();
        $productCategories = $productsEnabled
            ? ProductCategory::query()
                ->where('is_active', true)
                ->where('show_in_store', true)
                ->whereHas('activeProducts')
                ->orderBy('sort_order')
                ->get()
            : collect();

        return [
            'items' => $paginator,
            'storyCategories' => $storyCategories,
            'productCategories' => $productCategories,
            'ageRanges' => $this->ageRanges($stories, $products),
            'totalStories' => $needsStories ? $stories->count() : Story::query()->where('active', true)->count(),
            'totalProducts' => ! $productsEnabled
                ? 0
                : ($needsProducts ? $products->count() : Product::query()->publiclyVisible()->count()),
        ];
    }

    private function storyItem(Story $story): UnifiedCatalogItem
    {
        $categories = $story->categories;
        $category = $categories->first();
        $ageRange = trim((string) $story->age_range) ?: 'كل الأعمار';
        $shortDescription = trim((string) $story->short_desc);
        $regularPrice = $this->storyPricing->regularPrice($story);
        $effectivePrice = $this->storyPricing->effectivePrice($story);
        $hasOffer = $this->storyPricing->hasActiveOffer($story);

        return new UnifiedCatalogItem(
            id: 'story:'.$story->id,
            type: 'story',
            sourceModel: $story,
            title: $story->title,
            slug: $story->slug,
            description: trim((string) $story->full_desc),
            shortDescription: $shortDescription,
            imageUrl: $story->cover_url,
            price: $effectivePrice,
            priceLabel: format_money($effectivePrice),
            ageRange: $ageRange,
            ageValues: $ageRange === 'كل الأعمار' ? [] : [$ageRange],
            category: $category?->name,
            categorySlug: $category?->slug,
            categorySource: 'story',
            tags: $categories->pluck('name')->filter()->values()->all(),
            personalizationType: 'requires_child_photos',
            personalizationLabel: 'يحتاج اسم وصورة الطفل',
            isFeatured: false,
            sortOrder: 0,
            detailUrl: route('stories.show', $story->slug),
            ctaLabel: 'خصّص القصة',
            badgeLabel: $category?->name ?: 'قصة مخصصة',
            searchableText: Str::lower(implode(' ', array_filter([
                $story->title,
                $shortDescription,
                $story->lesson_value,
                $categories->pluck('name')->implode(' '),
            ]))),
            section: 'stories',
            createdTimestamp: $story->created_at?->timestamp ?? 0,
            originalPrice: $hasOffer ? $regularPrice : null,
            originalPriceLabel: $hasOffer ? format_money($regularPrice) : null,
            offerLabel: $hasOffer ? $this->storyPricing->offerLabel() : null,
        );
    }

    private function productItem(Product $product): UnifiedCatalogItem
    {
        $categoryName = $product->category?->name_ar;
        $section = $this->productSection($product);
        $personalizationType = match ($product->personalization_mode) {
            'inherit_from_linked_story' => 'story_context',
            'collect_child_details' => 'requires_child_photos',
            default => 'none',
        };
        $personalizationLabel = match ($personalizationType) {
            'story_context' => 'يستخدم قصة الطفل',
            'requires_child_photos' => 'يحتاج بيانات الطفل',
            default => 'لا يحتاج تخصيص',
        };
        $ctaLabel = match (true) {
            $personalizationType === 'story_context', $product->purchase_mode === 'add_on_only' => 'أضف مع قصة طفلك',
            $personalizationType === 'requires_child_photos' => 'خصّص المنتج',
            default => 'شراء الآن',
        };

        return new UnifiedCatalogItem(
            id: 'product:'.$product->id,
            type: 'product',
            sourceModel: $product,
            title: $product->name_ar,
            slug: $product->slug,
            description: trim((string) $product->description_ar),
            shortDescription: trim((string) $product->short_description_ar),
            imageUrl: $product->featured_image_url,
            price: $product->effectivePrice(),
            priceLabel: format_money($product->effectivePrice()),
            ageRange: $product->ageLabel(),
            ageValues: $product->age_groups ?? [],
            category: $categoryName,
            categorySlug: $product->category?->slug,
            categorySource: 'product',
            tags: array_values(array_filter([$categoryName, ...($product->features ?? [])])),
            personalizationType: $personalizationType,
            personalizationLabel: $personalizationLabel,
            isFeatured: (bool) $product->is_featured,
            sortOrder: (int) $product->sort_order,
            detailUrl: route('shop.product.show', $product),
            ctaLabel: $ctaLabel,
            badgeLabel: $categoryName ?: $this->productBadge($product, $section),
            searchableText: Str::lower(implode(' ', array_filter([
                $product->name_ar,
                $product->name_en,
                $product->short_description_ar,
                $categoryName,
                implode(' ', $product->features ?? []),
            ]))),
            section: $section,
            createdTimestamp: $product->created_at?->timestamp ?? 0,
        );
    }

    /** @param Collection<int, UnifiedCatalogItem> $items */
    private function filter(Collection $items, Request $request): Collection
    {
        if ($request->filled('category')) {
            [$source, $slug] = str_contains((string) $request->category, ':')
                ? explode(':', (string) $request->category, 2)
                : [null, (string) $request->category];
            $items = $items->filter(fn (UnifiedCatalogItem $item) => $item->categorySlug === $slug
                && ($source === null || $item->categorySource === $source));
        }

        if ($request->filled('age')) {
            $requestedAge = $this->normalizeAge((string) $request->age);
            $items = $items->filter(function (UnifiedCatalogItem $item) use ($requestedAge) {
                if ($item->ageValues === []) {
                    return true;
                }

                return collect($item->ageValues)
                    ->contains(fn ($age) => $this->normalizeAge((string) $age) === $requestedAge);
            });
        }

        if ($request->filled('personalization')) {
            $items = $items->where('personalizationType', (string) $request->personalization);
        }

        if ($request->filled('q')) {
            $needle = Str::lower(Str::squish((string) $request->q));
            $items = $items->filter(fn (UnifiedCatalogItem $item) => str_contains($item->searchableText, $needle));
        }

        if ($request->filled('gender') && in_array($request->gender, ['boy', 'girl'], true)) {
            $items = $items->filter(fn (UnifiedCatalogItem $item) => $item->type !== 'story'
                || in_array($item->sourceModel->gender, [$request->gender, 'both'], true));
        }

        if ($request->filled('lang') && in_array($request->lang, ['ar', 'en'], true)) {
            $items = $items->filter(fn (UnifiedCatalogItem $item) => $item->type !== 'story'
                || $item->sourceModel->language === $request->lang);
        }

        if ($request->input('availability') === 'available') {
            $items = $items->filter(fn (UnifiedCatalogItem $item) => $item->type !== 'product'
                || $item->sourceModel->hasStock());
        }

        return $items->values();
    }

    /** @param Collection<int, UnifiedCatalogItem> $items */
    private function sort(Collection $items, ?string $sort): Collection
    {
        return match ($sort) {
            'newest' => $items->sortByDesc('createdTimestamp')->values(),
            'price_asc' => $items->sortBy(fn (UnifiedCatalogItem $item) => [$item->price, $item->title])->values(),
            'price_desc' => $items->sortByDesc(fn (UnifiedCatalogItem $item) => [$item->price, $item->createdTimestamp])->values(),
            default => $this->featuredSort($items),
        };
    }

    /** @param Collection<int, UnifiedCatalogItem> $items */
    private function featuredSort(Collection $items): Collection
    {
        $sorted = $items->sortBy(fn (UnifiedCatalogItem $item) => [
            $item->isFeatured ? 0 : 1,
            $item->sortOrder,
            -$item->createdTimestamp,
            $item->title,
        ])->values();

        $stories = $sorted->where('type', 'story')->values();
        $products = $sorted->where('type', 'product')->values();

        if ($stories->isEmpty() || $products->isEmpty()) {
            return $sorted;
        }

        $firstType = $sorted->first()->type;
        $primary = $firstType === 'product' ? $products : $stories;
        $secondary = $firstType === 'product' ? $stories : $products;
        $interleaved = collect();
        $maximum = max($primary->count(), $secondary->count());

        for ($index = 0; $index < $maximum; $index++) {
            if ($primary->has($index)) {
                $interleaved->push($primary->get($index));
            }

            if ($secondary->has($index)) {
                $interleaved->push($secondary->get($index));
            }
        }

        return $interleaved;
    }

    /**
     * @param  Collection<int, UnifiedCatalogItem>  $stories
     * @param  Collection<int, UnifiedCatalogItem>  $products
     * @return Collection<int, string>
     */
    private function ageRanges(Collection $stories, Collection $products): Collection
    {
        $configured = collect(setting_array('age_ranges'));
        $catalogRanges = $stories->concat($products)
            ->flatMap(fn (UnifiedCatalogItem $item) => $item->ageValues)
            ->filter();

        return $configured->concat($catalogRanges)
            ->unique(fn ($age) => $this->normalizeAge((string) $age))
            ->values();
    }

    private function productSection(Product $product): string
    {
        $haystack = Str::lower(implode(' ', [
            $product->category?->slug,
            $product->category?->name_ar,
            $product->category?->name_en,
            $product->name_ar,
            $product->name_en,
        ]));

        if (Str::contains($haystack, ['gift', 'poster', 'هدية', 'هدايا', 'بوستر'])) {
            return 'gifts';
        }

        if (Str::contains($haystack, ['activity', 'activities', 'color', 'maze', 'learn', 'نشاط', 'أنشطة', 'تلوين', 'متاهات', 'تعلم'])) {
            return 'activities';
        }

        return 'products';
    }

    private function productBadge(Product $product, string $section): string
    {
        $haystack = Str::lower(implode(' ', [$product->name_ar, $product->name_en, $product->category?->name_ar, $product->category?->slug]));

        return match (true) {
            Str::contains($haystack, ['color', 'تلوين']) => 'كتاب تلوين',
            Str::contains($haystack, ['maze', 'متاهات']) => 'كتاب متاهات',
            $section === 'activities' => 'كتاب نشاط',
            $section === 'gifts' && $product->personalization_mode !== 'none' => 'هدية مخصصة',
            $section === 'gifts' => 'هدية',
            $product->personalization_mode !== 'none' => 'منتج مخصص',
            default => 'منتج مباشر',
        };
    }

    private function validatedType(?string $type): string
    {
        return in_array($type, ['all', 'stories', 'products', 'gifts', 'activities'], true) ? $type : 'all';
    }

    private function normalizeAge(string $age): string
    {
        return Str::of($age)
            ->replace(['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'])
            ->replace(['سنوات', 'سنة', 'years', 'year', '–', '—'], ['', '', '', '', '-', '-'])
            ->replaceMatches('/\s+/', '')
            ->lower()
            ->toString();
    }
}
