<?php

namespace App\Services\Orders;

use App\Models\OrderItemProductionComponent;
use App\Models\Product;

class ProductProductionPromptSyncService
{
    /**
     * Keep the prompt fallback stored on existing order components aligned
     * with the current product component. Order-specific names, quantities,
     * ordering, attachments, prices, and statuses are intentionally untouched.
     */
    public function syncForProduct(Product $product): int
    {
        $product->load('productionComponents');
        $updated = 0;

        foreach ($product->productionComponents as $component) {
            $updated += OrderItemProductionComponent::query()
                ->where('stable_key', $component->stable_key)
                ->whereHas('orderItem', fn ($query) => $query
                    ->where('product_id', $product->id)
                    ->whereIn('item_type', ['product', 'product_add_on']))
                ->where(function ($query) use ($component): void {
                    $query->where('product_production_component_id', '!=', $component->id)
                        ->orWhereNull('product_production_component_id')
                        ->orWhere('prompt_template', '!=', $component->prompt_template);
                })
                ->update([
                    'product_production_component_id' => $component->id,
                    'prompt_template' => $component->prompt_template,
                    'updated_at' => now(),
                ]);
        }

        return $updated;
    }
}
