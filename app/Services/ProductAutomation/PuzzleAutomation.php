<?php

namespace App\Services\ProductAutomation;

use App\DTOs\Ai\GptImageRequest;
use App\Models\AiModel;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderProcessEvent;
use App\Models\OrderProductPreviewGallery;
use App\Models\PuzzleGeneration;
use App\Models\User;
use App\Services\Ai\AiImagePricingService;
use App\Services\Ai\GptImageClient;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\PreviewRegenerationGuard;
use App\Support\AdminActivityLogger;
use App\Support\BookArtwork;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PuzzleAutomation
{
    public function enabled(): bool
    {
        return config('puzzle_automation.enabled') && is_numeric(config('puzzle_automation.after_event_id'));
    }

    /** Independent event cursor: consuming story events never consumes puzzle events. */
    public function enroll(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        DB::table('puzzle_automation_cursors')->insertOrIgnore(['id' => 1, 'event_id' => (int) config('puzzle_automation.after_event_id')]);

        return DB::transaction(function () {
            $cursor = DB::table('puzzle_automation_cursors')->where('id', 1)->lockForUpdate()->first();
            $events = OrderProcessEvent::where('id', '>', max($cursor->event_id, (int) config('puzzle_automation.after_event_id')))->orderBy('id')->limit(100)->get();
            $created = 0;
            foreach ($events as $event) {
                if (in_array($event->type, ['status_entered', 'automation_resumed'], true) && ($event->data['status'] ?? null) === 'new_confirmed') {
                    $rows = Order::where('checkout_group_key', $event->checkout_key)->orderBy('id')->lockForUpdate()->get();
                    $items = OrderItem::whereIn('order_id', $rows->pluck('id'))
                        ->whereHas('product', fn ($q) => $q->whereIn('slug', config('puzzle_automation.product_slugs')))->get();
                    foreach ($items as $item) {
                        if (PuzzleGeneration::where('order_item_id', $item->id)->exists()) {
                            continue;
                        }
                        // Ignore a stale confirmation after cancellation or manual progression.
                        if (in_array($rows->firstWhere('id', $item->order_id)?->status, ['new', 'cancelled', 'completed'], true)) {
                            continue;
                        }
                        $run = PuzzleGeneration::create(['order_id' => $item->order_id, 'order_item_id' => $item->id,
                            'event_id' => $event->id, 'checkout_key' => $event->checkout_key, 'unit_key' => 'product:'.$item->id,
                            'status' => 'queued']);
                        try {
                            $context = app(PuzzleInputs::class)->capture($item);
                            $this->assertNoOutput($run);
                            $run->update(['context' => $context, 'unit_key' => $context['unit_key']]);
                        } catch (Throwable $error) {
                            $run->update(['status' => 'blocked', 'error_code' => $this->safeError($error, 'puzzle_preflight_failed'), 'finished_at' => now()]);
                        }
                        $created++;
                    }
                }
            }
            if ($events->isNotEmpty()) {
                DB::table('puzzle_automation_cursors')->where('id', 1)->update(['event_id' => $events->last()->id]);
            }

            return $created;
        });
    }

    private function handoffToken(string $key): ?string
    {
        $hold = DB::table('order_automation_holds')->where('checkout_key', $key)->first();

        return $hold ? hash('sha256', json_encode([$hold->user_id, $hold->created_at, $hold->fingerprint])) : null;
    }

    private function outputBaseline(int $orderId, string $unit): string
    {
        $attachments = OrderAttachment::where('order_id', $orderId)->where(fn ($q) => $q->where('production_unit_key', $unit)->orWhereNull('production_unit_key'))
            ->orderBy('id')->get(['id', 'disk', 'path'])->toArray();
        $previews = OrderPreview::where('order_id', $orderId)->where(fn ($q) => $q->where('production_unit_key', $unit)->orWhereNull('production_unit_key'))
            ->orderBy('id')->get(['id', 'disk', 'file_path', 'checksum'])->toArray();

        return hash('sha256', json_encode([$attachments, $previews], JSON_THROW_ON_ERROR));
    }

    public function regenerate(Order $order, OrderItem $item, User $actor, int $previousId, ?string $promptOverride = null): PuzzleGeneration
    {
        abort_unless($actor->is_active && ! $actor->agent_api_enabled && $actor->hasPermission('orders.update')
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
        abort_unless($item->order_id === $order->id, 404);

        return DB::transaction(function () use ($order, $item, $actor, $previousId, $promptOverride) {
            $rows = Order::where('checkout_group_key', $order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
            $order = $rows->firstWhere('id', $order->id);
            abort_unless($order, 404);
            $item = OrderItem::where('order_id', $order->id)->lockForUpdate()->findOrFail($item->id);
            $runs = PuzzleGeneration::where('order_item_id', $item->id)->orderBy('id')->lockForUpdate()->get();
            $previous = $runs->firstWhere('id', $previousId);
            abort_unless($previous, 422, 'المحاولة السابقة لا تخص هذا المنتج.');
            if ($duplicate = $runs->firstWhere('previous_run_id', $previousId)) {
                return $duplicate; // Double-click/replayed form never creates another paid call.
            }
            if (! $this->enabled() || $runs->last()->id !== $previousId
                || ! in_array($previous->status, ['succeeded', 'review', 'blocked'], true)
                || $runs->contains(fn ($r) => in_array($r->status, ['queued', 'processing', 'uncertain'], true))
                || app(AutomationAssignment::class)->owns($order)) {
                throw ValidationException::withMessages(['product_generation' => 'انتظر انتهاء التنفيذ الحالي أو راجع المحاولة غير المؤكدة، ثم استلم الطلب يدويًا إذا كان تحت مسؤولية الأوتوميشن.']);
            }
            $this->assertRegenerationStage($rows);
            app(PreviewRegenerationGuard::class)->check($order);
            try {
                $context = app(PuzzleInputs::class)->capture($item, $promptOverride);
                if ($promptOverride !== null) {
                    $context['prompt_override'] = $promptOverride;
                }
            } catch (Throwable $error) {
                throw ValidationException::withMessages(['product_generation' => 'راجع برومبت المنتج وصوره: '.$this->safeError($error, 'puzzle_preflight_failed')]);
            }
            $context['output_baseline'] = $this->outputBaseline($order->id, $context['unit_key']);
            $run = PuzzleGeneration::create(['order_id' => $order->id, 'order_item_id' => $item->id,
                'event_id' => $previous->event_id, 'checkout_key' => $order->checkoutGroupKey(), 'unit_key' => $context['unit_key'],
                'attempt_number' => $previous->attempt_number + 1, 'previous_run_id' => $previous->id,
                'requested_by' => $actor->id, 'handoff_token' => $this->handoffToken($order->checkoutGroupKey()),
                'context' => $context, 'status' => 'queued']);
            AdminActivityLogger::log('order.product_regeneration_requested', 'طلب إعادة توليد صورة هذا المنتج بالبيانات الحالية مع الاحتفاظ بالصور السابقة.', $order,
                ['run_id' => $run->id, 'previous_run_id' => $previous->id, 'order_item_id' => $item->id,
                    'attempt_number' => $run->attempt_number, 'estimate_usd' => $context['estimate_usd']], $actor);

            return $run;
        }, 3);
    }

    private function assertRegenerationStage($rows): void
    {
        if ($rows->contains(fn ($r) => in_array($r->status, ['new', 'cancelled', 'completed'], true)
            || ! in_array($r->printing_status, ['not_started', 'ready', 'on_hold', 'not_required'], true)
            || ! in_array($r->shipping_status, ['not_ready', 'ready', 'not_required'], true))) {
            throw ValidationException::withMessages(['product_generation' => 'إعادة التوليد متاحة للطلب المؤكد قبل بدء الطباعة والشحن.']);
        }
    }

    /** Explicit manual return may refresh only work that never reached a provider. */
    public function refreshUnstartedForReturn(string $key): void
    {
        foreach (PuzzleGeneration::where('checkout_key', $key)->whereIn('id', PuzzleGeneration::selectRaw('MAX(id)')->where('checkout_key', $key)->groupBy('order_item_id'))->orderBy('id')->lockForUpdate()->get() as $run) {
            if (in_array($run->status, ['processing', 'uncertain'], true)) {
                throw ValidationException::withMessages(['assignment' => 'محاولة توليد المنتج جارية أو غير مؤكدة؛ يلزم مراجعتها قبل الإرجاع.']);
            }
            if ($run->requested_by && in_array($run->status, ['queued', 'blocked'], true)) {
                throw ValidationException::withMessages(['assignment' => 'راجع محاولة إعادة التوليد من بند المنتج قبل الإرجاع؛ لن يُعاد تشغيلها تلقائيًا.']);
            }
            $unstarted = in_array($run->status, ['queued', 'blocked'], true) && ! $run->started_at
                && ! $run->provider_request_id && ! $run->output_path && ! $run->attachment_id && ! $run->preview_id;
            try {
                $fresh = app(PuzzleInputs::class)->capture(OrderItem::findOrFail($run->order_item_id));
                if (! $unstarted) {
                    if (! $run->context || ! hash_equals($run->context['fingerprint'], $fresh['fingerprint'])) {
                        throw new RuntimeException('puzzle_completed_inputs_changed');
                    }

                    continue; // Preserve completed/attempted work exactly; never retry it here.
                }
                $this->assertNoOutput($run);
                $oldFingerprint = $run->context['fingerprint'] ?? null;
                $run->update(['context' => $fresh, 'unit_key' => $fresh['unit_key'], 'status' => 'queued',
                    'error_code' => null, 'finished_at' => null]);
                AutomationActivity::record($run->order, 'product_inputs_refreshed',
                    'تحديث بيانات محاولة لم يبدأ توليدها بعد، عقب الإرجاع اليدوي.',
                    ['run_id' => $run->id, 'order_item_id' => $run->order_item_id,
                        'old_fingerprint' => $oldFingerprint, 'new_fingerprint' => $fresh['fingerprint']]);
            } catch (Throwable $error) {
                throw ValidationException::withMessages(['assignment' => 'تعذرت إعادة تقييم بند المنتج #'.$run->order_item_id.': '.$this->safeError($error, 'puzzle_preflight_failed').'. لم تُعَد أي محاولة توليد.']);
            }
        }
    }

    /** Unique single-attempt AI job; durable run state prevents provider replay. */
    public function tick(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $count = $this->enroll();
        foreach (PuzzleGeneration::where('status', 'processing')->where('lease_until', '<', now())->get() as $run) {
            DB::transaction(function () use ($run) {
                $run = PuzzleGeneration::lockForUpdate()->findOrFail($run->id);
                if ($run->status === 'processing' && $run->lease_until->isPast()) {
                    $run->update(['status' => 'uncertain', 'error_code' => 'puzzle_receipt_missing', 'billing_status' => 'unknown', 'finished_at' => now()]);
                }
            });
        }
        $next = PuzzleGeneration::where('status', 'queued')->where(fn ($q) => $q->whereNotIn('checkout_key', DB::table('order_automation_controls')->select('checkout_key')->where('state', '!=', 'running'))->orWhere(fn ($q) => $q->whereNotNull('requested_by')->whereIn('checkout_key', DB::table('order_automation_controls')->select('checkout_key')->where('state', 'paused'))))->where(fn ($q) => $q->whereNotIn('checkout_key', DB::table('order_automation_holds')->select('checkout_key'))->orWhereNotNull('requested_by'))->orderBy('id')->first();
        if ($next) {
            $this->generate($next->id);
        }

        return $count;
    }

    public function generate(int $id): void
    {
        $run = DB::transaction(function () use ($id) {
            // Same lock ordering as manual handoff: checkout rows, then puzzle run.
            $candidate = PuzzleGeneration::findOrFail($id);
            Order::where('checkout_group_key', $candidate->checkout_key)->orderBy('id')->lockForUpdate()->get();
            $run = PuzzleGeneration::lockForUpdate()->findOrFail($id);
            if (! AutomationControl::admits($run->checkout_key, (bool) $run->requested_by)) {
                return null;
            }
            if ($run->status !== 'queued' || ! $this->enabled() || (! $run->requested_by && AutomationHandoff::held($run->checkout_key))) {
                return null;
            }
            try {
                $this->guard($run);
                $run->update(['status' => 'processing', 'started_at' => now(), 'lease_until' => now()->addSeconds(300)]);

                return $run;
            } catch (Throwable $error) {
                $run->update(['status' => 'blocked', 'error_code' => $this->safeError($error, 'puzzle_preflight_failed'), 'finished_at' => now()]);

                return null;
            }
        });
        if (! $run) {
            return;
        }
        $submitted = false;
        try {
            $context = $run->context;
            $model = AiModel::with('provider')->findOrFail($context['model_id']);
            $provider = clone $model->provider;
            $provider->default_timeout_seconds = 180;
            $provider->default_max_retries = 1;
            $images = app(WorkflowInputs::class)->images($context);
            $submitted = true;
            $result = app(GptImageClient::class)->generate(new GptImageRequest($provider, $model, $context['prompt'], $images,
                $context['size'], $context['quality'], 'puzzle-'.$run->id, maxAttempts: 1));
            $cost = app(AiImagePricingService::class)->calculate($model, $context['size'], $context['quality'], $result->usage);
            $run->update(['provider_request_id' => $result->providerRequestId, 'usage' => $result->usage,
                'cost_usd' => $cost['cost_usd'], 'billing_status' => $cost['billing_status']]);
            $size = @getimagesizefromstring($result->contents);
            if (! $size || strlen($result->contents) > 25 * 1024 * 1024 || $size[0] * $size[1] > 25000000
                || ! in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new RuntimeException('puzzle_invalid_output');
            }
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$size['mime']];
            $disk = config('media.private_disk', 'local');
            $path = 'order-attachments/'.$run->order_id.'/puzzle-'.$run->id.'.'.$ext;
            $checksum = hash('sha256', $result->contents);
            $this->store($disk, $path, $result->contents, $checksum);
            $cost = app(AiImagePricingService::class)->calculate($model, $context['size'], $context['quality'], $result->usage);
            // Retain the receipt/output even if later publication or input checks fail.
            $run->update(['disk' => $disk, 'output_path' => $path, 'checksum' => $checksum,
                'provider_request_id' => $result->providerRequestId, 'usage' => $result->usage,
                'cost_usd' => $cost['cost_usd'], 'billing_status' => $cost['billing_status']]);
            $contents = $result->contents;
            $artwork = null;
            if (in_array($context['artwork_contract'] ?? '', ['a4-cover-v1', 'a4-cover-v2'], true)) {
                $artwork = BookArtwork::a4($contents, true, $context['artwork_contract']);
                $rawPath = $path;
                $contents = $artwork['bytes'];
                unset($artwork['bytes']);
                $path = 'order-attachments/'.$run->order_id.'/book-cover-'.$run->id.'-a4.png';
                $ext = 'png';
                $size = getimagesizefromstring($contents);
                $checksum = hash('sha256', $contents);
                $this->store($disk, $path, $contents, $checksum);
                $run->update(['output_path' => $path, 'checksum' => $checksum]);
                AutomationActivity::record($run->order, 'book_cover_prepared', 'تجهيز كفر A4 مع الاحتفاظ بالأصل؛ يلزم فحص الاسم والهوية ودقة الطباعة.',
                    ['run_id' => $run->id, 'raw_path' => $rawPath, 'artwork' => $artwork]);
            }
            DB::transaction(function () use ($run, $contents, $artwork, $size, $ext, $disk, $path, $checksum) {
                Order::where('checkout_group_key', $run->checkout_key)->orderBy('id')->lockForUpdate()->get();
                $run = PuzzleGeneration::lockForUpdate()->findOrFail($run->id);
                try {
                    if ($run->status !== 'processing') {
                        throw new RuntimeException('puzzle_late_output');
                    }
                    $this->guard($run);
                    $book = ($run->context['production_stage'] ?? '') === 'book_cover';
                    if ($book ? ($size[0] < 1024 || $size[1] <= $size[0] || abs($size[1] / $size[0] - ($artwork ? 297 / 210 : 1.5)) > .015) : ($size[1] < 1024 || $size[0] < 1536 || abs($size[0] / $size[1] - 1.5) > .015)) {
                        throw new RuntimeException('puzzle_output_quality');
                    }
                } catch (Throwable $error) {
                    $run->update(['status' => 'review', 'error_code' => $this->safeError($error, 'puzzle_output_review'), 'finished_at' => now(), 'lease_until' => null]);

                    return;
                }
                $gallery = OrderProductPreviewGallery::where('checkout_group_key', $run->checkout_key)->lockForUpdate()->first();
                if (! $gallery) {
                    $token = Str::random(64);
                    $gallery = OrderProductPreviewGallery::create(['checkout_group_key' => $run->checkout_key, 'status' => 'active',
                        'public_token_hash' => hash('sha256', $token), 'public_token_encrypted' => Crypt::encryptString($token)]);
                }
                // Independent objects: deleting a preview must not remove the attachment.
                $previewPath = 'orders/product-previews/'.$gallery->id.'/puzzle-'.$run->id.'.'.$ext;
                $this->store($disk, $previewPath, $contents, $checksum);
                $name = 'product-'.$run->order_id.'-'.$run->order_item_id.'-attempt-'.($run->attempt_number ?: 1).'.'.$ext;
                $attachment = OrderAttachment::create(['order_id' => $run->order_id, 'production_unit_key' => $run->unit_key,
                    'disk' => $disk, 'path' => $path, 'original_name' => $name, 'mime_type' => $size['mime'], 'size' => strlen($contents),
                    'note' => $artwork ? 'Automation — كفر A4؛ دقة فعلية '.$artwork['effective_ppi'].' PPI. راجع الاسم والهوية والجودة قبل الطباعة.' : 'Automation — صورة المنتج؛ راجع الهوية والتصميم قبل الطباعة.', 'validity_days' => null, 'expires_at' => null]);
                $preview = OrderPreview::create(['order_id' => $run->order_id, 'production_unit_key' => $run->unit_key,
                    'product_gallery_id' => $gallery->id, 'file_path' => $previewPath, 'disk' => $disk, 'original_name' => $name,
                    'mime_type' => $size['mime'], 'file_size' => strlen($contents), 'checksum' => $checksum,
                    'note' => 'Automation — '.$run->item->title]);
                $run->update(['status' => 'succeeded', 'attachment_id' => $attachment->id, 'preview_id' => $preview->id,
                    'finished_at' => now(), 'lease_until' => null, 'error_code' => null]);
                // Do not change a mixed checkout's story/payment state or send WhatsApp.
            });
        } catch (Throwable $error) {
            $run->refresh();
            $run->update(['status' => $run->output_path ? 'review' : ($submitted ? 'uncertain' : 'blocked'),
                'error_code' => $this->safeError($error, $submitted ? 'puzzle_result_uncertain' : 'puzzle_inputs_failed'),
                'billing_status' => $run->provider_request_id ? $run->billing_status : ($submitted ? 'unknown' : 'not_billable'),
                'finished_at' => now(), 'lease_until' => null]);
        }
    }

    private function guard(PuzzleGeneration $run): void
    {
        if (! $this->enabled() || (! $run->requested_by && AutomationHandoff::held($run->checkout_key))) {
            throw new RuntimeException('puzzle_paused');
        }
        if ($run->requested_by && $run->handoff_token !== $this->handoffToken($run->checkout_key)) {
            throw new RuntimeException('puzzle_manual_handoff_changed');
        }
        if ($run->requested_by) {
            $this->assertRegenerationStage(Order::where('checkout_group_key', $run->checkout_key)->get());
        }
        $item = OrderItem::findOrFail($run->order_item_id);
        $fresh = app(PuzzleInputs::class)->capture($item, $run->context['prompt_override'] ?? null);
        if (! $run->context || ! hash_equals($run->context['fingerprint'], $fresh['fingerprint'])) {
            throw new RuntimeException('puzzle_inputs_changed');
        }
        $this->assertNoOutput($run);
    }

    private function assertNoOutput(PuzzleGeneration $run): void
    {
        if ($run->previous_run_id) {
            if (! hash_equals($run->context['output_baseline'] ?? '', $this->outputBaseline($run->order_id, $run->unit_key))) {
                throw new RuntimeException('puzzle_existing_output_changed');
            }

            return;
        }
        if (OrderAttachment::where('order_id', $run->order_id)->where(fn ($q) => $q->where('production_unit_key', $run->unit_key)->orWhereNull('production_unit_key'))->exists()
            || OrderPreview::where('order_id', $run->order_id)->where(fn ($q) => $q->where('production_unit_key', $run->unit_key)->orWhereNull('production_unit_key'))->exists()) {
            throw new RuntimeException('puzzle_existing_output');
        }
    }

    private function store(string $disk, string $path, string $bytes, string $checksum): void
    {
        if (! Storage::disk($disk)->put($path, $bytes)
            || ! hash_equals($checksum, hash('sha256', Storage::disk($disk)->get($path)))) {
            throw new RuntimeException('puzzle_storage_failed');
        }
    }

    private function safeError(Throwable $error, string $fallback): string
    {
        return preg_match('/\Apuzzle_[a-z_]+\z/', $error->getMessage()) ? $error->getMessage() : $fallback;
    }
}
