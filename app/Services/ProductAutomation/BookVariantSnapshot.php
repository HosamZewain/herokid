<?php

namespace App\Services\ProductAutomation;

use App\Models\CodexSceneRun;
use App\Models\OrderItem;
use App\Models\PuzzleGeneration;
use App\Services\Orders\AutomationActivity;
use RuntimeException;

/** Repair only a missing historical page-count from the exact purchased variant. */
class BookVariantSnapshot
{
    public function prepare(OrderItem $item): bool
    {
        $item->loadMissing('productionComponents');
        if (! $item->productionComponents->contains('production_stage', 'book_cover') || ! $item->product_variant_id
            || data_get($item->variant_snapshot, 'book_page_count')) {
            return false;
        }
        $variant = $item->variant;
        $snapshot = $item->variant_snapshot ?? [];
        if (! $variant || $variant->product_id !== $item->product_id || (int) ($snapshot['id'] ?? 0) !== $variant->id
            || ($snapshot['name_ar'] ?? '') !== $variant->name_ar || ! $variant->book_page_count
            || preg_match('/\bA3\b/i', json_encode($snapshot))) {
            throw new RuntimeException('book_variant_requires_review');
        }
        if (PuzzleGeneration::where('order_item_id', $item->id)->exists()
            || CodexSceneRun::where('order_item_id', $item->id)->exists()
            || $item->order->previews()->exists() || $item->order->attachments()->exists()) {
            throw new RuntimeException('existing_book_output_or_attempt');
        }
        $item->variant_snapshot = array_merge($snapshot, ['book_page_count' => (int) $variant->book_page_count]);
        $item->setRelation('productionComponents', $item->productionComponents->map(function ($component) use ($variant) {
            $copy = clone $component;
            if ($copy->production_stage === 'book_interior') {
                $copy->quantity_per_item = $variant->book_page_count;
            }

            return $copy;
        }));

        return true;
    }

    public function persist(OrderItem $item): void
    {
        if (! $this->prepare($item)) {
            return;
        }
        $item->save();
        foreach ($item->productionComponents->where('production_stage', 'book_interior') as $component) {
            $component->save();
        }
        AutomationActivity::record($item->order, 'book_variant_snapshot_completed',
            'استكمال عدد صفحات الكتاب من نفس الاختيار الأصلي قبل أول توليد.', ['item_id' => $item->id, 'page_count' => $item->variant_snapshot['book_page_count']]);
    }
}
