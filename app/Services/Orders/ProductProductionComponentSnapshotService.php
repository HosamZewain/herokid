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
            $item->productionComponents()->updateOrCreate(
                ['stable_key' => $component->stable_key],
                [
                    'product_production_component_id' => $component->id,
                    'name' => $component->name,
                    'prompt_template' => $component->prompt_template,
                    'quantity_per_item' => $component->quantity_per_item,
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
