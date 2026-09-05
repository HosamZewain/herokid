<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductUpsellRule;
use App\Models\Story;
use Illuminate\Support\Collection;

class ProductRecommendations
{
    public function forProduct(Product $source, int $limit = 4, array $excludedProductIds = []): Collection
    {
        $excludedProductIds = array_values(array_unique(array_filter([
            (int) $source->id,
            ...array_map('intval', $excludedProductIds),
        ])));

        return ProductUpsellRule::query()
            ->with(['targetProduct.category', 'targetProduct.activeVariants'])
            ->where('is_active', true)
            ->where('source_product_id', $source->id)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->pluck('targetProduct')
            ->filter(fn ($product) => $product
                && $product->is_active
                && $product->category?->is_active
                && $product->category?->show_in_store
                && $product->hasStock()
                && ! in_array((int) $product->id, $excludedProductIds, true))
            ->unique('id')
            ->take($limit)
            ->values();
    }

    public function forCartItems(array $items, int $limit = 6): Collection
    {
        $cart = collect($items);
        $excludedProductIds = $cart->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $sources = Product::query()->whereIn('id', $excludedProductIds)->get();
        $recommendations = collect();

        foreach ($sources as $source) {
            $recommendations = $recommendations->merge($this->forProduct($source, $limit, $excludedProductIds));
        }

        foreach ($cart->filter(fn (array $item): bool => ($item['item_type'] ?? 'story') === 'story') as $storyItem) {
            $recommendations = $recommendations->merge($this->forStoryCartItem($storyItem, $limit, $excludedProductIds));
        }

        return $recommendations->unique('id')->take($limit)->values();
    }

    public function forStoryCartItem(array $item, int $limit = 6, array $excludedProductIds = []): Collection
    {
        $story = Story::with('categories')->find($item['story_id'] ?? null);
        $age = (string) ($item['child_age'] ?? '');
        $gender = $item['child_gender'] ?? null;
        $categoryIds = $story?->categories?->pluck('id')->all() ?? [];
        $excludedProductIds = array_values(array_filter(array_map('intval', $excludedProductIds)));

        $ruleProducts = ProductUpsellRule::query()
            ->with(['targetProduct.category', 'targetProduct.activeVariants'])
            ->where('is_active', true)
            ->whereNull('source_product_id')
            ->where(function ($query) use ($story, $categoryIds, $age, $gender) {
                $query->whereNull('source_story_id')->orWhere('source_story_id', $story?->id);
                $query->where(function ($builder) use ($categoryIds) {
                    $builder->whereNull('source_story_category_id');
                    if ($categoryIds !== []) {
                        $builder->orWhereIn('source_story_category_id', $categoryIds);
                    }
                });
                $query->where(function ($builder) use ($age) {
                    $builder->whereNull('age_group')->orWhere('age_group', $age);
                });
                $query->where(function ($builder) use ($gender) {
                    $builder->whereNull('gender')->orWhere('gender', $gender);
                });
            })
            ->orderByDesc('priority')
            ->get()
            ->pluck('targetProduct')
            ->filter(fn ($product) => $product
                && $product->is_active
                && $product->category?->is_active
                && ! in_array((int) $product->id, $excludedProductIds, true));

        $fallbackProducts = Product::query()
            ->with(['category', 'activeVariants'])
            ->publiclyVisible()
            ->where('personalization_mode', '!=', 'collect_child_details')
            ->when($excludedProductIds !== [], fn ($query) => $query->whereNotIn('id', $excludedProductIds))
            ->orderByRaw("CASE WHEN personalization_mode = 'inherit_from_linked_story' THEN 0 ELSE 1 END")
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->get()
            ->filter(function (Product $product) use ($age): bool {
                if ($product->age_groups === null || $product->age_groups === []) {
                    return true;
                }

                return collect($product->age_groups)
                    ->contains(fn (string $range): bool => in_array((int) $age, StoryAgeOptions::fromRange($range), true));
            });

        return $ruleProducts
            ->merge($fallbackProducts)
            ->unique('id')
            ->take($limit)
            ->values();
    }
}
