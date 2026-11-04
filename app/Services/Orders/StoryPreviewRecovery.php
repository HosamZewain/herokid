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
use App\Services\OrderAutomation\Process\ProcessTemplates;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\ProductAutomation\PuzzleAutomation;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** A reviewed replacement starts a fresh preview, never resumes the old customer wait. */
class StoryPreviewRecovery
{
    private function deny(string $message): never
    {
        throw ValidationException::withMessages(['story_recovery' => $message]);
    }

    public function authorize(Order $order, User $actor): void
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor) && $actor->hasPermission('orders.update')
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
    }

    /** Cheap eligibility for the order detail only; the full plan is explicit. */
    public function candidate(Order $order): bool
    {
        $key = $order->checkoutGroupKey();
        $run = OrderProcessRun::where('checkout_key', $key)->where('status', 'paused')->latest('id')->first();
        $stories = Order::where('checkout_group_key', $key)->whereNotNull('story_id')->get(['id', 'story_id']);
        $originals = $run?->identities->keyBy('order_id') ?? collect();

        return $run && ($run->revision->definition['nodes'][0]['type'] ?? '') === 'generate_previews'
            && DB::table('order_automation_controls')->where('checkout_key', $key)->where('state', 'paused')->exists()
            && $stories->isNotEmpty() && ($stories->contains(fn ($row) => ! in_array($row->id, $run->context['order_ids'], true)
                || ! in_array($row->story_id, $run->context['story_ids'], true)
                || ($originals->has($row->id) && data_get($originals[$row->id]->context, 'story_id') !== $row->story_id))
                || Order::onlyTrashed()->where('checkout_group_key', $key)->whereIn('id', $run->context['order_ids'])->whereNotNull('story_id')->exists());
    }

    public function plan(Order $order): array
    {
        $key = $order->checkoutGroupKey();
        $control = DB::table('order_automation_controls')->where('checkout_key', $key)->first();
        $hold = DB::table('order_automation_holds')->where('checkout_key', $key)->first();
        $open = OrderProcessRun::where('checkout_key', $key)->whereNotIn('status', ['cancelled', 'completed'])->get();
        $previous = $open->first();
        $manifest = OrderReviewManifest::where('checkout_key', $key)->latest('id')->first();
        if (! $this->candidate($order) || ! $hold || $control?->state !== 'paused' || $open->count() !== 1
            || $previous->status !== 'paused' || ! array_key_exists($previous->id, json_decode($hold->runs, true))
            || ! $manifest || $manifest->run_id !== $previous->id || $manifest->approved_at) {
            $this->deny('هذا الإجراء لاستبدال قصة بعد المعاينة وقبل اعتمادها أو توليد صفحاتها. أوقف التنفيذ بأمان وراجع المسار الحالي.');
        }
        $definition = $previous->revision->definition;
        $policy = app(ProcessLivePolicy::class);
        $authority = clone $previous->automation;
        $authority->setRelation('revision', $previous->revision);
        $authority->process_activated_by = $previous->context['actor_id'];
        if (! $authority->enabled || ! $policy->enabled() || ! $policy->allowsAutomation($previous->automation_id)
            || ! $policy->allowsCheckout($key, true) || ! $policy->authorized($authority)
            || collect($definition['nodes'])->where('type', 'whatsapp')->contains(fn ($n) => data_get($n, 'config.template') !== ProcessTemplates::REVIEW)) {
            $this->deny('إعداد أو صلاحية مسار المعاينة غير متاحين للقصة الجديدة؛ يراجعهما المدير قبل التشغيل.');
        }
        $policy->check($definition);
        $all = Order::withTrashed()->where('checkout_group_key', $key)->orderBy('id')->with('items', 'story')->get();
        $rows = $all->reject(fn ($row) => $row->trashed())->values();
        $retired = $all->filter(fn ($row) => $row->trashed())->modelKeys();
        $known = array_merge($previous->context['order_ids'], data_get($previous->context, 'story_recovery.retired_order_ids', []));
        if ($all->whereIn('id', $retired)->contains(fn ($row) => ! $row->story_id || ! in_array($row->id, $known, true))
            || $all->contains(fn ($row) => $row->printing_status !== 'not_started' || $row->shipping_status !== 'not_ready')
            || $rows->contains(fn ($row) => ! in_array($row->status, ['new', 'new_confirmed', 'preview_uploaded', 'ready_preview', 'pending_team_review', 'costomer_confirmed'], true))
            || CodexSceneRun::where('checkout_key', $key)->exists()
            || OrderIdentityRegeneration::where('checkout_key', $key)->whereIn('status', ['queued', 'processing', 'uncertain'])->exists()
            || OrderAutomationRun::whereIn('order_id', $all->modelKeys())->where('dry_run', false)->whereNotIn('status', ['completed', 'cancelled', 'failed'])->exists()
            || PuzzleGeneration::where('checkout_key', $key)->where(fn ($q) => $q->whereIn('status', ['queued', 'processing', 'uncertain'])->orWhereNotNull('lease_until'))->exists()
            || RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->whereIn('status', ['pending', 'processing', 'unknown'])->exists()
            || $previous->steps()->whereIn('status', ['processing', 'uncertain', 'retry_pending'])->exists()
            || $previous->steps()->whereHas('messageAttempts', fn ($q) => $q->whereIn('status', ['pending', 'processing', 'uncertain']))->exists()
            || $previous->identities()->where(fn ($q) => $q->where('status', '!=', 'succeeded')->orWhereNotNull('lease_until'))->exists()) {
            $this->deny('يوجد تنفيذ أو إرسال غير محسوم، أو صفحات سابقة، أو تغيّر خارج نطاق استبدال القصة. لم يبدأ توليد أو إرسال جديد.');
        }
        $contexts = app(ProcessOrderContext::class);
        $snapshot = $contexts->snapshot($rows);
        if (! $contexts->matches($definition, array_replace($snapshot, ['status' => 'new_confirmed']))) {
            $this->deny('القصة الجديدة لا تطابق نطاق مسار المعاينة المفعّل؛ يراجع المدير إعداد المسار.');
        }
        $identities = [];
        $storyRows = [];
        $lineage = [];
        $ancestor = $previous;
        while ($ancestor && ! in_array($ancestor->id, $lineage, true) && count($lineage) < 100) {
            $lineage[] = $ancestor->id;
            $ancestor = isset($ancestor->context['supersedes_run_id']) ? OrderProcessRun::where('checkout_key', $key)->find($ancestor->context['supersedes_run_id']) : null;
        }
        foreach ($rows->whereNotNull('story_id') as $story) {
            if ($story->child_identity_approved_attempt_id || $story->childIdentityRequest?->approved_attempt_id) {
                $this->deny('للقصة هوية معتمدة بالفعل؛ راجع هويتها الحالية قبل إنشاء معاينة أخرى.');
            }
            $inputs = app(WorkflowInputs::class)->capture($story, app(ProcessIdentityService::class)->inputDefinition());
            $prior = OrderProcessIdentity::where('order_id', $story->id)->orderBy('id')->get();
            if ($prior->contains(fn ($task) => ! in_array($task->run_id, $lineage, true) || $task->status !== 'succeeded')) {
                $this->deny('توجد هوية سابقة خارج المسار الحالي؛ راجعها من قسم الهوية قبل الاستكمال.');
            }
            $source = $prior->firstWhere('run_id', $previous->id);
            $reuse = $source && ! $source->lease_until && hash_equals($source->context['fingerprint'], $inputs['fingerprint']);
            $identities[(string) $story->id] = ['mode' => $reuse ? 'reuse' : 'new', 'source_task_id' => $reuse ? $source->id : null,
                'prior_task_ids' => $prior->modelKeys(), 'input_fingerprint' => $inputs['fingerprint']];
            $storyRows[] = ['order' => $story, 'mode' => $reuse ? 'reuse' : 'new'];
        }
        $products = [];
        $cost = 0;
        foreach ($rows->flatMap->items->whereIn('item_type', ['product', 'product_add_on'])->sortBy('id') as $item) {
            $current = app(PuzzleInputs::class)->captureCurrent($item);
            $cost += $current['estimate_usd'];
            $saved = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first();
            if (! $saved || ! MixedPreviewRecovery::reusable($saved) || $saved->status !== 'succeeded'
                || $saved->order_id !== $item->order_id || $saved->checkout_key !== $key || $saved->unit_key !== $current['unit_key']) {
                $this->deny('المنتج #'.$item->id.' ليس له ناتج مكتمل مؤكد؛ راجع محاولته من بند المنتج أولًا.');
            }
            $preview = OrderPreview::with('productGallery')->find($saved->preview_id);
            $attachment = $item->order->attachments()->find($saved->attachment_id);
            if (! $preview || ! $attachment || $preview->order_id !== $item->order_id || $preview->productGallery?->status !== 'active'
                || $preview->production_unit_key !== $current['unit_key'] || $attachment->production_unit_key !== $current['unit_key']
                || $preview->checksum !== $saved->checksum || $attachment->disk !== $saved->disk || $attachment->path !== $saved->output_path) {
                $this->deny('صورة المنتج #'.$item->id.' أو ربطها غير مكتمل؛ راجع الصورة الحالية قبل الاستكمال.');
            }
            foreach (collect([[$preview->disk, $preview->file_path], [$attachment->disk, $attachment->path]])->unique(fn ($p) => implode(':', $p)) as [$disk,$path]) {
                if (! hash_equals($saved->checksum, hash('sha256', Storage::disk($disk)->get($path)))) {
                    $this->deny('تغيّر ملف المنتج #'.$item->id.'؛ راجع الصورة المرفوعة قبل الاستكمال.');
                }
            }
            $receipt = ['generation_id' => $saved->id, 'preview_id' => $preview->id, 'sha256' => $saved->checksum,
                'original_fingerprint' => $saved->context['fingerprint'], 'input_fingerprint' => $current['fingerprint']];
            $products[(string) $item->id] = ['item' => $item, 'generation' => $saved, 'preview' => $preview, 'receipt' => $receipt,
                'changed' => ! hash_equals($saved->context['fingerprint'], $current['fingerprint'])];
        }
        $candidate = new OrderProcessRun($previous->only(['automation_id', 'revision_id', 'checkout_key', 'account_key', 'phone_hash']));
        $candidate->setRelation('revision', $previous->revision);
        $recovery = ['source_run_id' => $previous->id, 'retired_order_ids' => $retired, 'identities' => $identities];
        $candidate->context = $snapshot + ['actor_id' => $previous->context['actor_id'], 'story_recovery' => $recovery];
        $config = $definition['nodes'][0]['config'];
        $config['budget_usd'] -= $cost;
        app(ProcessIdentityService::class)->preflight($candidate, $config);
        $state = ['run_id' => $previous->id, 'run_version' => $previous->version, 'control_version' => $control->version,
            'hold' => [$hold->fingerprint, $hold->created_at], 'snapshot' => $snapshot['fingerprint'], 'recovery' => $recovery,
            'manifest' => [$manifest->id, $manifest->fingerprint], 'products' => array_map(fn ($p) => $p['receipt'], $products)];
        $fingerprint = hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));

        return compact('rows', 'previous', 'snapshot', 'recovery', 'products', 'storyRows', 'fingerprint', 'control');
    }

    public function token(array $plan): string
    {
        return Crypt::encryptString(json_encode(['purpose' => 'story_recovery', 'fingerprint' => $plan['fingerprint']], JSON_THROW_ON_ERROR));
    }

    public function start(Order $order, User $actor, Request $request, string $token, array $choices): OrderProcessRun
    {
        $this->authorize($order, $actor);
        try {
            $offer = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'انتهت صلاحية الفحص؛ حدّث الصفحة.');
        }

        return DB::transaction(function () use ($order, $actor, $request, $offer, $choices) {
            $key = $order->checkoutGroupKey();
            DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
            OrderProcessRun::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            Order::withTrashed()->where('checkout_group_key', $key)->orderBy('id')->lockForUpdate()->get();
            OrderItem::whereIn('order_id', Order::withTrashed()->where('checkout_group_key', $key)->select('id'))->orderBy('id')->lockForUpdate()->get();
            PuzzleGeneration::where('checkout_key', $key)->orderBy('id')->lockForUpdate()->get();
            $this->authorize($order->fresh(), $actor);
            $plan = $this->plan($order->fresh());
            abort_unless(($offer['purpose'] ?? '') === 'story_recovery' && hash_equals($offer['fingerprint'] ?? '', $plan['fingerprint']), 409, 'تغيرت بيانات الطلب أو صوره أو التنفيذ؛ حدّث الصفحة وراجع الاختيارات.');
            $changed = array_keys(array_filter($plan['products'], fn ($p) => $p['changed']));
            $submitted = array_map('intval', array_keys($choices));
            $expected = array_map('intval', $changed);
            sort($submitted);
            sort($expected);
            if ($submitted !== $expected || collect($choices)->contains(fn ($choice) => ! in_array($choice, ['retain', 'regenerate'], true))) {
                $this->deny('اختر الاحتفاظ بالصورة الحالية أو إعادة التوليد لكل منتج تغيّرت مراجعه فقط.');
            }
            foreach ($plan['rows']->where('status', 'new') as $row) {
                $row->update(['status' => 'new_confirmed']);
                $row->statusLogs()->create(['status_type' => 'order', 'status' => 'new_confirmed', 'notes' => 'تأكيد بيانات الطلب المعدّل بواسطة الفريق قبل معاينة القصة البديلة.']);
            }
            $retained = [];
            $regenerated = [];
            foreach ($plan['products'] as $id => $product) {
                if (($choices[$id] ?? 'retain') === 'regenerate') {
                    $generation = app(PuzzleAutomation::class)->regenerate($product['item']->order, $product['item'], $actor, $product['generation']->id);
                    // This explicit admission hands the queued attempt back to the
                    // automation; no stale manual-hold token follows it after commit.
                    $generation->update(['handoff_token' => null]);
                    $regenerated[$id] = $generation->id;
                } else {
                    $retained[$id] = $product['receipt'];
                }
            }
            $previous = $plan['previous'];
            $event = OrderProcessEvent::create(['event_key' => 'story-revision:'.Str::uuid(), 'type' => 'story_preview_revised', 'checkout_key' => $key,
                'order_id' => $plan['storyRows'][0]['order']->id, 'status' => 'processed', 'data' => ['previous_run_id' => $previous->id, 'requested_by' => $actor->id]]);
            $recovery = $plan['recovery'] + ['products' => $retained];
            $previous->update(['status' => 'cancelled', 'active_key' => null, 'next_at' => null, 'wait_until' => null, 'version' => $previous->version + 1]);
            $rows = $plan['rows']->map(fn ($row) => $row->fresh()->load('items'));
            $snapshot = app(ProcessOrderContext::class)->snapshot($rows);
            $run = OrderProcessRun::create(['automation_id' => $previous->automation_id, 'revision_id' => $previous->revision_id, 'event_id' => $event->id,
                'order_id' => $plan['storyRows'][0]['order']->id, 'checkout_key' => $key, 'account_key' => $previous->account_key, 'phone_hash' => hash('sha256', '+'.$snapshot['phone']),
                'enrollment_key' => hash('sha256', 'story-revision:'.$event->id), 'status' => 'queued', 'node_id' => $previous->revision->definition['nodes'][0]['id'], 'next_at' => now(),
                'context' => $snapshot + ['actor_id' => $previous->context['actor_id'], 'supersedes_run_id' => $previous->id, 'story_recovery' => $recovery]]);
            RoboDeskIntegrationEvent::where('checkout_group_key', $key)->where('event_type', 'order.pending_confirmation')->where('status', 'held')->update(['status' => 'cancelled']);
            DB::table('order_automation_holds')->where('checkout_key', $key)->delete();
            DB::table('order_automation_controls')->where('checkout_key', $key)->update(['state' => 'running', 'version' => $plan['control']->version + 1, 'error_code' => null, 'updated_at' => now()]);
            AdminActivityLogger::log('order.story_preview_revised', 'استكمال الطلب بعد استبدال القصة: معاينة جديدة مع الاحتفاظ بالصور المختارة؛ لا اعتماد للمعاينة أو دفع أو إعادة رسالة تأكيد.',
                $order, ['previous_run_id' => $previous->id, 'run_id' => $run->id, 'retired_order_ids' => $recovery['retired_order_ids'],
                    'identities' => $recovery['identities'], 'retained_product_ids' => array_keys($retained), 'regenerated_products' => $regenerated], $actor, $request);

            return $run;
        }, 3);
    }
}
