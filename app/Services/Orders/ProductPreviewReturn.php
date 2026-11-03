<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\OrderProcessEvent;
use App\Models\OrderProcessRun;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\OrderAutomation\Process\ProcessTemplates;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\ProductAutomation\PuzzleInputs;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** An explicit staff return starts a new preview version, never replays the old wait. */
class ProductPreviewReturn
{
    public function restart(Collection $rows, Collection $runs, object $hold, int $actorId): OrderProcessRun
    {
        $key = $rows->first()->checkoutGroupKey();
        $saved = json_decode($hold->runs, true);
        $current = $runs->whereNotIn('status', ['cancelled', 'completed']);
        $previous = $current->sortByDesc('id')->first();
        $deny = fn (string $message) => throw ValidationException::withMessages(['assignment' => $message]);
        if (! $previous || $current->count() !== 1 || ! array_key_exists($previous->id, $saved)
            || $previous->status !== 'paused' || ! $previous->automation->enabled
            || CodexSceneRun::where('checkout_key', $key)->exists()) {
            $deny('المسار السابق يحتاج مراجعة قبل بدء معاينة معدّلة؛ لم تتم إعادة أي توليد أو إرسال.');
        }
        $definition = $previous->revision->definition;
        // Only the product preview workflow. Never restart order confirmation or a story.
        if (($definition['nodes'][0]['type'] ?? null) !== 'generate_previews'
            || collect($definition['nodes'])->where('type', 'whatsapp')->contains(fn ($node) => data_get($node, 'config.template') !== ProcessTemplates::REVIEW)
            || $previous->steps()->whereIn('status', ['processing', 'uncertain', 'retry_pending'])->exists()
            || $previous->steps()->whereHas('messageAttempts', fn ($q) => $q->whereIn('status', ['processing', 'uncertain', 'pending']))->exists()) {
            $deny('يوجد إرسال غير محسوم أو مسار غير متوافق؛ راجعه قبل بدء معاينة معدّلة.');
        }
        $policy = app(ProcessLivePolicy::class);
        $authority = clone $previous->automation;
        $authority->setRelation('revision', $previous->revision);
        $authority->process_activated_by = $previous->context['actor_id'];
        if (! $policy->enabled() || ! $policy->allowsAutomation($previous->automation_id)
            || ! $policy->allowsCheckout($key, true) || ! $policy->authorized($authority)) {
            $deny('المسار السابق غير مفعّل أو صلاحية تنفيذه غير متاحة.');
        }
        $policy->check($definition);
        $contexts = app(ProcessOrderContext::class);
        $snapshot = $contexts->snapshot($contexts->orders($key, true));
        if (! $contexts->matches($definition, $snapshot)) {
            $deny('أكد بيانات جميع منتجات الطلب المعدّل قبل الإرجاع للأوتوميشن.');
        }
        // Validate all newly added inputs before retiring the old path. Completed
        // outputs must still match their original inputs; regeneration is explicit.
        try {
            foreach ($rows as $row) {
                foreach ($row->items()->get() as $item) {
                    app(PuzzleInputs::class)->capture($item);
                }
            }
        } catch (\Throwable) {
            $deny('راجع صور وبرومبتات المنتجات المضافة؛ بعض البيانات غير مكتملة أو غير مدعومة.');
        }
        app(PuzzleAutomation::class)->refreshUnstartedForReturn($key);
        $event = OrderProcessEvent::create(['event_key' => 'preview-return:'.Str::uuid(), 'type' => 'preview_revised',
            'checkout_key' => $key, 'order_id' => $rows->first()->id, 'status' => 'processed',
            'data' => ['requested_by' => $actorId, 'previous_run_id' => $previous->id]]);
        $previous->update(['status' => 'cancelled', 'active_key' => null, 'next_at' => null, 'version' => $previous->version + 1]);
        $run = OrderProcessRun::create(['automation_id' => $previous->automation_id, 'revision_id' => $previous->revision_id,
            'event_id' => $event->id, 'order_id' => $rows->first()->id, 'checkout_key' => $key,
            'account_key' => $previous->account_key, 'phone_hash' => hash('sha256', '+'.$snapshot['phone']),
            'enrollment_key' => hash('sha256', 'preview-return:'.$event->id), 'status' => 'queued',
            'node_id' => $definition['nodes'][0]['id'], 'next_at' => now(),
            'context' => $snapshot + ['actor_id' => $previous->context['actor_id'], 'supersedes_run_id' => $previous->id]]);
        AutomationActivity::record($rows->first(), 'product_preview_revised',
            'بدء نسخة معاينة جديدة للمنتجات المؤكدة بطلب الموظف؛ حفظ الصور السابقة ومنع اعتماد الردود على النسخة القديمة.',
            ['previous_run_id' => $previous->id, 'run_id' => $run->id, 'requested_by' => $actorId]);

        return $run;
    }
}
