<?php

namespace App\Services\Orders;

use App\Jobs\GenerateProcessIdentity;
use App\Jobs\RegenerateOrderIdentity;
use App\Jobs\RetryOrderProcessMessage;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAutomationRun;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\PuzzleGeneration;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Services\CodexScenes\ManualSceneService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Durable admission fence. A pause never revokes a claimed provider operation. */
class AutomationControl
{
    public static function blocked(string $key): bool
    {
        return DB::table('order_automation_controls')->where('checkout_key', $key)->where('state', '!=', 'running')->exists();
    }

    /** Call within the transaction that durably claims the next step. */
    public static function admits(string $key, bool $manual = false): bool
    {
        DB::table('order_automation_controls')->insertOrIgnore(['checkout_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
        $state = DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->value('state');

        return $state === 'running' || ($manual && $state === 'paused');
    }

    public function pause(Order $order, User $actor, Request $request, int $version, string $reason): void
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor), 403);
        DB::transaction(function () use ($order, $actor, $request, $version, $reason) {
            $key = $order->checkoutGroupKey();
            DB::table('order_automation_controls')->insertOrIgnore(['checkout_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            $control = DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            abort_unless($control->version === $version, 409, 'تغير وضع التنفيذ؛ حدّث الصفحة.');
            abort_unless($control->state === 'running', 409, 'طلب الإيقاف مسجل بالفعل.');
            DB::table('order_automation_controls')->where('checkout_key', $key)->update(['state' => 'pause_requested', 'version' => $version + 1,
                'requested_by' => $actor->id, 'reason' => $reason, 'requested_at' => now(), 'updated_at' => now()]);
            AdminActivityLogger::log('order.automation_pause_requested', 'طلب إيقاف آمن بعد الخطوة الجارية.', $order, ['reason' => $reason, 'version' => $version + 1], $actor, $request);
        }, 3);
        $this->settle($order->checkoutGroupKey());
    }

    public function settle(string $key): void
    {
        $control = DB::table('order_automation_controls')->where('checkout_key', $key)->first();
        if (! $control || ! in_array($control->state, ['pause_requested', 'pause_uncertain'], true)) {
            return;
        }
        // Codex writes a checkpoint only at scene boundaries. collect() persists its
        // completed output to S3 before releasing active_slot.
        foreach (CodexSceneRun::where('checkout_key', $key)->where('active_slot', 1)->get() as $run) {
            app(ManualSceneService::class)->requestPause($run);
        }
        $legacyBusy = OrderAutomationRun::whereIn('order_id', Order::where('checkout_group_key', $key)->select('id'))->where('dry_run', false)->where(fn ($q) => $q->whereIn('status', ['generating', 'polling', 'uncertain'])->orWhereNotNull('lease_until'))->exists();
        $busy = $legacyBusy || CodexSceneRun::where('checkout_key', $key)->where('active_slot', 1)->exists()
            || PuzzleGeneration::where('checkout_key', $key)->where(fn ($q) => $q->where('status', 'processing')->orWhereNotNull('lease_until'))->exists()
            || OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', ['processing', 'uncertain'])->exists()
            || OrderProcessRun::where('checkout_key', $key)->where(fn ($q) => $q->whereIn('status', ['sending', 'uncertain'])->orWhereHas('identities', fn ($q) => $q->whereIn('status', ['processing', 'uncertain'])))->exists()
            || RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->whereIn('status', ['processing', 'unknown'])->exists();
        if ($busy) {
            // Time alone never proves a worker stopped. Keep the admission fence.
            if (now()->diffInSeconds(Carbon::parse($control->requested_at), true) > 1800) {
                DB::table('order_automation_controls')->where('checkout_key', $key)->where('state', 'pause_requested')->update(['state' => 'pause_uncertain', 'error_code' => 'worker_stop_unconfirmed', 'updated_at' => now()]);
            }

            return;
        }
        $order = Order::where('checkout_group_key', $key)->first();
        $actor = User::find($control->requested_by);
        if (! $order || ! $actor) {
            return;
        }
        try {
            // Uses the original actor for audit, but settling an already authorized
            // request must not depend on assignment/permission changes while it runs.
            app(AutomationHandoff::class)->take($order, $actor, Request::create('/'), true);
            $updated = DB::table('order_automation_controls')->where('checkout_key', $key)->whereIn('state', ['pause_requested', 'pause_uncertain'])
                ->update(['state' => 'paused', 'paused_at' => now(), 'error_code' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            if ($updated) {
                AutomationActivity::record($order, 'automation_paused', 'تأكد الإيقاف الآمن؛ النتائج محفوظة والمسؤول لم يتغير.', ['requested_by' => $actor->id, 'reason' => $control->reason]);
            }
        } catch (ValidationException $e) {
            // Preserve safety on unresolved provider outcomes; no automatic retries.
            DB::table('order_automation_controls')->where('checkout_key', $key)->update(['state' => 'pause_uncertain', 'error_code' => 'settlement_requires_review', 'updated_at' => now()]);
        }
    }

    public function resume(Order $order, User $actor, Request $request, int $version): void
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor), 403);
        DB::transaction(function () use ($order, $actor, $request, $version) {
            $control = DB::table('order_automation_controls')->where('checkout_key', $order->checkoutGroupKey())->lockForUpdate()->first();
            abort_unless($control && $control->state === 'paused' && $control->version === $version, 409, 'لم يتأكد التوقف أو تغيرت نسخة التنفيذ.');
            app(AutomationHandoff::class)->resume($order, $actor, $request);
            foreach (CodexSceneRun::where('checkout_key', $order->checkoutGroupKey())->where('status', 'paused')->get() as $run) {
                app(ManualSceneService::class)->resumePaused($run);
            }
            foreach (OrderProcessIdentity::whereIn('run_id', OrderProcessRun::where('checkout_key', $order->checkoutGroupKey())->select('id'))->where('status', 'queued')->get() as $task) {
                $task->touch();
                GenerateProcessIdentity::dispatch($task->id)->afterCommit();
            }
            foreach (OrderIdentityRegeneration::where('checkout_key', $order->checkoutGroupKey())->where('status', 'queued')->get() as $task) {
                RegenerateOrderIdentity::dispatch($task->id)->afterCommit();
            }
            foreach (OrderProcessRun::where('checkout_key', $order->checkoutGroupKey())->where('status', 'retry_queued')->get() as $run) {
                foreach ($run->steps()->where('status', 'retry_pending')->get() as $step) {
                    $attempt = $step->messageAttempts()->where('status', 'pending')->latest('number')->first();
                    if ($attempt) {
                        RetryOrderProcessMessage::dispatch($run->id, $step->id, $attempt->id, $run->version)->afterCommit();
                    }
                }
            }
            DB::table('order_automation_controls')->where('checkout_key', $order->checkoutGroupKey())->update(['state' => 'running', 'version' => $version + 1, 'error_code' => null, 'updated_at' => now()]);
        }, 3);
    }
}
