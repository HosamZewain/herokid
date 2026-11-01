<?php

namespace App\Services\Orders;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderProcessRun;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        abort_unless($actor->is_active && ! $actor->agent_api_enabled && $actor->hasPermission('orders.assignment.manage') && $actor->hasPermission('orders.update'), 403);
    }

    public function take(Order $order, User $actor, Request $request): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($order, $actor, $request) {
            $key = $order->checkoutGroupKey();
            // Match worker lock order: run/event before checkout rows.
            $runs = OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            $events = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->orderBy('id')->lockForUpdate()->get();
            $rows = Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            abort_if($rows->isEmpty(), 404);
            if (PuzzleGeneration::where('checkout_key', $key)->whereIn('status', ['processing', 'uncertain'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['assignment' => 'توليد البازل جارٍ أو نتيجته غير مؤكدة. انتظر اكتماله أو مراجعة المحاولة قبل الاستلام.']);
            }
            if (CodexSceneRun::where('checkout_key', $key)->where('active_slot', 1)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'أوقف تجربة Codex من زر الإيقاف وانتظر تأكيد توقفها، ثم استلم الطلب.']);
            }
            if ($runs->contains(fn ($run) => in_array($run->status, ['sending', 'uncertain', 'retry_queued', 'waiting_identity'], true) || $run->identities()->whereIn('status', ['processing', 'uncertain'])->exists())
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
                AdminActivityLogger::log('order.automation_taken_over', 'استلام يدوي من Automation وإيقاف متابعة الأوتوميشن لهذه العملية.', $order, ['checkout_group_key' => $key, 'run_ids' => array_keys($savedRuns)], $actor, $request);
            }
            app(OrderAssignmentService::class)->acquire($order, $actor, $request, true);
        }, 3);
    }

    public function resume(Order $order, User $actor, Request $request): void
    {
        $this->authorize($actor);
        DB::transaction(function () use ($order, $actor, $request) {
            $key = $order->checkoutGroupKey();
            $runs = OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $events = RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $rows = Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            $hold = DB::table('order_automation_holds')->where('checkout_key', $key)->lockForUpdate()->first();
            abort_unless($hold, 422, 'الطلب ليس متوقفًا للعمل اليدوي.');
            if (CodexSceneRun::where('checkout_key', $key)->where('active_slot', 1)->exists()) {
                throw ValidationException::withMessages(['assignment' => 'انتظر انتهاء تجربة Codex قبل إعادة المسار السابق.']);
            }
            if (! hash_equals($hold->fingerprint, $this->fingerprint($rows))) {
                throw ValidationException::withMessages(['assignment' => 'تغيّرت بيانات الطلب أو هويته أثناء العمل اليدوي؛ لا يمكن استكمال مسار برسائل أو صور قديمة. أكمل يدويًا أو استخدم زر Codex بعد اعتماد الهوية وتسجيل الدفعة.']);
            }
            foreach (json_decode($hold->runs, true) as $id => $status) {
                $run = $runs->get($id);
                abort_unless($run && $run->status === 'paused' && $run->automation->enabled, 422, 'المسار السابق غير متاح للاستكمال.');
                $run->update(['status' => $status, 'version' => $run->version + 1, 'next_at' => now(),
                    'wait_until' => $run->wait_until?->addSeconds(max(0, now()->timestamp - Carbon::parse($hold->created_at)->timestamp)),
                ]);
            }
            foreach (json_decode($hold->confirmations, true) as $id => $status) {
                $event = $events->get($id);
                abort_unless($event && $event->status === 'held', 422, 'تغيّرت محاولة رسالة التأكيد أثناء التوقف.');
                $event->update(['status' => $status]);
                if ($status === 'pending') {
                    SendOrderConfirmationJob::dispatch($event->id)->afterCommit();
                }
            }
            DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
            AdminActivityLogger::log('order.automation_resumed', 'إعادة متابعة الطلب إلى Automation بطلب الموظف، دون إعادة الخطوات المكتملة.', $order, ['checkout_group_key' => $key], $actor, $request);
        }, 3);
    }
}
