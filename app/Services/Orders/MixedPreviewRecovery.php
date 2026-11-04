<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAutomationRun;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\OrderAutomation\Process\ProcessIdentityService;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A fresh, explicit admission after a preview node failed before claiming effects. */
class MixedPreviewRecovery
{
    public static function reusable(PuzzleGeneration $generation): bool
    {
        return ! $generation->lease_until
            && filled($generation->context['fingerprint'] ?? null)
            && ($generation->context['unit_key'] ?? null) === $generation->unit_key
            && (($generation->status === 'queued' && ! $generation->requested_by && ! $generation->started_at && ! $generation->provider_request_id
                    && ! $generation->output_path && ! $generation->attachment_id && ! $generation->preview_id)
                || ($generation->status === 'succeeded' && $generation->attachment_id && $generation->preview_id && $generation->output_path && $generation->checksum));
    }

    public static function candidate(OrderProcessRun $run): bool
    {
        $first = $run->revision->definition['nodes'][0] ?? [];
        $orders = Order::where('checkout_group_key', $run->checkout_key);

        return in_array($run->status, ['blocked', 'paused'], true)
            && ($first['type'] ?? null) === 'generate_previews' && $run->node_id === ($first['id'] ?? null)
            && ! $run->steps()->exists()
            && OrderProcessRun::where('checkout_key', $run->checkout_key)->whereNotIn('status', ['cancelled', 'completed'])->count() === 1
            && ! OrderIdentityRegeneration::where('checkout_key', $run->checkout_key)->exists()
            && ! OrderAutomationRun::whereIn('order_id', (clone $orders)->select('id'))->where('dry_run', false)->exists()
            && ! CodexSceneRun::where('checkout_key', $run->checkout_key)->exists()
            && ! OrderReviewManifest::where('checkout_key', $run->checkout_key)->exists()
            && ! RoboDeskIntegrationEvent::where('checkout_group_key', $run->checkout_key)->where('event_type', 'order.pending_confirmation')->whereIn('status', ['processing', 'unknown'])->exists()
            && ! OrderProcessIdentity::whereIn('run_id', OrderProcessRun::where('checkout_key', $run->checkout_key)->select('id'))->exists()
            && PuzzleGeneration::where('checkout_key', $run->checkout_key)->get()->every(fn ($generation) => self::reusable($generation));
    }

    /** SELECT/S3 reads only; also used to explain a rejected admission before any effect. */
    public function plan(OrderProcessRun $previous): array
    {
        $deny = fn (string $reason) => throw ValidationException::withMessages(['automation' => OrderAutomationGuidance::reason($reason)]);
        $definition = $previous->revision->definition;
        $policy = app(ProcessLivePolicy::class);
        $authority = clone $previous->automation;
        $authority->setRelation('revision', $previous->revision);
        $authority->process_activated_by = $previous->context['actor_id'];
        if (! $authority->enabled || ! $policy->enabled() || ! $policy->allowsAutomation($previous->automation_id)
            || ! $policy->allowsCheckout($previous->checkout_key, true) || ! $policy->authorized($authority)) {
            $deny('actor_revoked');
        }
        $policy->check($definition);
        $contexts = app(ProcessOrderContext::class);
        $rows = $contexts->orders($previous->checkout_key);
        $snapshot = $contexts->snapshot($rows);
        if (! $rows->every(fn ($row) => $row->status === 'new_confirmed' && $row->printing_status === 'not_started' && $row->shipping_status === 'not_ready')
            || ! $contexts->matches($definition, $snapshot)) {
            $deny('order_ineligible');
        }
        $totalCost = 0;
        $reused = [];
        foreach (OrderItem::whereIn('order_id', $rows->modelKeys())->whereIn('item_type', ['product', 'product_add_on'])->orderBy('id')->get() as $item) {
            try {
                $current = app(PuzzleInputs::class)->captureCurrent($item);
                $totalCost += $current['estimate_usd'];
                $saved = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first();
                $attachments = $item->order->attachments()->where(fn ($q) => $q->where('production_unit_key', $current['unit_key'])->orWhereNull('production_unit_key'))->get();
                if ($saved) {
                    if (! self::reusable($saved) || $saved->order_id !== $item->order_id || $saved->checkout_key !== $previous->checkout_key
                        || $saved->unit_key !== $current['unit_key'] || ! hash_equals($current['fingerprint'], $saved->context['fingerprint'] ?? '')) {
                        throw new \RuntimeException('preview_product_changed');
                    }
                    if ($saved->status === 'succeeded') {
                        $preview = OrderPreview::with('productGallery')->find($saved->preview_id);
                        $attachment = $attachments->firstWhere('id', $saved->attachment_id);
                        if (! $preview || ! $attachment || $attachments->contains(fn ($file) => ! $file->production_unit_key)
                            || $preview->order_id !== $item->order_id || $preview->production_unit_key !== $current['unit_key']
                            || $preview->productGallery?->status !== 'active' || $preview->checksum !== $saved->checksum
                            || $attachment->production_unit_key !== $current['unit_key'] || $attachment->disk !== $saved->disk || $attachment->path !== $saved->output_path) {
                            throw new \RuntimeException('preview_image_unavailable');
                        }
                        foreach (collect([[$preview->disk, $preview->file_path], [$attachment->disk, $attachment->path]])->unique(fn ($pair) => implode(':', $pair)) as [$disk, $path]) {
                            if (! hash_equals($saved->checksum, hash('sha256', Storage::disk($disk)->get($path)))) {
                                throw new \RuntimeException('preview_image_unavailable');
                            }
                        }
                    } elseif ($attachments->isNotEmpty()) {
                        throw new \RuntimeException('preview_existing_attachment');
                    }
                    $reused[] = $saved->id;
                } elseif ($attachments->isNotEmpty() || $item->order->previews()->where(fn ($q) => $q->where('production_unit_key', $current['unit_key'])->orWhereNull('production_unit_key'))->exists()) {
                    throw new \RuntimeException('preview_existing_attachment');
                }
            } catch (\Throwable $error) {
                $code = preg_match('/\A[a-z][a-z0-9_]+\z/', $error->getMessage()) ? $error->getMessage() : 'preview_image_unavailable';
                throw ValidationException::withMessages(['automation' => 'المنتج #'.$item->id.': '.OrderAutomationGuidance::reason($code)]);
            }
        }
        $config = $definition['nodes'][0]['config'];
        if ($totalCost > (float) $config['budget_usd']) {
            $deny('preview_budget_exceeded');
        }
        if ($rows->contains(fn ($row) => (bool) $row->story_id)) {
            $candidate = clone $previous;
            $candidate->context = $snapshot + ['actor_id' => $previous->context['actor_id']];
            $config['budget_usd'] -= $totalCost;
            try {
                app(ProcessIdentityService::class)->preflight($candidate, $config);
            } catch (\Throwable $error) {
                $deny(preg_match('/\A[a-z][a-z0-9_]+\z/', $error->getMessage()) ? $error->getMessage() : 'identity_requires_review');
            }
        }

        return ['snapshot' => $snapshot, 'reused_generation_ids' => $reused];
    }

