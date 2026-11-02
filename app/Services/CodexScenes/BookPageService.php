<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\ProductProductionPrompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Book interiors share the bounded Codex pool, but never a story's identity or unit key. */
class BookPageService
{
    public function workerReady(): bool
    {
        if (! app(ManualSceneService::class)->health()) {
            return false;
        }
        $health = json_decode(file_get_contents(config('codex_scenes.spool').'/outbox/health.json'), true);

        return in_array(5, $health['supported_protocols'] ?? [], true);
    }

    private function approvedInputs(OrderItem $item, OrderReviewManifest $manifest): array
    {
        $item->loadMissing('order', 'productionComponents', 'product.productionComponents');
        if (! $manifest->approved_at || $manifest->checkout_key !== $item->order->checkoutGroupKey()
            || OrderReviewManifest::where('checkout_key', $manifest->checkout_key)->max('id') !== $manifest->id
            || ! in_array($manifest->run?->status, ['running', 'waiting_staff', 'completed'], true)) {
            throw new RuntimeException('Book approval unavailable');
        }
        $image = collect($manifest->images)->first(fn ($image) => ($image['kind'] ?? '') === 'book_cover' && ($image['item_id'] ?? 0) === $item->id);
        $interior = ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_interior');
        $generation = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first();
        if (! $image || ! $interior || ! $generation || $generation->id !== $image['generation_id'] || $generation->status !== 'succeeded'
            || ! hash_equals($image['input_fingerprint'], app(PuzzleInputs::class)->capture($item)['fingerprint'])
            || ! hash_equals($image['interior_fingerprint'] ?? '', app(ProcessProductReview::class)->interiorFingerprint($item) ?? '')
            || ! $generation->preview_id || $generation->preview_id !== $image['preview_id']) {
            throw new RuntimeException('Book inputs changed after approval');
        }
        $preview = OrderPreview::find($generation->preview_id);
        if (! $preview || $preview->productGallery?->status !== 'active' || $preview->file_path !== $image['path'] || $preview->checksum !== $image['sha256']) {
            throw new RuntimeException('Book preview revoked');
        }
        app(ProcessProductReview::class)->bytes($image);
        $count = (int) $interior['quantity_per_item'];
        if ($count < 1 || $count > config('product_review.max_pages', 1000) || trim($interior['prompt']) === '' || preg_match('/\{\{|\[MISSING/', $interior['prompt'])) {
            throw new RuntimeException('Book pages incomplete');
        }

        return ['manifest_id' => $manifest->id, 'item_id' => $item->id, 'unit_key' => $interior['unit_key'], 'page_count' => $count,
            'input_fingerprint' => $image['input_fingerprint'], 'interior_fingerprint' => $image['interior_fingerprint'],
            'prompt' => $interior['prompt'], 'identity_disk' => $image['disk'], 'identity_path' => $image['path'], 'identity_checksum' => $image['sha256']];
    }

    public function fingerprint(CodexSceneRun $run): string
    {
        $item = OrderItem::where('order_id', $run->order_id)->findOrFail($run->order_item_id);
        $manifest = OrderReviewManifest::findOrFail($run->snapshot['manifest_id']);

        return hash('sha256', json_encode($this->approvedInputs($item, $manifest), JSON_THROW_ON_ERROR));
    }

    public function start(OrderItem $item, OrderReviewManifest $manifest): CodexSceneRun
    {
        if (! config('product_review.enabled') || ! config('product_review.book_pages_enabled') || ! $this->workerReady()) {
            throw new RuntimeException('Book production disabled');
        }

        return Cache::lock('codex-scenes-start', 30)->block(3, function () use ($item, $manifest) {
            return DB::transaction(function () use ($item, $manifest) {
                $rows = Order::where('checkout_group_key', $item->order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
                $order = $rows->firstWhere('id', $item->order_id);
                $item = OrderItem::lockForUpdate()->findOrFail($item->id);
                if (! $order || AutomationHandoff::held($order->checkoutGroupKey()) || app(AutomationAssignment::class)->owns($order, false)
                    || ! in_array($order->status, ['new_confirmed', 'costomer_confirmed', 'ready_preview'], true)
                    || ! $rows->contains(fn ($row) => $row->paid_amount_cents > 0 && in_array($row->payment_status, ['partially_paid', 'paid_in_full', 'paid_without_shipping'], true))
                    || CodexSceneRun::where('order_id', $order->id)->where('active_slot', 1)->exists()
                    || CodexSceneRun::where('production_kind', 'coloring_book')->where('order_item_id', $item->id)->exists()
                    || ($order->story_id && ! CodexSceneRun::where('order_id', $order->id)->where('production_kind', 'story')->where('status', 'completed')->exists())) {
                    throw new RuntimeException('Book not eligible');
                }
                $inputs = $this->approvedInputs($item, $manifest->fresh());
                $run = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'checkout_key' => $order->checkoutGroupKey(),
                    'order_item_id' => $item->id, 'production_kind' => 'coloring_book', 'protocol' => 5, 'scene_count' => $inputs['page_count'],
                    'source' => 'automatic', 'status' => 'waiting_slot', 'active_slot' => 1, 'previous_status' => $order->status, 'scenes' => [],
                    'fingerprint' => hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR)),
                    'snapshot' => $inputs + ['texts' => array_map(fn ($n) => ['scene_number' => $n, 'title' => 'صفحة '.$n,
                        'text' => 'Create interior coloring page '.$n.' of '.$inputs['page_count'].' according to the production brief. Preserve the approved child and keep every page distinct.'], range(1, $inputs['page_count']))]]);
                $before = $order->status;
                $order->update(['status' => 'generating']);
                $order->statusLogs()->create(['status' => 'generating', 'notes' => 'توليد صفحات كتاب التلوين بعد اعتماد الكفر وتسجيل دفعة.']);
                AutomationActivity::record($order, 'coloring_book_enrolled', 'بدء صفحات كتاب التلوين؛ الدفعة مسجلة يدويًا والكفر معتمد.',
                    ['run_id' => $run->id, 'item_id' => $item->id, 'unit_key' => $inputs['unit_key'], 'page_count' => $inputs['page_count'], 'from' => $before, 'to' => 'generating']);

                return $run;
            });
        });
    }

    public function enroll(): void
    {
        if (! config('product_review.enabled') || ! config('product_review.book_pages_enabled') || ! $this->workerReady()) {
            return;
        }
        $room = max(0, 50 - CodexSceneRun::where('active_slot', 1)->count());
        if (! $room) {
            return;
        }
        $query = OrderItem::whereHas('productionComponents', fn ($q) => $q->where('production_stage', 'book_interior'))
            ->whereNotIn('id', CodexSceneRun::where('production_kind', 'coloring_book')->whereNotNull('order_item_id')->select('order_item_id'))->orderBy('id');
        $cursor = (int) Cache::get('coloring-book-enrollment-cursor', 0);
        $batch = (clone $query)->where('id', '>', $cursor)->limit(min(25, $room))->get();
        if ($batch->isEmpty()) {
            $batch = $query->limit(min(25, $room))->get();
        }
        foreach ($batch as $item) {
            $manifest = OrderReviewManifest::where('checkout_key', $item->order->checkoutGroupKey())->latest('id')->first();
            try {
                if ($manifest?->approved_at) {
                    $this->start($item, $manifest);
                }
            } catch (RuntimeException $error) { /* Eligibility only; no request and no historical retry. */
            }
            Cache::put('coloring-book-enrollment-cursor', $item->id, now()->addDay());
        }
    }
}
