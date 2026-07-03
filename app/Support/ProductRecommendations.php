<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductUpsellRule;
use App\Models\Story;
use Illuminate\Support\Collection;

class ProductRecommendations
{
    public function forStoryCartItem(array $item, int $limit = 6): Collection
    {
        $story = Story::with('categories')->find($item['story_id'] ?? null);
        $age = (string) ($item['child_age'] ?? '');
        $gender = $item['child_gender'] ?? null;
        $categoryIds = $story?->categories?->pluck('id')->all() ?? [];

        $ruleProducts = ProductUpsellRule::query()
            ->with('targetProduct.category')
            ->where('is_active', true)
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
            ->filter(fn ($product) => $product && $product->is_active && $product->category?->is_active);

        $fallbackProducts = Product::query()
            ->with('category')
            ->publiclyVisible()
            ->forAgeGroup($age)
            ->where(function ($query) {
                $query->where('personalization_mode', 'inherit_from_linked_story')
                    ->orWhere('is_featured', true);
            })
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();

        return $ruleProducts
            ->merge($fallbackProducts)
            ->unique('id')
            ->take($limit)
            ->values();
    }
}
