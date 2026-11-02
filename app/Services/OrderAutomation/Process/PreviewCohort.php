<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderItemProductionComponent;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessRun;
use App\Models\PuzzleGeneration;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationHandoff;
use App\Services\ProductAutomation\BookCatalog;
use App\Services\ProductAutomation\PuzzleInputs;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** New, explicitly authorized cohort, not historical event/job replay. */
class PreviewCohort
{
    public function inspect(string $key, OrderAutomation $flow, bool $lock = false): array
    {
        $orders = app(ProcessOrderContext::class)->orders($key, $lock);
        if (AutomationHandoff::held($key) || OrderProcessRun::where('checkout_key', $key)->exists()) {
            throw new RuntimeException('existing_workflow_or_manual_hold');
        }
        if ($orders->contains(fn ($o) => $o->status !== 'new_confirmed'
            || ! in_array($o->printing_status, ['not_started', 'ready', 'on_hold', 'not_required'], true)
            || ! in_array($o->shipping_status, ['not_ready', 'ready', 'not_required'], true))) {
            throw new RuntimeException('order_stage_requires_review');
        }
        $snapshot = app(ProcessOrderContext::class)->snapshot($orders);
        if (! app(ProcessOrderContext::class)->matches($flow->revision->definition, $snapshot)) {
            throw new RuntimeException('conditions_not_matched');
        }
        foreach ($orders as $order) {
            if ($order->story_id && ($order->child_identity_request_id || $order->child_identity_approved_attempt_id || $order->attachments()->exists())) {
                throw new RuntimeException('existing_identity_or_output');
            }
            if ($order->story_id) {
                app(WorkflowInputs::class)->capture($order, ['story_ids' => [], 'photo_limit' => 3, 'prompt' => '{{identity_prompt}}']);
            }
            foreach ($order->items->whereIn('item_type', ['product', 'product_add_on']) as $item) {
                $item->loadMissing('productionComponents', 'product.productionComponents');
                if (! $item->productionComponents->contains('production_stage', 'book_cover') && $item->product?->productionComponents->contains('production_stage', 'book_cover')) {
                    if (PuzzleGeneration::where('order_item_id', $item->id)->exists() || $order->attachments()->exists() || $order->previews()->exists()) {
                        throw new RuntimeException('existing_book_output_or_attempt');
                    }
                    foreach ($item->productionComponents as $component) {
                        $original = $item->product->productionComponents->firstWhere('stable_key', $component->stable_key);
                        if (! $original || $component->prompt_template !== $original->prompt_template) {
                            throw new RuntimeException('book_manual_brief_requires_review');
                        }
                    }
                    $variant = $item->product_variant_id ? $item->product->variants()->find($item->product_variant_id) : null;
                    if ($item->product_variant_id && ! $variant?->book_page_count) {
                        throw new RuntimeException('book_variant_requires_review');
                    }
                    $variantSnapshot = $item->variant_snapshot ?? [];
                    if ($variant) {
                        $variantSnapshot['book_page_count'] = $variant->book_page_count;
                    }
                    $item->variant_snapshot = $variantSnapshot;
                    $item->setRelation('productionComponents', $item->product->productionComponents->where('is_active', true)->values()
                        ->map(fn ($c) => new OrderItemProductionComponent(['stable_key' => $c->stable_key, 'name' => $c->name, 'production_stage' => $c->production_stage,
                            'prompt_template' => $c->prompt_template, 'quantity_per_item' => $c->production_stage === 'book_interior' ? ($variant?->book_page_count ?: $c->quantity_per_item) : 1])));
                }
                $input = app(PuzzleInputs::class)->capture($item);
                $previous = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first();
                if ($previous && ($previous->status !== 'succeeded' || ! hash_equals($input['fingerprint'], $previous->context['fingerprint'] ?? ''))) {
                    throw new RuntimeException('existing_product_attempt_requires_review');
                }
            }
        }

        return $snapshot;
    }

    public function enroll(string $key, OrderAutomation $flow): OrderProcessRun
    {
        return DB::transaction(function () use ($key, $flow) {
            $flow = OrderAutomation::lockForUpdate()->findOrFail($flow->id);
            if (! $flow->enabled || ! $flow->accepts_new_runs || ! app(ProcessLivePolicy::class)->authorized($flow)) {
                throw new RuntimeException('workflow_unavailable');
            }
            app(ProcessLivePolicy::class)->check($flow->revision->definition);
            $snapshot = $this->inspect($key, $flow, true);
            foreach (Order::whereIn('id', $snapshot['order_ids'])->with('items')->get() as $order) {
                foreach ($order->items->whereIn('item_type', ['product', 'product_add_on']) as $item) {
                    app(BookCatalog::class)->upgrade($item);
                }
            }
            $snapshot = app(ProcessOrderContext::class)->snapshot(app(ProcessOrderContext::class)->orders($key, true));
            $event = OrderProcessEvent::create(['event_key' => 'preview-cohort:'.$flow->id.':'.$key, 'checkout_key' => $key,
                'order_id' => $snapshot['order_ids'][0], 'type' => 'preview_cohort_enrolled', 'status' => 'processed', 'data' => ['status' => 'new_confirmed']]);
            $run = OrderProcessRun::create(['enrollment_key' => hash('sha256', $flow->id.':'.$key), 'automation_id' => $flow->id,
                'revision_id' => $flow->revision_id, 'event_id' => $event->id, 'order_id' => $snapshot['order_ids'][0], 'checkout_key' => $key,
                'account_key' => config('robodesk.conversations.account_key', 'primary'), 'phone_hash' => hash('sha256', '+'.$snapshot['phone']),
                'node_id' => $flow->revision->definition['nodes'][0]['id'], 'status' => 'queued',
                'context' => $snapshot + ['actor_id' => $flow->process_activated_by], 'next_at' => now()]);
            AutomationActivity::record($key, 'combined_preview_enrolled', 'إدراج الطلب المؤكد المؤهل في المعاينة المجمعة دون إعادة تأكيد الطلب أو إعادة محاولة قديمة.', ['run_id' => $run->id]);

            return $run;
        });
    }
}
