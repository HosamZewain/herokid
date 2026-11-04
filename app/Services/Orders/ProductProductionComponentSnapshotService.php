<?php

namespace App\Services\Orders;

use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class ProductProductionComponentSnapshotService
{
    public function captureForItem(OrderItem $item): void
    {
        if (! $item->product_id || ! in_array($item->item_type, ['product', 'product_add_on'], true)) {
            return;
        }

        $item->loadMissing('product.productionComponents');
        if (! $item->product) {
            return;
        }

        $components = $item->product->productionComponents->where('is_active', true)->values();
        if ($components->isEmpty()) {
            return;
        }

        if ($item->productionComponents()->whereIn('production_stage', ['book_cover', 'book_interior'])->exists()) {
            return;
        }

        // New checkout snapshots must retain the selected variant's page count,
        // rather than the catalog's generic component quantity (historically 1).
        if ($item->wasRecentlyCreated && $components->contains('production_stage', 'book_interior')
            && $item->product_variant_id && ! data_get($item->variant_snapshot, 'book_page_count')) {
            $variant = $item->product->variants()->find($item->product_variant_id);
            if ($variant?->book_page_count && (int) data_get($item->variant_snapshot, 'id') === $variant->id
                && data_get($item->variant_snapshot, 'name_ar') === $variant->name_ar) {
                $item->updateQuietly(['variant_snapshot' => array_merge($item->variant_snapshot ?? [], ['book_page_count' => (int) $variant->book_page_count])]);
            }
        }

        foreach ($components as $component) {
            $item->productionComponents()->updateOrCreate(
                ['stable_key' => $component->stable_key],
                [
                    'product_production_component_id' => $component->id,
                    'name' => $component->name,
                    'production_stage' => $component->production_stage ?? 'standard',
                    'prompt_template' => $component->prompt_template,
                    'quantity_per_item' => $component->production_stage === 'book_interior'
                        ? (data_get($item->variant_snapshot, 'book_page_count') ?: $component->quantity_per_item)
                        : $component->quantity_per_item,
                    'sort_order' => $component->sort_order,
                ],
            );
        }
    }

    public function refreshForItem(OrderItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $item->productionComponents()->delete();
            $item->unsetRelation('productionComponents');
            $item->unsetRelation('product');
            $this->captureForItem($item);
        });
    }
}
