<?php

namespace App\Services\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogSalesRankingService
{
    /**
     * @param  Collection<int, int>  $storyIds
     * @param  Collection<int, int>  $productIds
     * @return array{stories: array<int, int>, products: array<int, int>}
     */
    public function counts(Collection $storyIds, Collection $productIds): array
    {
        $storyIds = $storyIds->map(fn ($id): int => (int) $id)->unique()->values();
        $productIds = $productIds->map(fn ($id): int => (int) $id)->unique()->values();

        $storyCounts = collect();
        if ($storyIds->isNotEmpty()) {
            $itemCounts = $this->storyItemCounts($storyIds);
            $legacyCounts = $this->legacyStoryCounts($storyIds);
            $storyCounts = $itemCounts->keys()->merge($legacyCounts->keys())->unique()
                ->mapWithKeys(fn ($id): array => [
                    (int) $id => (int) $itemCounts->get($id, 0) + (int) $legacyCounts->get($id, 0),
                ]);
        }

        $productCounts = $productIds->isEmpty()
            ? collect()
            : $this->productItemCounts($productIds);

        return [
            'stories' => $storyCounts->mapWithKeys(fn ($quantity, $id): array => [(int) $id => (int) $quantity])->all(),
            'products' => $productCounts->mapWithKeys(fn ($quantity, $id): array => [(int) $id => (int) $quantity])->all(),
        ];
    }

    /**
     * @param  Collection<int, int>  $storyIds
     * @param  Collection<int, int>  $productIds
     * @return array{stories: array<int, int>, products: array<int, int>}
     */
    public function viewCounts(Collection $storyIds, Collection $productIds): array
    {
        $storyCounts = $storyIds->isEmpty()
            ? collect()
            : DB::table('customer_story_views')
                ->whereIn('story_id', $storyIds)
                ->groupBy('story_id')
                ->selectRaw('story_id as catalog_id, COUNT(*) as views_count')
                ->pluck('views_count', 'catalog_id');
        $productCounts = $productIds->isEmpty()
            ? collect()
            : DB::table('customer_product_views')
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id')
                ->selectRaw('product_id as catalog_id, COUNT(*) as views_count')
                ->pluck('views_count', 'catalog_id');

        return [
            'stories' => $storyCounts->mapWithKeys(fn ($count, $id): array => [(int) $id => (int) $count])->all(),
            'products' => $productCounts->mapWithKeys(fn ($count, $id): array => [(int) $id => (int) $count])->all(),
        ];
    }

    /** @param Collection<int, int> $storyIds */
    private function storyItemCounts(Collection $storyIds): Collection
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.item_type', 'story')
            ->whereIn('order_items.story_id', $storyIds);

        $this->onlyRecordedOrders($query);

        return $query
            ->groupBy('order_items.story_id')
            ->selectRaw('order_items.story_id as catalog_id, SUM(order_items.quantity) as sold_quantity')
            ->pluck('sold_quantity', 'catalog_id')
            ->map(fn ($quantity): int => (int) $quantity);
    }

    /** @param Collection<int, int> $storyIds */
    private function legacyStoryCounts(Collection $storyIds): Collection
    {
        $query = DB::table('orders')
            ->whereIn('orders.story_id', $storyIds)
            ->whereNotExists(fn (Builder $items) => $items
                ->selectRaw('1')
                ->from('order_items')
                ->whereColumn('order_items.order_id', 'orders.id')
                ->where('order_items.item_type', 'story'));

        $this->onlyRecordedOrders($query);

        return $query
            ->groupBy('orders.story_id')
            ->selectRaw('orders.story_id as catalog_id, COUNT(*) as sold_quantity')
            ->pluck('sold_quantity', 'catalog_id')
            ->map(fn ($quantity): int => (int) $quantity);
    }

    /** @param Collection<int, int> $productIds */
    private function productItemCounts(Collection $productIds): Collection
    {
        $query = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.item_type', ['product', 'product_add_on'])
            ->whereIn('order_items.product_id', $productIds);

        $this->onlyRecordedOrders($query);

        return $query
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id as catalog_id, SUM(order_items.quantity) as sold_quantity')
            ->pluck('sold_quantity', 'catalog_id')
            ->map(fn ($quantity): int => (int) $quantity);
    }

    private function onlyRecordedOrders(Builder $query): void
    {
        $query->whereNull('orders.deleted_at');
    }
}
