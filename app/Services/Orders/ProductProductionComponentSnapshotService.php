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

        foreach ($components as $component) {
            $existing = $item->productionComponents()->where('stable_key', $component->stable_key)->first();
            $studioSnapshot = $existing
                ? $existing->only(['studio_enabled', 'studio_workflow', 'studio_recipe_version', 'studio_recipe'])
                : $component->only(['studio_enabled', 'studio_workflow', 'studio_recipe_version', 'studio_recipe']);

            $item->productionComponents()->updateOrCreate(
                ['stable_key' => $component->stable_key],
                [
                    'product_production_component_id' => $component->id,
                    'name' => $component->name,
                    'prompt_template' => $component->prompt_template,
                    'quantity_per_item' => $component->quantity_per_item,
                    'sort_order' => $component->sort_order,
                    ...$studioSnapshot,
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
