<?php

namespace App\Services\Orders;

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
use App\Services\OrderAutomation\Process\ProcessIdentityService;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Explicit staff consent for an exact replacement identity; old sends and previews stay immutable. */
final class ApprovedIdentityRecovery
{
    private function deny(string $message): never
    {
        throw ValidationException::withMessages(['identity_recovery' => $message]);
    }

    public function authorize(Order $order, User $actor): void
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor)
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
    }

    /** Only a display hint; plan() checks the full checkout and current bytes again. */
    public function candidate(Order $order): bool
    {
        $key = $order->checkoutGroupKey();
        if (! DB::table('order_automation_controls')->where('checkout_key', $key)->where('state', 'paused')->exists()) {
            return false;
        }
        $manifest = OrderReviewManifest::where('checkout_key', $key)->latest('id')->with('run.identities')->first();
        if (! $manifest || $manifest->approved_at || $manifest->run?->status !== 'paused') {
            return false;
        }
        $approved = Order::where('checkout_group_key', $key)->whereNotNull('story_id')->pluck('child_identity_approved_attempt_id', 'id');

        return $manifest->run->identities->contains(fn ($task) => $approved->get($task->order_id)
            && $approved->get($task->order_id) !== $task->attempt_id);
    }

    public function plan(Order $order): array
    {
        $key = $order->checkoutGroupKey();
        $control = DB::table('order_automation_controls')->where('checkout_key', $key)->first();
        $hold = DB::table('order_automation_holds')->where('checkout_key', $key)->first();
        $open = OrderProcessRun::where('checkout_key', $key)->whereNotIn('status', ['cancelled', 'completed'])->with(['identities.attempt', 'revision', 'automation'])->get();
        $previous = $open->first();
        $manifest = OrderReviewManifest::where('checkout_key', $key)->latest('id')->first();
        if ($control?->state !== 'paused' || ! $hold || $open->count() !== 1 || $previous?->status !== 'paused'
            || ! array_key_exists($previous->id, json_decode($hold->runs, true) ?: [])
            || ! $manifest || $manifest->run_id !== $previous->id || $manifest->approved_at) {
            $this->deny('يلزم إيقاف مؤكد ومعاينة سابقة غير معتمدة لربط الهوية البديلة. راجع حالة الطلب الحالية.');
        }
        $nodes = collect($previous->revision->definition['nodes']);
        $approvalNode = $nodes->firstWhere('type', 'approve_previews');
        $responseNode = $nodes->firstWhere('id', $approvalNode['config']['response_node'] ?? '');
        $reviewNode = $nodes->firstWhere('id', $approvalNode['next'] ?? '');
        if (($nodes->first()['type'] ?? '') !== 'generate_previews' || ($responseNode['type'] ?? '') !== 'wait_response'
            || ($reviewNode['type'] ?? '') !== 'manual_review' || ! $previous->steps()->where('node_id', $responseNode['id'])->exists()) {
            $this->deny('مسار المعاينة الحالي لا يدعم ربط الهوية البديلة؛ راجعه قبل الاستكمال.');
        }
        $policy = app(ProcessLivePolicy::class);
        $authority = clone $previous->automation;
        $authority->setRelation('revision', $previous->revision);
        $authority->process_activated_by = $previous->context['actor_id'];
        if (! $authority->enabled || ! $policy->enabled() || ! $policy->allowsAutomation($previous->automation_id)
            || ! $policy->allowsCheckout($key, true) || ! $policy->authorized($authority)) {
            $this->deny('إعداد أو صلاحية مسار المعاينة غير متاحين حاليًا؛ راجع المسؤول.');
        }
        $policy->check($previous->revision->definition);
        $contexts = app(ProcessOrderContext::class);
        $rows = $contexts->forRun($previous)->load(['story', 'childIdentityRequest', 'childIdentityApprovedAttempt']);
        if ($rows->contains(fn ($row) => $row->trashed() || ! in_array($row->status, ['new_confirmed', 'preview_uploaded', 'costomer_confirmed', 'pending_team_review'], true)
                || $row->printing_status !== 'not_started' || $row->shipping_status !== 'not_ready')
            || CodexSceneRun::where('checkout_key', $key)->exists()
            || OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', ['queued', 'processing', 'uncertain'])->exists()
            || PuzzleGeneration::where('checkout_key', $key)->where(fn ($q) => $q->whereIn('status', ['queued', 'processing', 'uncertain'])->orWhereNotNull('lease_until'))->exists()
            || OrderAutomationRun::whereIn('order_id', $rows->modelKeys())->where('dry_run', false)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()
            || RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->whereIn('status', ['pending', 'processing', 'unknown'])->exists()
            || $previous->steps()->whereIn('status', ['processing', 'uncertain', 'retry_pending'])->exists()
            || $previous->steps()->whereHas('messageAttempts', fn ($q) => $q->whereIn('status', ['pending', 'processing', 'uncertain']))->exists()
            || $previous->identities->contains(fn ($task) => $task->status !== 'succeeded' || $task->lease_until)) {
            $this->deny('يوجد تنفيذ أو إرسال غير محسوم أو إنتاج سابق؛ لا يمكن تغيير مرجع الهوية قبل مراجعة نتيجته.');
        }
        $tasks = $previous->identities->keyBy('order_id');
        $stories = $rows->whereNotNull('story_id');
        if ($stories->isEmpty() || $tasks->count() !== $stories->count()
            || array_diff($tasks->keys()->all(), $stories->modelKeys())) {
            $this->deny('تغيّرت قصص أو وحدات الطلب؛ يلزم تجهيز معاينة جديدة للبيانات الحالية.');
        }
        $identityService = app(ProcessIdentityService::class);
        $images = $manifest->images;
        $captured = [];
        $attempts = [];
        $replaced = [];
        foreach ($stories as $story) {
            $task = $tasks[$story->id];
            $attempt = $story->childIdentityApprovedAttempt ?? $task->attempt;
            if (! $attempt || $attempt->status !== 'succeeded' || ! $attempt->output_storage_path || ! $attempt->output_checksum) {
                $this->deny('الهوية الحالية غير مكتملة أو غير معتمدة؛ راجع صورة هذه القصة أولًا.');
            }
            $input = $story->child_identity_approved_attempt_id
                ? $identityService->approvedInputs($story, $attempt)
                : app(WorkflowInputs::class)->capture($story, $identityService->inputDefinition());
            if (! hash_equals($task->context['fingerprint'], $input['fingerprint'])) {
                $this->deny('تغيّرت القصة أو بيانات الطفل أو صوره أو تعليمات الهوية؛ يلزم معاينة جديدة لهذه البيانات.');
            }
            $matches = array_keys(array_filter($images, fn ($image) => ($image['kind'] ?? '') === 'identity' && ($image['order_id'] ?? null) === $story->id));
            if (count($matches) !== 1 || ($images[$matches[0]]['attempt_id'] ?? null) !== $task->attempt_id) {
                $this->deny('مرجع الهوية في المعاينة السابقة غير متطابق؛ راجعه قبل الاستكمال.');
            }
            $image = array_replace($images[$matches[0]], ['attempt_id' => $attempt->id, 'disk' => $attempt->output_disk,
                'path' => $attempt->output_storage_path, 'sha256' => $attempt->output_checksum]);
            app(ProcessProductReview::class)->bytes($image);
            $images[$matches[0]] = $image;
            $captured[$story->id] = $input;
            $attempts[$story->id] = $attempt;
            if ($attempt->id !== $task->attempt_id) {
                $replaced[$story->id] = ['order' => $story, 'previous_attempt_id' => $task->attempt_id, 'attempt' => $attempt];
            }
        }
        if (! $replaced) {
            $this->deny('المعاينة مرتبطة بالهوية الحالية بالفعل؛ راجع موافقتها والتقدم من صفحة الطلب.');
        }
        // Prove the only edited order facts are the replacement approval links.
        // Never overwrite a stale fingerprint to admit other child/story/contact edits.
        $comparison = $rows->map(function ($row) use ($previous, $tasks, $replaced) {
            $copy = clone $row;
            $copy->status = $previous->context['order_statuses'][$row->id] ?? $previous->context['status'];
            if (isset($replaced[$row->id])) {
                $copy->child_identity_approved_attempt_id = null;
                $copy->child_identity_request_id = $tasks[$row->id]->attempt->child_identity_request_id;
            }

            return $copy;
        });
        $proof = $contexts->snapshot($comparison, (int) ($previous->context['fingerprint_version'] ?? 1));
        if (! hash_equals($previous->context['fingerprint'], $proof['fingerprint'])) {
            $this->deny('تغيّرت بيانات أخرى بعد المعاينة؛ هذا الإجراء يستبدل الهوية فقط ولا يعتمد بيانات أو منتجات جديدة.');
        }
        $inputs = app(StaffPreviewInputs::class)->capture($manifest);
        foreach ($images as $image) {
            app(ProcessProductReview::class)->bytes($image);
        }
        $snapshot = $contexts->snapshot($rows);
        $replacementIds = array_map(fn ($replacement) => ['previous_attempt_id' => $replacement['previous_attempt_id'], 'attempt_id' => $replacement['attempt']->id], $replaced);
        $fingerprint = hash('sha256', json_encode(['run' => [$previous->id, $previous->version], 'control' => $control->version,
            'hold' => [$hold->fingerprint, $hold->created_at], 'manifest' => [$manifest->id, $manifest->fingerprint],
            'snapshot' => $snapshot['fingerprint'], 'images' => $images, 'inputs' => $inputs, 'replacements' => $replacementIds], JSON_THROW_ON_ERROR));

        return compact('previous', 'control', 'manifest', 'rows', 'images', 'captured', 'attempts', 'replaced', 'inputs', 'snapshot', 'fingerprint', 'approvalNode', 'responseNode', 'reviewNode');
    }

    public function token(array $plan): string
    {
        return Crypt::encryptString(json_encode(['purpose' => 'approved_identity_recovery', 'fingerprint' => $plan['fingerprint']], JSON_THROW_ON_ERROR));
    }

    public function approve(Order $order, User $actor, Request $request, string $token): OrderProcessRun
    {
        $this->authorize($order, $actor);
        try {
            $selected = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'تغير اختيار الهوية؛ حدّث الصفحة وراجع الصورة الحالية.');
        }

        return DB::transaction(function () use ($order, $actor, $request, $selected) {
            $key = $order->checkoutGroupKey();
            OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            Order::withTrashed()->where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            OrderReviewManifest::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            $this->authorize($order->fresh(), $actor->fresh());
            $plan = $this->plan($order);
            abort_unless(($selected['purpose'] ?? null) === 'approved_identity_recovery'
                && hash_equals($plan['fingerprint'], $selected['fingerprint'] ?? ''), 409, 'تغيّرت الهوية أو المعاينة أو حالة التنفيذ؛ حدّث الصفحة.');
            $previous = $plan['previous'];
            $event = OrderProcessEvent::create(['event_key' => 'approved-identity-recovery:'.Str::uuid(), 'type' => 'approved_identity_recovered',
                'checkout_key' => $key, 'order_id' => $order->id, 'status' => 'processed', 'data' => ['source_run_id' => $previous->id, 'requested_by' => $actor->id]]);
            $previous->update(['status' => 'cancelled', 'active_key' => null, 'version' => $previous->version + 1, 'next_at' => null, 'wait_until' => null]);
            $context = $plan['snapshot'] + app(ProcessOrderContext::class)->metadata($previous);
            $context['supersedes_run_id'] = $previous->id;
            $context['identity_recovery'] = ['source_run_id' => $previous->id, 'source_manifest_id' => $plan['manifest']->id,
                'attempts' => array_map(fn ($attempt) => $attempt->id, $plan['attempts'])];
            $run = OrderProcessRun::create($previous->only(['automation_id', 'revision_id', 'account_key', 'phone_hash']) + [
                'checkout_key' => $key, 'order_id' => $order->id, 'event_id' => $event->id,
                'enrollment_key' => hash('sha256', 'approved-identity-recovery:'.$event->id), 'status' => 'paused',
                'node_id' => $plan['reviewNode']['id'], 'version' => 1, 'context' => $context]);
            $generate = $run->steps()->create(['node_id' => $previous->revision->definition['nodes'][0]['id'], 'type' => 'generate_previews', 'status' => 'completed', 'completed_at' => now()]);
            foreach ($plan['attempts'] as $orderId => $attempt) {
                $run->identities()->create(['step_id' => $generate->id, 'order_id' => $orderId, 'attempt_id' => $attempt->id,
                    'status' => 'succeeded', 'context' => $plan['captured'][$orderId]]);
            }
            // No send receipt or customer response is invented. Staff consent below
            // settles this wait explicitly for the exact newly built manifest.
            $run->steps()->create(['node_id' => $plan['responseNode']['id'], 'type' => 'wait_response', 'status' => 'waiting']);
            $manifest = OrderReviewManifest::create(['checkout_key' => $key, 'run_id' => $run->id, 'images' => $plan['images'],
                'fingerprint' => hash('sha256', json_encode($plan['images'], JSON_THROW_ON_ERROR))]);
            app(StaffPreviewApproval::class)->approve($order->fresh(), $actor, $request, ['manifest_id' => $manifest->id,
                'fingerprint' => $manifest->fingerprint, 'inputs_fingerprint' => app(StaffPreviewInputs::class)->fingerprint($plan['inputs']),
                'run_version' => $run->version, 'control_version' => $plan['control']->version, 'channel' => 'staff',
                'note' => 'سجّل الموظف موافقة العميل على الهوية البديلة المعروضة مع حفظ المعاينة والمحاولة السابقتين.']);
            $audit = AdminActivityLogger::log('order.approved_identity_recovered', 'ربط الهوية البديلة المعتمدة بمعاينة حالية واستكمال المتبقي المؤهل دون إعادة توليد الهوية أو إرسال المعاينة.', $order,
                ['source_run_id' => $previous->id, 'run_id' => $run->id, 'source_manifest_id' => $plan['manifest']->id, 'manifest_id' => $manifest->id,
                    'replaced_order_ids' => array_keys($plan['replaced']), 'attempts' => $context['identity_recovery']['attempts']], $actor, $request);
            if (! $audit) {
                throw new RuntimeException('identity_recovery_audit_required');
            }

            return $run->fresh();
        });
    }
}
