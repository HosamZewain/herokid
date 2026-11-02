<?php

namespace App\Services\ProductAutomation;

use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\ProductProductionComponentSnapshotService;
use App\Support\ProductProductionStage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit catalog setup and bounded legacy conversion; never runs in a seeder/migration. */
class BookCatalog
{
    public function configure(Product $product, int $defaultPages, array $variantPages): void
    {
        DB::transaction(function () use ($product, $defaultPages, $variantPages) {
            $product = Product::lockForUpdate()->findOrFail($product->id);
            if ($defaultPages < 1 || $defaultPages > 1000 || ! str_contains($product->name_ar, 'تلوين')) {
                throw new RuntimeException('book_catalog_invalid');
            }
            $existing = $product->productionComponents()->whereIn('production_stage', ['book_cover', 'book_interior'])->get();
            if ($existing->isNotEmpty()) {
                throw new RuntimeException('book_catalog_already_configured');
            }
            foreach ($variantPages as $id => $count) {
                $variant = $product->variants()->lockForUpdate()->findOrFail($id);
                if ($count < 1 || $count > 1000 || preg_match('/\bA3\b/i', $variant->name_ar.' '.$variant->name_en)) {
                    throw new RuntimeException('book_variant_invalid');
                }
                $variant->update(['book_page_count' => $count]);
            }
            // Preserve all old creative briefs for history; never repurpose stable keys.
            $product->productionComponents()->update(['is_active' => false]);
            foreach ([['automation-cover-v1', 'كفر كتاب التلوين A4', 'book_cover', 1, ProductProductionStage::coverPrompt()],
                ['automation-pages-v1', 'صفحات كتاب التلوين A4', 'book_interior', $defaultPages, ProductProductionStage::interiorPrompt()]] as $i => [$key,$name,$stage,$count,$prompt]) {
                $product->productionComponents()->create(['stable_key' => $key, 'name' => $name, 'production_stage' => $stage,
                    'quantity_per_item' => $count, 'prompt_template' => $prompt, 'sort_order' => $i, 'is_active' => true]);
            }
        });
    }

    public function upgrade(OrderItem $item): void
    {
        if ($item->productionComponents->contains('production_stage', 'book_cover')) {
            return;
        }
        $product = $item->product;
        if (! $product?->productionComponents->contains('production_stage', 'book_cover')) {
            return;
        }
        $variant = $item->product_variant_id ? $product->variants()->find($item->product_variant_id) : null;
        if (($item->product_variant_id && (! $variant || ! $variant->book_page_count))
            || preg_match('/\bA3\b/i', json_encode($item->variant_snapshot ?? []))) {
            throw new RuntimeException('book_variant_requires_review');
        }
        foreach ($item->productionComponents as $component) {
            $original = $product->productionComponents->firstWhere('stable_key', $component->stable_key);
            if (! $original || $component->production_stage !== 'standard' || $component->prompt_template !== $original->prompt_template) {
                throw new RuntimeException('book_manual_brief_requires_review');
            }
        }
        // Caller holds checkout/item locks and rejects any attempted work or existing output.
        $ids = $item->productionComponents->pluck('id')->all();
        $item->productionComponents()->update(['retired_at' => now()]);
        if ($variant) {
            $item->update(['variant_snapshot' => ($item->variant_snapshot ?? []) + ['book_page_count' => $variant->book_page_count]]);
            // Null in the old snapshot is not a selected count.
            $snapshot = $item->variant_snapshot;
            $snapshot['book_page_count'] = $variant->book_page_count;
            $item->update(['variant_snapshot' => $snapshot]);
        }
        $item->unsetRelation('productionComponents');
        app(ProductProductionComponentSnapshotService::class)->captureForItem($item);
        $item->unsetRelation('productionComponents');
        AutomationActivity::record($item->order, 'book_configuration_upgraded', 'فصل الكفر والصفحات مع حفظ برومبتات الطلب القديمة دون حذفها.',
            ['item_id' => $item->id, 'retired_component_ids' => $ids]);
    }
}
