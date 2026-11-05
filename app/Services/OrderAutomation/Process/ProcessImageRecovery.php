<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\AutomationHandoff;
use Illuminate\Support\Facades\DB;

/** Resume only the unchanged, unsent preview wait after an automatic image repair. */
final class ProcessImageRecovery
{
    public function resume(PuzzleGeneration $image): bool
    {
        if ($image->status !== 'succeeded' || ! isset($image->context['automatic_recovery']) || $image->requested_by) {
            return false;
        }

        return DB::transaction(function () use ($image) {
            $key = $image->checkout_key;
            Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            $runs = OrderProcessRun::where('checkout_key', $key)->whereNotIn('status', ['cancelled', 'completed'])->lockForUpdate()->get();
            $run = $runs->first(fn ($run) => $run->status === 'blocked' && $run->error_code === 'preview_product_result_review');
            if ($runs->count() !== 1 || ! $run || ! AutomationControl::admits($key) || AutomationHandoff::held($key)
                || ! $run->automation->enabled || ! app(ProcessLivePolicy::class)->enabled()
                || OrderReviewManifest::where('checkout_key', $key)->exists()) {
                return false;
            }
            $node = app(ProcessRuntime::class)->node($run);
            if (($node['type'] ?? '') !== 'generate_previews' || ! $run->steps()->where('node_id', $run->node_id)->where('type', 'generate_previews')->where('status', 'waiting')->exists()
                || $run->steps()->whereHas('messageAttempts')->exists()) {
                return false;
            }
            try {
                app(ProcessRuntime::class)->guard($run);
            } catch (\Throwable) {
                return false;
            }
            $ids = $run->context['order_ids'];
            $items = OrderItem::whereIn('order_id', $ids)->whereIn('item_type', ['product', 'product_add_on'])->pluck('id');
            $latest = PuzzleGeneration::whereIn('order_item_id', $items)->orderBy('id')->get()->keyBy('order_item_id');
            if ($latest->count() !== $items->count() || $latest->contains(fn ($g) => ! in_array($g->status, ['queued', 'processing', 'succeeded'], true))) {
                return false;
            }
            $run->update(['status' => 'waiting_identity', 'error_code' => null, 'version' => $run->version + 1, 'next_at' => now()]);
            AutomationActivity::record($image->order, 'preview_automatically_recovered', 'استكمال تجهيز المعاينة بعد تعافي توليد الصورة؛ لا تغيير للموافقة أو الدفع.',
                ['run_id' => $run->id, 'generation_id' => $image->id]);

            return true;
        });
    }
}