    /** Caller holds control/run/order/product locks; native workers claim missing work later. */
    public function restart(OrderProcessRun $previous, User $actor, Request $request, int $controlVersion): void
    {
        $key = $previous->checkout_key;
        Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
        PuzzleGeneration::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
        abort_unless(self::candidate($previous), 409, 'بدأ تنفيذ أو تغيرت نتيجة محفوظة؛ حدّث الصفحة. لم يُكرر التوليد.');
        $plan = $this->plan($previous);
        $event = OrderProcessEvent::create(['event_key' => 'preview-recheck:'.Str::uuid(), 'checkout_key' => $key,
            'order_id' => $previous->order_id, 'type' => 'preview_preflight_rechecked', 'status' => 'processed',
            'data' => ['requested_by' => $actor->id, 'previous_run_id' => $previous->id]]);
        $previous->update(['status' => 'cancelled', 'active_key' => null, 'next_at' => null, 'version' => $previous->version + 1]);
        $run = OrderProcessRun::create(['automation_id' => $previous->automation_id, 'revision_id' => $previous->revision_id,
            'event_id' => $event->id, 'order_id' => $previous->order_id, 'checkout_key' => $key,
            'account_key' => $previous->account_key, 'phone_hash' => hash('sha256', '+'.$plan['snapshot']['phone']),
            'enrollment_key' => hash('sha256', 'preview-recheck:'.$event->id), 'status' => 'queued',
            'node_id' => $previous->revision->definition['nodes'][0]['id'], 'next_at' => now(),
            'context' => $plan['snapshot'] + ['actor_id' => $previous->context['actor_id'], 'supersedes_run_id' => $previous->id]]);
        // Only a verified paused preflight can reach here; no messages were claimed.
        RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->where('status', 'held')->update(['status' => 'cancelled']);
        DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
        DB::table('order_automation_controls')->where('checkout_key', $key)->update(['state' => 'running', 'version' => $controlVersion + 1, 'error_code' => null, 'updated_at' => now()]);
        AdminActivityLogger::log('order.preview_preflight_rechecked', 'فحص واستكمال الناقص من معاينة الطلب المختلط؛ الاحتفاظ بالصور المكتملة دون إعادة تأكيد الطلب.',
            Order::findOrFail($previous->order_id), ['previous_run_id' => $previous->id, 'run_id' => $run->id,
                'reused_generation_ids' => $plan['reused_generation_ids']], $actor, $request);
    }
}
