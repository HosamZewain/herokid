<?php

namespace App\Services\Orders;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAutomationRun;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\CodexScenes\BookPageService;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Explicit checkout-level manual ownership, independent of the current employee. */
class AutomationHandoff
{
    public static function held(string $key): bool
    {
        return DB::table('order_automation_holds')->where('checkout_key', $key)->exists();
    }

    private function fingerprint(Collection $orders): string
    {
        $facts = $orders->map(function ($order) {
            $facts = $order->only(['id', 'status', 'story_id', 'parent_name', 'child_name', 'child_age', 'child_gender', 'uploaded_photos', 'child_identity_request_id', 'child_identity_approved_attempt_id', 'printing_status', 'shipping_status', 'discount_cents', 'delivery_details', 'story_price', 'interests', 'lesson', 'gift_note']);
            foreach (['paid_amount', 'remaining_amount', 'payment_status', 'payment_method'] as $field) {
                unset($facts['delivery_details'][$field]);
            }
            $facts['items'] = $order->items()->orderBy('id')->get()->map(fn ($item) => $item->only(['id', 'item_type', 'story_id', 'product_id', 'product_variant_id', 'title', 'quantity', 'unit_price_cents', 'total_price_cents']))->all();

            return $facts;
        })->all();

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && ! $actor->agent_api_enabled && $actor->hasPermission('orders.automation.control') && $actor->hasPermission('orders.update'), 403);
    }

    public function take(Order $order, User $actor, Request $request, bool $settling = false): void
    {
        if (! $settling) {
            $this->authorize($actor);
        }
        if (! $settling) {
            abort_unless(app(OrderAccess::class)->controls($order, $actor), 403);
        }
        DB::transaction(function () use ($order, $actor, $request, $settling) {
            $key = $order->checkoutGroupKey();
            // Match worker lock order: run/event before checkout rows.
            $runs = OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            $events = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->orderBy('id')->lockForUpdate()->get();
            $rows = Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            if (OrderAutomationRun::whereIn('order_id', $rows->pluck('id'))->where('dry_run', false)->where(fn ($q) => $q->whereIn('status', ['generating', 'polling', 'uncertain'])->orWhereNotNull('lease_until'))->exists()) {
                throw ValidationException::withMessages(['automation' => 'هناك محاولة هوية قديمة جارية أو نتيجتها غير مؤكدة؛ يلزم مراجعتها.']);
            }
            if (OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', $settling ? ['processing', 'uncertain'] : ['queued', 'processing', 'uncertain'])->exists()) {
                throw ValidationException::withMessages(['assignment' => 'إعادة توليد الهوية جارية أو نتيجتها غير مؤكدة؛ انتظر المراجعة.']);
            }
            abort_if($rows->isEmpty(), 404);
            // A finished worker with an uncertain provider result is not running work.
            // Preserve its unknown result/billing and retry fence, but permit a manual hold/cancellation.
            $productRuns = PuzzleGeneration::where('checkout_key', $key)->orderBy('id')->lockForUpdate()
                ->get(['id', 'status', 'requested_by', 'finished_at', 'lease_until']);
            if ($productRuns->contains(fn ($run) => $run->status === 'processing'
                || ($run->status === 'uncertain' && (! $run->finished_at || $run->lease_until))
                || (! $settling && $run->status === 'queued' && $run->requested_by))) {
                throw ValidationException::withMessages(['assignment' => 'توليد المنتج جارٍ أو لم يتأكد توقف العامل. انتظر انتهاء التنفيذ قبل الاستلام.']);
            }
            $uncertainProductIds = $productRuns->where('status', 'uncertain')->pluck('id')->all();
            if (CodexSceneRun::where('checkout_key', $key)->where('active_slot', 1)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'أوقف تجربة Codex من زر الإيقاف وانتظر تأكيد توقفها، ثم استلم الطلب.']);
            }
            if ($runs->contains(fn ($run) => in_array($run->status, $settling ? ['sending', 'uncertain'] : ['sending', 'uncertain', 'retry_queued', 'waiting_identity'], true) || $run->identities()->whereIn('status', ['processing', 'uncertain'])->exists())
                || $events->contains(fn ($event) => in_array($event->status, ['processing', 'unknown'], true))) {
                throw ValidationException::withMessages(['assignment' => 'هناك توليد أو إرسال جارٍ أو نتيجته غير مؤكدة. انتظر انتهاءه أو سوِّ المحاولة من الأوتوميشن قبل الاستلام؛ لم يتم تغيير المسؤولية.']);
            }
            if (! self::held($key)) {
                $savedRuns = [];
                foreach ($runs as $run) {
                    if (in_array($run->status, ['completed', 'cancelled'], true)) {
                        continue;
                    }
                    $savedRuns[$run->id] = $run->status;
                    $run->update(['status' => 'paused', 'version' => $run->version + 1]);
                }
                $savedEvents = [];
                foreach ($events as $event) {
                    if (in_array($event->status, ['pending', 'succeeded'], true)) {
                        $savedEvents[$event->id] = $event->status;
                        $event->update(['status' => 'held']);
                    }
                }
                DB::table('order_automation_holds')->insert(['checkout_key' => $key, 'user_id' => $actor->id, 'fingerprint' => $this->fingerprint($rows), 'runs' => json_encode($savedRuns), 'confirmations' => json_encode($savedEvents), 'created_at' => now(), 'updated_at' => now()]);
                AdminActivityLogger::log('order.automation_taken_over', 'استلام يدوي من Automation وإيقاف متابعة الأوتوميشن لهذه العملية.', $order, ['checkout_group_key' => $key, 'run_ids' => array_keys($savedRuns), 'uncertain_product_run_ids' => $uncertainProductIds], $actor, $request);
            }
            // Employee assignment is independent of automation execution ownership.
        }, 3);
    }

    public function resume(Order $order, User $actor, Request $request): void
    {
        $this->authorize($actor);
        abort_unless(app(OrderAccess::class)->controls($order, $actor), 403);
        DB::transaction(function () use ($order, $actor, $request) {
            $key = $order->checkoutGroupKey();
            $runs = OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $events = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $rows = Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            if (OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', ['processing', 'uncertain'])->exists()) {
                throw ValidationException::withMessages(['assignment' => 'إعادة توليد الهوية جارية أو نتيجتها غير مؤكدة؛ انتظر المراجعة.']);
            }
            $hold = DB::table('order_automation_holds')->where('checkout_key', $key)->lockForUpdate()->first();
            abort_unless($hold, 422, 'الطلب ليس متوقفًا للعمل اليدوي.');
            if (CodexSceneRun::where('checkout_key', $key)->where('active_slot', 1)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'انتظر انتهاء تجربة Codex قبل إعادة المسار السابق.']);
            }
            $changed = ! hash_equals($hold->fingerprint, $this->fingerprint($rows));
            $confirmedProducts = $rows->isNotEmpty()
                && $rows->every(fn ($row) => ! $row->story_id && in_array($row->status, ['new_confirmed', 'preview_uploaded', 'costomer_confirmed', 'pending_team_review', 'delivered'], true)
                    && $row->printing_status === 'not_started' && $row->shipping_status === 'not_ready'
                    && $row->items()->exists() && ! $row->items()->where('item_type', 'story')->exists());
            $productReturn = $confirmedProducts && $runs->isEmpty();
            $revisedArtwork = PuzzleGeneration::where('checkout_key', $key)->whereNotNull('requested_by')->where('created_at', '>=', $hold->created_at)->exists();
            $unstartedPreview = $runs->contains(fn ($run) => (json_decode($hold->runs, true)[$run->id] ?? null) === 'blocked' && ProductPreviewRecovery::untouched($run));
            $revisedPreview = ($changed || $revisedArtwork || $unstartedPreview) && $confirmedProducts && $runs->isNotEmpty();
            if (PuzzleGeneration::where('checkout_key', $key)->whereIn('status', ['processing', 'uncertain'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['assignment' => 'هناك توليد منتج جارٍ أو نتيجته غير مؤكدة؛ انتظر مراجعته قبل الإرجاع.']);
            }
            $approvedBooks = collect();
            $manifest = OrderReviewManifest::where('checkout_key', $key)->latest('id')->first();
            $savedRuns = json_decode($hold->runs, true);
            if ($manifest?->approved_at && ($savedRuns[$manifest->run_id] ?? null) === 'waiting_staff'
                && $rows->every(fn ($row) => ! $row->story_id && in_array($row->status, ['new_confirmed', 'costomer_confirmed', 'pending_team_review', 'delivered'], true)
                    && $row->printing_status === 'not_started' && $row->shipping_status === 'not_ready')
                && ! CodexSceneRun::where('checkout_key', $key)->exists()) {
                $items = $rows->flatMap(fn ($row) => $row->items()->get());
                if ($items->isNotEmpty() && $items->every(fn ($item) => in_array($item->item_type, ['product', 'product_add_on'], true)
                    && $item->productionComponents()->where('production_stage', 'book_interior')->exists())) {
                    try {
                        foreach ($items as $item) {
                            app(BookPageService::class)->validatePausedResume($item, $manifest);
                        }
                        $approvedBooks = $items;
                    } catch (ValidationException $error) {
                        if (! $revisedPreview) {
                            throw $error;
                        }
                        // A changed cover needs a fresh preview, never inherited consent.
                        // restart() still requires current completed artwork for every item.
                    }
                }
            }
            if ($changed && ! $productReturn && ! $revisedPreview && $approvedBooks->isEmpty()) {
                if ($rows->every(fn ($row) => ! $row->story_id) && $rows->contains('status', 'new')) {
                    throw ValidationException::withMessages(['assignment' => 'الطلب المعدّل غير مؤكد. بعد موافقة العميل على البيانات الجديدة، احفظ حالة «طلب جديد مؤكد» لكل عملية الشراء ثم أرجعه للأوتوميشن. البازل وكفر التلوين يبدأان قبل الدفع؛ صفحات التلوين تبدأ بعد اعتماد الكفر وتسجيل دفعة.']);
                }
                throw ValidationException::withMessages(['assignment' => 'تغيّرت بيانات الطلب أو هويته أثناء العمل اليدوي؛ لا يمكن استكمال مسار برسائل أو صور قديمة. أكمل يدويًا أو استخدم زر Codex بعد اعتماد الهوية وتسجيل الدفعة.']);
            }
            if ($approvedBooks->isNotEmpty()) {
                $revisedPreview = false;
            }
            if ($productReturn) {
                app(PuzzleAutomation::class)->refreshUnstartedForReturn($key);
            }
            if ($revisedPreview) {
                app(ProductPreviewReturn::class)->restart($rows, $runs, $hold, $actor->id);
            }
            foreach ($revisedPreview ? [] : json_decode($hold->runs, true) as $id => $status) {
                $run = $runs->get($id);
                abort_unless($run && $run->status === 'paused' && $run->automation->enabled, 422, 'المسار السابق غير متاح للاستكمال.');
                $run->update(['status' => $status, 'version' => $run->version + 1, 'next_at' => now(),
                    'wait_until' => $run->wait_until?->addSeconds(max(0, now()->timestamp - Carbon::parse($hold->created_at)->timestamp)),
                ]);
            }
            foreach (json_decode($hold->confirmations, true) as $id => $status) {
                $event = $events->get($id);
                abort_unless($event && $event->status === 'held', 422, 'تغيّرت محاولة رسالة التأكيد أثناء التوقف.');
                // A staff-confirmed revised checkout must never consume a reply to
                // the old order contents. Keep its original send receipt and attempts.
                $event->update(['status' => ($productReturn || $revisedPreview || $approvedBooks->isNotEmpty()) ? 'cancelled' : $status]);
                if (! $productReturn && ! $revisedPreview && $approvedBooks->isEmpty() && $status === 'pending') {
                    SendOrderConfirmationJob::dispatch($event->id)->afterCommit();
                }
            }
            DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
            if ($productReturn) {
                // Explicit new evaluation, not replay of a missed historical event.
                $event = OrderProcessEvent::create(['event_key' => 'manual-return:'.Str::uuid(),
                    'type' => 'automation_resumed', 'order_id' => $rows->first()->id, 'checkout_key' => $key,
                    'data' => ['status' => 'new_confirmed', 'actor_id' => $actor->id, 'changed' => $changed], 'status' => 'pending']);
                AutomationActivity::record($order, 'products_reevaluated', 'إعادة تقييم المنتجات بالبيانات الحالية بعد إرجاع الطلب من الموظف؛ دون إعادة الصور المكتملة أو رسالة التأكيد السابقة.',
                    ['event_id' => $event->id, 'requested_by' => $actor->id, 'changed' => $changed]);
            }
            foreach ($approvedBooks as $item) {
                OrderProcessEvent::create(['event_key' => 'book-resume:'.Str::uuid(), 'type' => 'book_pages_resume',
                    'order_id' => $item->order_id, 'checkout_key' => $key, 'status' => 'processed',
                    'data' => ['actor_id' => $actor->id, 'item_id' => $item->id, 'manifest_id' => $manifest->id]]);
            }
            AdminActivityLogger::log('order.automation_resumed', 'إعادة متابعة الطلب إلى Automation بطلب الموظف، دون إعادة الخطوات المكتملة.', $order, ['checkout_group_key' => $key], $actor, $request);
        }, 3);
    }
}
