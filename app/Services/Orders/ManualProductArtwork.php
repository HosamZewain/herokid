<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAutomationRun;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Explicit artwork replacement retires a settled failure, never invents AI success or consent. */
final class ManualProductArtwork
{
    public function authorize(Order $order, User $actor): void
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor) && $actor->is_active && ! $actor->agent_api_enabled
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
    }

    private function plan(Order $order): array
    {
        $key = $order->checkoutGroupKey();
        $control = DB::table('order_automation_controls')->where('checkout_key', $key)->first();
        $runs = OrderProcessRun::where('checkout_key', $key)->whereNotIn('status', ['completed', 'cancelled'])->get();
        $run = $runs->first();
        $deny = fn () => throw ValidationException::withMessages(['manual_artwork' => 'أوقف الأوتوميشن بأمان أولًا. الربط متاح لمحاولة انتهت قبل إرسال المعاينة، دون توليد أو إرسال جارٍ.']);
        if ($control?->state !== 'paused' || $runs->count() !== 1 || $run?->status !== 'paused'
            || ! AutomationHandoff::held($key) || OrderReviewManifest::where('checkout_key', $key)->exists()
            || CodexSceneRun::where('checkout_key', $key)->exists()
            || OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', ['queued', 'processing', 'uncertain'])->exists()
            || RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->whereIn('status', ['processing', 'unknown'])->exists()
            || OrderAutomationRun::whereIn('order_id', Order::where('checkout_group_key', $key)->select('id'))->where('dry_run', false)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()
            || ($run->revision->definition['nodes'][0]['type'] ?? '') !== 'generate_previews'
            || ($run->revision->definition['nodes'][0]['id'] ?? '') !== $run->node_id
            || $run->steps()->whereHas('messageAttempts')->exists()
            || $run->steps()->whereIn('status', ['processing', 'uncertain', 'retry_pending'])->exists()
            || $run->identities()->where('status', '!=', 'succeeded')->exists()) {
            $deny();
        }
        $rows = app(ProcessOrderContext::class)->forRun($run);
        // A replacement may not silently rebase a changed customer/order identity.
        $snapshot = app(ProcessOrderContext::class)->snapshot($rows, (int) ($run->context['fingerprint_version'] ?? 1));
        if (! hash_equals($run->context['fingerprint'], $snapshot['fingerprint'])
            || $rows->contains(fn ($o) => (bool) $o->story_id)
            || $rows->contains(fn ($o) => $o->printing_status !== 'not_started' || $o->shipping_status !== 'not_ready'
                || ! in_array($o->status, ['new_confirmed', 'costomer_confirmed'], true))) {
            $deny();
        }
        $latest = PuzzleGeneration::where('checkout_key', $key)->orderBy('id')->get()->keyBy('order_item_id');
        $failed = $latest->reject(fn ($g) => $g->status === 'succeeded');
        if ($failed->isEmpty() || $failed->contains(fn ($g) => ! in_array($g->status, ['uncertain', 'blocked'], true)
            || ! $g->finished_at || $g->lease_until || $g->output_path || $g->attachment_id || $g->preview_id)) {
            $deny();
        }

        return ['run' => $run, 'rows' => $rows, 'failed' => $failed, 'control_version' => $control->version];
    }

    private function preview(OrderItem $item, int $id): OrderPreview
    {
        $preview = OrderPreview::with(['order', 'productGallery'])->findOrFail($id);
        $unit = app(PuzzleInputs::class)->captureCurrent($item)['unit_key'];
        if (! $preview->order || $preview->order->trashed() || $preview->order->checkoutGroupKey() !== $item->order->checkoutGroupKey()
            || $preview->productGallery?->status !== 'active'
            || $preview->productGallery->checkout_group_key !== $item->order->checkoutGroupKey()
            || ($preview->production_unit_key && $preview->production_unit_key !== $unit)
            || ! in_array($preview->mime_type, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new RuntimeException('manual_artwork_unavailable');
        }
        $bytes = Storage::disk($preview->disk)->get($preview->file_path);
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)
            || strlen($bytes) > 25 * 1024 * 1024 || $info[0] * $info[1] > 25000000
            || ($preview->checksum && ! hash_equals($preview->checksum, hash('sha256', $bytes)))) {
            throw new RuntimeException('manual_artwork_unavailable');
        }
        $preview->checksum = hash('sha256', $bytes);

        return $preview;
    }

    public function offers(Order $order): array
    {
        $plan = $this->plan($order);
        $previews = OrderPreview::whereIn('order_id', $plan['rows']->pluck('id'))->whereHas('productGallery', fn ($q) => $q->where('status', 'active'))->get();
        $offers = [];
        foreach ($plan['failed'] as $generation) {
            $item = $generation->item;
            $inputs = app(PuzzleInputs::class)->captureCurrent($item);
            $choices = [];
            foreach ($previews as $preview) {
                if ($preview->production_unit_key && $preview->production_unit_key !== $inputs['unit_key']) {
                    continue;
                }
                // Bytes are verified at selection/POST; GET lists only scoped images.
                $choices[] = ['preview' => $preview, 'token' => Crypt::encryptString(json_encode([
                    'item' => $item->id, 'generation' => $generation->id, 'preview' => $preview->id,
                    'preview_updated' => $preview->updated_at->toISOString(), 'input' => $inputs['fingerprint'],
                    'run' => $plan['run']->id, 'run_version' => $plan['run']->version, 'control_version' => $plan['control_version'],
                ], JSON_THROW_ON_ERROR))];
            }
            $offers[] = compact('item', 'choices');
        }

        return $offers;
    }

    public function adopt(Order $order, User $actor, Request $request, array $choices): void
    {
        $this->authorize($order, $actor);
        DB::transaction(function () use ($order, $actor, $request, $choices) {
            $key = $order->checkoutGroupKey();
            DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            PuzzleGeneration::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            $plan = $this->plan($order);
            abort_unless(count($choices) === $plan['failed']->count(), 422, 'اختر صورة مستقلة لكل منتج متعثر.');
            $seen = [];
            foreach ($plan['failed'] as $generation) {
                try {
                    $offer = json_decode(Crypt::decryptString($choices[$generation->order_item_id] ?? ''), true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    abort(409, 'حدّث الصفحة وأعد اختيار المعاينات.');
                }
                $item = $generation->item;
                $inputs = app(PuzzleInputs::class)->captureCurrent($item);
                abort_if(isset($seen[$offer['preview'] ?? 0]), 409, 'اختر صورة مختلفة لكل منتج؛ لم يُحفظ ربط جزئي.');
                abort_unless(($offer['item'] ?? null) === $item->id && ($offer['generation'] ?? null) === $generation->id
                    && ($offer['run'] ?? null) === $plan['run']->id && ($offer['run_version'] ?? null) === $plan['run']->version
                    && ($offer['control_version'] ?? null) === $plan['control_version'] && hash_equals($offer['input'], $inputs['fingerprint']), 409, 'تغيّرت البيانات أو حالة التنفيذ؛ حدّث الصفحة.');
                $preview = $this->preview($item, $offer['preview']);
                abort_unless($preview->updated_at->toISOString() === $offer['preview_updated'] && ! isset($seen[$preview->id]), 409, 'الصورة تغيّرت أو اختيرت لمنتجين؛ راجع الاختيار.');
                $seen[$preview->id] = true;
                // Preserve any uncollected provider result for review; do not hide it.
                foreach (array_unique(array_filter([$generation->disk, config('media.private_disk', 'local')])) as $disk) {
                    foreach (['puzzle-'.$generation->id.'.png', 'puzzle-'.$generation->id.'.jpg', 'puzzle-'.$generation->id.'.webp', 'book-cover-'.$generation->id.'-a4.png'] as $file) {
                        if (Storage::disk($disk)->exists('order-attachments/'.$generation->order_id.'/'.$file)) {
                            throw new RuntimeException('manual_artwork_uncollected_output');
                        }
                    }
                }
                $preview->production_unit_key = $inputs['unit_key'];
                $preview->save();
                $generation->update(['status' => 'manual_artwork', 'context' => array_merge($generation->context ?? [], ['manual_artwork' => [
                    'preview_id' => $preview->id, 'sha256' => $preview->checksum, 'inputs' => $inputs,
                    'actor_id' => $actor->id, 'at' => now()->toIso8601String(), 'previous_status' => $generation->status,
                ]])]);
                AdminActivityLogger::log('order.manual_product_artwork_selected', 'ربط معاينة رفعها الفريق بدل محاولة منتهية؛ لا اعتماد للعميل ولا دفع أو توليد جديد.', $item->order,
                    ['generation_id' => $generation->id, 'item_id' => $item->id, 'preview_id' => $preview->id], $actor, $request);
            }
            // A new unsent version uses the selected files. Normal customer approval
            // and payment gates remain; the previous failures/billing stay recorded.
            $hold = DB::table('order_automation_holds')->where('checkout_key', $key)->first();
            app(ProductPreviewReturn::class)->restart($plan['rows'], collect([$plan['run']]), $hold, $actor->id);
            DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
            DB::table('order_automation_controls')->where('checkout_key', $key)->update(['state' => 'running', 'version' => $plan['control_version'] + 1, 'updated_at' => now()]);
        });
    }

    public function image(OrderItem $item, PuzzleGeneration $generation, array $current): array
    {
        $receipt = $generation->context['manual_artwork'] ?? [];
        if ($generation->status !== 'manual_artwork' || ! hash_equals($receipt['inputs']['fingerprint'] ?? '', $current['fingerprint'])) {
            throw new RuntimeException('manual_artwork_changed');
        }
        $preview = $this->preview($item, $receipt['preview_id']);
        if (! hash_equals($receipt['sha256'], $preview->checksum)) {
            throw new RuntimeException('manual_artwork_changed');
        }

        return ['kind' => $current['production_stage'] ?? 'product', 'order_id' => $preview->order_id, 'item_id' => $item->id,
            'generation_id' => $generation->id, 'preview_id' => $preview->id, 'unit_key' => $current['unit_key'],
            'input_fingerprint' => $current['fingerprint'], 'manual_artwork' => true,
            'disk' => $preview->disk, 'path' => $preview->file_path, 'sha256' => $preview->checksum,
            'interior_fingerprint' => app(ProcessProductReview::class)->interiorFingerprint($item), 'label' => $item->title.' — '.$item->order->child_name];
    }
}
