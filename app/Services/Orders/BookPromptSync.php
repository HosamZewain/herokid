<?php

namespace App\Services\Orders;

use App\Models\BookPromptRevision;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PuzzleGeneration;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use Illuminate\Support\Facades\DB;

/** Update unstarted book briefs; sent artwork manifests and accepted runs stay immutable. */
class BookPromptSync
{
    public function sync(Product $product, ?int $actorId = null): array
    {
        $result = ['updated' => 0, 'frozen' => 0];
        $live = $product->productionComponents()->where('is_active', true)->whereIn('production_stage', ['book_cover', 'book_interior'])->get()->keyBy('stable_key');
        OrderItem::where('product_id', $product->id)->whereHas('order')->whereIn('item_type', ['product', 'product_add_on'])
            ->whereHas('productionComponents', fn ($q) => $q->whereIn('production_stage', ['book_cover', 'book_interior']))
            ->orderBy('id')->chunkById(100, function ($items) use ($live, $actorId, &$result) {
                foreach ($items as $item) {
                    DB::transaction(function () use ($item, $live, $actorId, &$result) {
                        // Same checkout -> item lock order as book admission prevents dispatch races.
                        Order::where('checkout_group_key', $item->order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
                        $item = OrderItem::lockForUpdate()->findOrFail($item->id);
                        $item->load('productionComponents', 'order');
                        foreach ($item->productionComponents as $part) {
                            $sameStage = $live->where('production_stage', $part->production_stage);
                            $source = $live->get($part->stable_key) ?? ($sameStage->count() === 1 ? $sameStage->first() : null);
                            if (! $source || $source->production_stage !== $part->production_stage || blank($source->prompt_template)
                                || $source->prompt_template === $part->prompt_template) {
                                continue;
                            }
                            $frozen = CodexSceneRun::where('order_item_id', $item->id)->where('production_kind', 'coloring_book')->exists();
                            if ($part->production_stage === 'book_cover') {
                                $frozen = $frozen || PuzzleGeneration::where('order_item_id', $item->id)->exists();
                            }
                            if ($frozen || $item->order->trashed() || $item->order->printing_status !== 'not_started' || $item->order->shipping_status !== 'not_ready'
                                || in_array($item->order->status, ['cancelled', 'completed'], true)) {
                                $result['frozen']++;

                                continue;
                            }
                            $before = $part->production_stage === 'book_interior' ? app(ProcessProductReview::class)->interiorFingerprint($item) : hash('sha256', $part->prompt_template);
                            $previous = $part->prompt_template;
                            $part->update(['prompt_template' => $source->prompt_template, 'product_production_component_id' => $source->id]);
                            $item->unsetRelation('productionComponents');
                            $after = $part->production_stage === 'book_interior' ? app(ProcessProductReview::class)->interiorFingerprint($item) : hash('sha256', $source->prompt_template);
                            $revision = BookPromptRevision::create(['order_item_id' => $item->id, 'component_id' => $part->id,
                                'user_id' => $actorId, 'stage' => $part->production_stage, 'before_fingerprint' => $before, 'after_fingerprint' => $after,
                                'previous_template' => $previous, 'new_template' => $source->prompt_template]);
                            AutomationActivity::record($item->order, 'book_prompt_synced', 'تحديث برومبت '.($part->production_stage === 'book_interior' ? 'صفحات' : 'كفر').' كتاب التلوين من قالب المنتج الحالي قبل بدء التوليد.',
                                ['item_id' => $item->id, 'revision_id' => $revision->id, 'catalog_updated_by' => $actorId, 'before_fingerprint' => $before, 'after_fingerprint' => $after]);
                            $result['updated']++;
                        }
                    }, 3);
                }
            });

        return $result;
    }

    public function matchesInterior(OrderItem $item, string $expected): bool
    {
        $current = app(ProcessProductReview::class)->interiorFingerprint($item);
        if (! $current || $expected === '') {
            return false;
        }
        if (hash_equals($expected, $current)) {
            return true;
        }
        // Only catalog-template revisions qualify. Child/page-count/manual changes
        // break this exact rendered-fingerprint chain and require a fresh review.
        foreach (BookPromptRevision::where('order_item_id', $item->id)->where('stage', 'book_interior')->orderBy('id')->get() as $revision) {
            if (hash_equals($expected, $revision->before_fingerprint)) {
                $expected = $revision->after_fingerprint;
            }
        }

        return hash_equals($expected, $current);
    }
}
