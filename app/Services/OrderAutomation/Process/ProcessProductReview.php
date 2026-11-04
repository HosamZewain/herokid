<?php

namespace App\Services\OrderAutomation\Process;

use App\Exceptions\ProductPreviewInputException;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderPreviewStaffApproval;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\BookPromptSync;
use App\Services\Orders\PreviewProductReuse;
use App\Services\Orders\StaffPreviewInputs;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\ProductProductionPrompt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** One immutable manifest per send: every story identity and supported product preview. */
class ProcessProductReview
{
    private function items(OrderProcessRun $run)
    {
        return OrderItem::whereIn('order_id', $run->context['order_ids'])->whereIn('item_type', ['product', 'product_add_on'])
            ->with(['order', 'product.productionComponents', 'productionComponents'])->orderBy('id')->get();
    }

    public function prepare(OrderProcessRun $run, OrderProcessStep $step, array $config): void
    {
        if (! config('product_review.enabled') || ! app(PuzzleAutomation::class)->enabled()) {
            throw new ProductPreviewInputException(null, 'preview_disabled');
        }
        $items = $this->items($run);
        $captured = $items->mapWithKeys(fn ($item) => [$item->id => $this->capture($item)]);
        $productCost = $captured->sum('estimate_usd');
        if ($productCost > (float) $config['budget_usd']) {
            throw new ProductPreviewInputException(null, 'preview_budget_exceeded');
        }
        $config['budget_usd'] -= $productCost;
        foreach ($items as $item) {
            // Capture every product before scheduling an effect. Unsupported/missing inputs
            // roll back the whole prepare transaction, never silently omit part of a checkout.
            $context = $captured[$item->id];
            $existing = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($context['fingerprint'], $existing->context['fingerprint'] ?? '') && ! PreviewProductReuse::matches($run, $item, $existing, $context)) {
                    throw new ProductPreviewInputException($item->id, 'preview_product_changed');
                }
                if (! in_array($existing->status, ['queued', 'processing', 'succeeded'], true)) {
                    throw new ProductPreviewInputException($item->id, 'preview_product_result_review');
                }

                continue;
            }
            if ($item->order->attachments()->where(fn ($q) => $q->where('production_unit_key', $context['unit_key'])->orWhereNull('production_unit_key'))->exists()) {
                throw new ProductPreviewInputException($item->id, 'preview_existing_attachment');
            }
            PuzzleGeneration::create(['order_id' => $item->order_id, 'order_item_id' => $item->id, 'event_id' => $run->event_id,
                'checkout_key' => $run->checkout_key, 'unit_key' => $context['unit_key'], 'context' => $context, 'status' => 'queued']);
            AutomationActivity::record($item->order, 'preview_generation_enrolled', 'تسجيل معاينة المنتج ضمن المعاينة المجمعة.', ['run_id' => $run->id, 'item_id' => $item->id, 'unit_key' => $context['unit_key']]);
        }
        if (app(ProcessOrderContext::class)->forRun($run)->contains(fn ($o) => (bool) $o->story_id)) {
            app(ProcessIdentityService::class)->prepare($run, $step, $config);
        } else {
            $run->update(['status' => 'waiting_identity', 'next_at' => now()->addMinute()]);
            $step->update(['status' => 'waiting', 'started_at' => now()]);
        }
    }

    public function ready(OrderProcessRun $run, ?array $staffInputs = null): bool
    {
        $savedManifest = OrderReviewManifest::where('run_id', $run->id)->first();
        $staffInputs ??= $savedManifest ? app(StaffPreviewInputs::class)->recorded($savedManifest) : [];
        $stories = app(ProcessOrderContext::class)->forRun($run)->whereNotNull('story_id');
        if ($stories->isNotEmpty() && ! app(ProcessIdentityService::class)->ready($run)) {
            return false;
        }
        $images = [];
        foreach (OrderProcessIdentity::with(['attempt', 'order.story'])->where('run_id', $run->id)->orderBy('order_id')->get() as $task) {
            $images[] = ['kind' => 'identity', 'order_id' => $task->order_id, 'attempt_id' => $task->attempt_id,
                'disk' => $task->attempt->output_disk, 'path' => $task->attempt->output_storage_path, 'sha256' => $task->attempt->output_checksum,
                'label' => implode(' — ', array_filter([$task->order->child_name, $task->order->story?->title]))];
        }
        foreach ($this->items($run) as $item) {
            $generation = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first();
            if (! $generation || in_array($generation->status, ['queued', 'processing'], true)) {
                if ($run->created_at->lt(now()->subHours(6))) {
                    throw new ProductPreviewInputException($item->id, 'preview_worker_delayed');
                }

                return false;
            }
            if ($generation->status !== 'succeeded') {
                throw new ProductPreviewInputException($item->id, 'preview_product_result_review');
            }
            $context = $this->capture($item);
            $retained = PreviewProductReuse::matches($run, $item, $generation, $context);
            $retainedFingerprint = $retained ? $context['fingerprint'] : null;
            if (! hash_equals($context['fingerprint'], $generation->context['fingerprint'] ?? '')) {
                if (! $retained && ! hash_equals($context['fingerprint'], $staffInputs[(string) $item->id]['input_fingerprint'] ?? '')) {
                    throw new ProductPreviewInputException($item->id, 'preview_product_changed');
                }
                // The approval attests to this saved artwork under the current brief;
                // keep the original generation provenance in the immutable manifest.
                $context['fingerprint'] = $generation->context['fingerprint'];
            }
            $preview = OrderPreview::find($generation->preview_id);
            if (! $preview || $preview->order_id !== $item->order_id || $preview->production_unit_key !== $context['unit_key'] || $preview->checksum !== $generation->checksum || $preview->productGallery?->status !== 'active') {
                throw new ProductPreviewInputException($item->id, 'preview_image_unavailable');
            }
            $images[] = ['kind' => ($context['production_stage'] ?? 'product'), 'order_id' => $item->order_id, 'item_id' => $item->id,
                'generation_id' => $generation->id, 'preview_id' => $preview->id, 'unit_key' => $context['unit_key'],
                'input_fingerprint' => $context['fingerprint'], 'disk' => $preview->disk, 'path' => $preview->file_path, 'sha256' => $preview->checksum,
                'interior_fingerprint' => $this->interiorFingerprint($item), 'label' => $item->title.' — '.$item->order->child_name]
                + ($retained ? ['retained_input_fingerprint' => $retainedFingerprint] : []);
        }
        if (! $images) {
            throw new RuntimeException('preview_requires_review');
        }
        foreach ($images as $image) {
            $this->bytes($image);
        }
        $existingManifest = OrderReviewManifest::where('run_id', $run->id)->first();
        if ($existingManifest) {
            foreach ($images as &$image) {
                if (($image['kind'] ?? '') !== 'book_cover') {
                    continue;
                }
                $old = collect($existingManifest->images)->firstWhere('item_id', $image['item_id']);
                if ($old && (app(BookPromptSync::class)->matchesInterior(OrderItem::findOrFail($image['item_id']), $old['interior_fingerprint'] ?? '')
                    || app(BookPromptSync::class)->matchesInterior(OrderItem::findOrFail($image['item_id']), $staffInputs[(string) $image['item_id']]['interior_fingerprint'] ?? ''))) {
                    $image['interior_fingerprint'] = $old['interior_fingerprint'];
                }
            }
            unset($image);
        }
        $fingerprint = hash('sha256', json_encode($images, JSON_THROW_ON_ERROR));
        $manifest = OrderReviewManifest::firstOrCreate(['run_id' => $run->id], ['checkout_key' => $run->checkout_key, 'images' => $images, 'fingerprint' => $fingerprint]);
        if (! hash_equals($manifest->fingerprint, $fingerprint)) {
            throw new RuntimeException('preview_requires_review');
        }

        return true;
    }

    private function capture(OrderItem $item): array
    {
        try {
            return app(PuzzleInputs::class)->captureCurrent($item);
        } catch (\Throwable $error) {
            $code = array_key_exists($error->getMessage(), PuzzleGeneration::ERRORS) ? $error->getMessage() : 'preview_requires_review';
            throw new ProductPreviewInputException($item->id, $code);
        }
    }

    public function interiorFingerprint(OrderItem $item): ?string
    {
        $unit = ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_interior');

        return $unit ? hash('sha256', json_encode([$unit['unit_key'], $unit['prompt'], $unit['quantity_per_item'], $item->quantity], JSON_THROW_ON_ERROR)) : null;
    }

    public function bytes(array $image): string
    {
        try {
            $bytes = Storage::disk($image['disk'])->get($image['path']);
        } catch (\Throwable) {
            throw new RuntimeException('preview_requires_review');
        }
        if (! is_string($bytes) || ! hash_equals($image['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('preview_requires_review');
        }

        return $bytes;
    }

    public function url(OrderProcessRun $run): string
    {
        if (! $this->ready($run)) {
            throw new RuntimeException('preview_requires_review');
        }

        return URL::temporarySignedRoute('order-review.show', now()->addDays(35), ['manifest' => OrderReviewManifest::where('run_id', $run->id)->firstOrFail()->id]);
    }

    public function approve(OrderProcessRun $run, array $config, ?OrderPreviewStaffApproval $staffApproval = null): void
    {
        if (! $this->ready($run)) {
            throw new RuntimeException('preview_requires_review');
        }
        $step = $run->steps()->where('node_id', $config['response_node'])->firstOrFail();
        $decision = DB::table('order_process_responses')->where('run_id', $run->id)->where('step_id', $step->id)->first();
        $wait = collect($run->revision->definition['nodes'])->firstWhere('id', $config['response_node']);
        $case = collect($wait['config']['cases'])->firstWhere('text', 'تأكيد المعاينة');
        if ($staffApproval ? ! $staffApproval->matches($run) : (! $decision || ! $case || $case['key'] !== $decision->decision)) {
            throw new RuntimeException('preview_requires_review');
        }
        if (OrderProcessIdentity::where('run_id', $run->id)->exists()) {
            app(ProcessIdentityService::class)->approve($run, $config, 'تأكيد المعاينة', $staffApproval);
        }
        $manifest = OrderReviewManifest::where('run_id', $run->id)->lockForUpdate()->firstOrFail();
        if (! $manifest->approved_at) {
            $manifest->update(['approved_at' => now(), 'response_message_id' => $staffApproval ? null : $decision->message_id]);
            // A product approval is not a printing or payment approval. Hand it to
            // staff; approved, paid books can enter their existing interior gate.
            $contexts = app(ProcessOrderContext::class);
            foreach ($contexts->forRun($run, true)->whereNull('story_id') as $order) {
                if (! in_array($order->status, $staffApproval ? ['new_confirmed', 'preview_uploaded', 'delivered', 'pending_team_review'] : ['new_confirmed', 'preview_uploaded'], true)) {
                    continue;
                }
                $from = $order->status;
                $order->update(['status' => 'pending_team_review']);
                $order->statusLogs()->create(['status_type' => 'order', 'status' => 'pending_team_review', 'notes' => 'Automation: اعتماد المعاينة؛ مراجعة الدفع واستكمال الطلب بواسطة الفريق.']);
                AutomationActivity::record($order, 'product_preview_approved', 'اعتماد معاينة المنتج وتسليم الطلب لمراجعة الفريق دون اعتماد الدفع.',
                    ['run_id' => $run->id, 'manifest_id' => $manifest->id, 'from' => $from, 'to' => 'pending_team_review']);
            }
            $run->context = $contexts->snapshot($contexts->forRun($run, true)) + app(ProcessOrderContext::class)->metadata($run);
            AutomationActivity::record($run->checkout_key, 'previews_approved', $staffApproval ? 'اعتماد النسخة الحالية بناءً على موافقة العميل المسجلة بواسطة الفريق؛ لم يسجل دفع.' : 'اعتماد صور المعاينة المحددة فقط بناءً على رد العميل؛ لم يسجل دفع.', ['manifest_id' => $manifest->id, 'image_count' => count($manifest->images), 'source_id' => $staffApproval ? null : $decision->message_id, 'staff_approval_id' => $staffApproval?->id, 'requested_by' => $staffApproval?->user_id]);
        }
    }
}
