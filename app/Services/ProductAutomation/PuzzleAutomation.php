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
use App\Services\Ai\AiImagePricingService;
use App\Services\Ai\GptImageClient;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\Orders\AutomationHandoff;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
                if ($event->type === 'status_entered' && ($event->data['status'] ?? null) === 'new_confirmed') {
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
        $next = PuzzleGeneration::where('status', 'queued')->whereNotIn('checkout_key', DB::table('order_automation_holds')->select('checkout_key'))->orderBy('id')->first();
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
            if ($run->status !== 'queued' || ! $this->enabled() || AutomationHandoff::held($run->checkout_key)) {
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
            DB::transaction(function () use ($run, $result, $size, $ext, $disk, $path, $checksum) {
                Order::where('checkout_group_key', $run->checkout_key)->orderBy('id')->lockForUpdate()->get();
                $run = PuzzleGeneration::lockForUpdate()->findOrFail($run->id);
                try {
                    if ($run->status !== 'processing') {
                        throw new RuntimeException('puzzle_late_output');
                    }
                    $this->guard($run);
                    if ($size[1] < 1024 || $size[0] < 1536 || abs($size[0] / $size[1] - 1.5) > .015) {
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
                $this->store($disk, $previewPath, $result->contents, $checksum);
                $name = 'puzzle-'.$run->order_id.'-'.$run->order_item_id.'.'.$ext;
                $attachment = OrderAttachment::create(['order_id' => $run->order_id, 'production_unit_key' => $run->unit_key,
                    'disk' => $disk, 'path' => $path, 'original_name' => $name, 'mime_type' => $size['mime'], 'size' => strlen($result->contents),
                    'note' => 'Automation — صورة البازل؛ راجع الهوية والتصميم قبل الطباعة.', 'validity_days' => null, 'expires_at' => null]);
                $preview = OrderPreview::create(['order_id' => $run->order_id, 'production_unit_key' => $run->unit_key,
                    'product_gallery_id' => $gallery->id, 'file_path' => $previewPath, 'disk' => $disk, 'original_name' => $name,
                    'mime_type' => $size['mime'], 'file_size' => strlen($result->contents), 'checksum' => $checksum,
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
        if (! $this->enabled() || AutomationHandoff::held($run->checkout_key)) {
            throw new RuntimeException('puzzle_paused');
        }
        $item = OrderItem::findOrFail($run->order_item_id);
        $fresh = app(PuzzleInputs::class)->capture($item);
        if (! $run->context || ! hash_equals($run->context['fingerprint'], $fresh['fingerprint'])) {
            throw new RuntimeException('puzzle_inputs_changed');
        }
        $this->assertNoOutput($run);
    }

    private function assertNoOutput(PuzzleGeneration $run): void
    {
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
