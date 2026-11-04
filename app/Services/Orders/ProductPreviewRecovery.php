<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAutomationRun;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Models\User;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Explicit recovery of preview preflight only; never retry an accepted effect. */
class ProductPreviewRecovery
{
    public static function untouched(OrderProcessRun $run): bool
    {
        $first = $run->revision->definition['nodes'][0] ?? [];

        return in_array($run->status, ['blocked', 'paused'], true)
            && ($first['type'] ?? null) === 'generate_previews' && $run->node_id === ($first['id'] ?? null)
            && ! $run->steps()->exists()
            && ! OrderIdentityRegeneration::where('checkout_key', $run->checkout_key)->exists()
            && ! OrderAutomationRun::whereIn('order_id', Order::where('checkout_group_key', $run->checkout_key)->select('id'))->where('dry_run', false)->exists()
            && ! Order::where('checkout_group_key', $run->checkout_key)->whereNotNull('story_id')->exists()
            && ! PuzzleGeneration::where('checkout_key', $run->checkout_key)->exists()
            && ! CodexSceneRun::where('checkout_key', $run->checkout_key)->exists()
            && ! OrderReviewManifest::where('checkout_key', $run->checkout_key)->exists()
            && ! OrderProcessIdentity::whereIn('run_id', OrderProcessRun::where('checkout_key', $run->checkout_key)->select('id'))->exists();
    }

    public function recheck(Order $order, User $actor, Request $request, int $version, int $runId, int $runVersion): void
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor)
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
        DB::transaction(function () use ($order, $actor, $request, $version, $runId, $runVersion) {
            $key = $order->checkoutGroupKey();
            DB::table('order_automation_controls')->insertOrIgnore(['checkout_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
            $control = DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            abort_unless(($control->version ?? 1) === $version && in_array($control->state ?? 'running', ['running', 'paused'], true), 409, 'تغير وضع التنفيذ؛ حدّث الصفحة قبل الفحص.');
            $run = OrderProcessRun::where('checkout_key', $key)->lockForUpdate()->findOrFail($runId);
            abort_unless($run->version === $runVersion && self::untouched($run), 409, 'تغير المسار أو بدأ تنفيذ بالفعل. حدّث الصفحة واتبع الإجراء الظاهر؛ لم يُكرر أي توليد أو إرسال.');
            if (! config('product_review.enabled') || ! app(PuzzleAutomation::class)->enabled()) {
                throw ValidationException::withMessages(['automation' => OrderAutomationGuidance::reason('preview_disabled')]);
            }
            $totalCost = 0;
            $rows = Order::where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            foreach ($rows as $row) {
                foreach ($row->items()->get() as $item) {
                    try {
                        $context = app(PuzzleInputs::class)->captureCurrent($item);
                        $totalCost += $context['estimate_usd'];
                        if ($row->attachments()->where(fn ($q) => $q->where('production_unit_key', $context['unit_key'])->orWhereNull('production_unit_key'))->exists()) {
                            throw new \RuntimeException('preview_existing_attachment');
                        }
                    } catch (Throwable $error) {
                        throw ValidationException::withMessages(['automation' => 'المنتج #'.$item->id.': '.($error->getMessage() === 'preview_existing_attachment' ? OrderAutomationGuidance::reason('preview_existing_attachment') : (PuzzleGeneration::ERRORS[$error->getMessage()] ?? 'تعذر فحص بيانات المنتج أو الوصول للصور. راجع بياناته والتخزين ثم أعد الفحص.'))]);
                    }
                }
            }
            if ($totalCost > (float) data_get($run->revision->definition, 'nodes.0.config.budget_usd', 0)) {
                throw ValidationException::withMessages(['automation' => OrderAutomationGuidance::reason('preview_budget_exceeded')]);
            }
            if (! AutomationHandoff::held($key)) {
                app(AutomationHandoff::class)->take($order, $actor, $request);
            }
            if (($control->state ?? 'running') === 'paused') {
                app(AutomationControl::class)->resume($order, $actor, $request, $version);
            } else {
                app(AutomationHandoff::class)->resume($order, $actor, $request);
            }
            AdminActivityLogger::log('order.preview_preflight_rechecked', 'فحص بيانات المنتجات واستكمال مسار لم يبدأ توليده؛ لا إعادة لرسالة تأكيد الطلب.', $order, ['previous_run_id' => $runId], $actor, $request);
        }, 3);
    }
}
