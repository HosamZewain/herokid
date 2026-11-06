<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\OrderAccess;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** One new replacement per missing page, never a replay of the failed request. */
class BookPageRecovery
{
    private function denyReview(string $reason): never
    {
        throw ValidationException::withMessages(['book_review' => $reason]);
    }

    /** SELECT/byte verification only. Content refusals always need explicit staff review. */
    public function reviewPlan(CodexSceneRun $run, User $actor): array
    {
        abort_unless($this->authorized($run->order, $actor), 403);
        $control = DB::table('order_automation_controls')->where('checkout_key', $run->checkout_key)->first();
        $missing = array_values(array_diff(range(1, max(1, min(1000, (int) $run->scene_count))), array_map('intval', array_keys($run->scenes ?? []))));
        $recovery = $run->book_pdf['recovery'] ?? [];
        if ($run->production_kind !== 'coloring_book' || $run->status !== 'needs_manual' || $run->active_slot
            || isset($run->snapshot['book_repair']) || isset($run->snapshot['book_recovery']) || ! $missing
            || $run->scene_count < 1 || $run->scene_count > 1000 || isset($recovery['reviewed_pages'])
            || ($recovery['children'] ?? []) || ! in_array($recovery['status'] ?? 'blocked', ['blocked', 'queued'], true)
            || $control?->state !== 'paused' || ! $run->order || $run->order->trashed()
            || ! in_array($run->order->printing_status, ['not_started', 'in_progress'], true)
            || ! in_array($run->order->shipping_status, ['not_ready', 'ready'], true)
            || in_array($run->order->status, ['cancelled', 'new'], true)
            || CodexSceneRun::where('checkout_key', $run->checkout_key)->where('active_slot', 1)->exists()
            || ! $this->settled($run, $missing)) {
            $this->denyReview('يلزم إيقاف مؤكد ومحاولة منتهية بلا استكمال سابق أو نتيجة غير محفوظة. راجع تقدم الكتاب الحالي.');
        }
        $pages = [];
        foreach ($missing as $number) {
            $code = $run->scene_progress[$number]['code'] ?? '';
            if ($code !== 'content_review') {
                if (! in_array($code, [...SceneProgress::QUALITY, 'generation_result_uncertain'], true)) {
                    $this->denyReview('سبب نقص بعض الصفحات يحتاج مراجعة؛ لا يمكن طلب محاولة أخرى لهذه النتيجة.');
                }

                continue;
            }
            $text = collect($run->snapshot['texts'] ?? [])->firstWhere('scene_number', $number);
            if (! is_array($text) || ! filled($text['text'] ?? null)) {
                $this->denyReview('وصف الصفحة الأصلية غير متاح للمراجعة؛ لا يمكن تخمين محتواها.');
            }
            $pages[$number] = $text;
        }
        if (! $pages) {
            $this->denyReview('الصفحات الناقصة لا تتضمن رفض محتوى يحتاج إقرارًا؛ استخدم استكمال الصفحات الناقصة المعتاد.');
        }
        if (! app(BookPageService::class)->workerReady()) {
            $this->denyReview('عامل صفحات الكتاب غير متاح حاليًا؛ الصفحات المحفوظة متاحة للعرض والتحميل.');
        }
        if (! hash_equals($run->fingerprint, app(BookPageService::class)->fingerprint($run, true))) {
            $this->denyReview('تغيرت بيانات الكتاب أو اعتماد الكفر؛ راجع النسخة الحالية قبل طلب محاولة أخرى.');
        }
        if (! Order::where('checkout_group_key', $run->checkout_key)->where('paid_amount_cents', '>', 0)
            ->whereIn('payment_status', ['partially_paid', 'paid_in_full', 'paid_without_shipping'])->exists()) {
            $this->denyReview('يلزم تسجيل دفعة فعلية قبل استكمال الصفحات.');
        }
        $fingerprint = hash('sha256', json_encode([$run->id, $run->updated_at->toISOString(), $run->fingerprint, $run->snapshot,
            $run->scenes, $run->scene_progress, $run->book_pdf, $control->version, $actor->id, $pages], JSON_THROW_ON_ERROR));

        return compact('pages', 'fingerprint');
    }

    public function reviewToken(array $plan): string
    {
        return Crypt::encryptString(json_encode(['purpose' => 'book_page_review', 'fingerprint' => $plan['fingerprint']], JSON_THROW_ON_ERROR));
    }

    public function retryReviewed(CodexSceneRun $original, User $actor, Request $request, string $token): void
    {
        abort_unless($this->authorized($original->order, $actor), 403);
        try {
            $selected = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(409, 'حدّث الصفحة وراجع وصف الصفحات الحالية.');
        }
        Cache::lock('codex-scenes-start', 30)->block(3, function () use ($original, $actor, $request, $selected) {
            DB::transaction(function () use ($original, $actor, $request, $selected) {
                Order::where('checkout_group_key', $original->checkout_key)->orderBy('id')->lockForUpdate()->get();
                $run = CodexSceneRun::lockForUpdate()->findOrFail($original->id);
                $control = DB::table('order_automation_controls')->where('checkout_key', $run->checkout_key)->lockForUpdate()->first();
                $plan = $this->reviewPlan($run, $actor->fresh());
                abort_unless(($selected['purpose'] ?? '') === 'book_page_review'
                    && hash_equals($plan['fingerprint'], $selected['fingerprint'] ?? ''), 409, 'تغيرت الصفحة أو بيانات الكتاب أو حالة التحكم؛ حدّث الصفحة.');
                $meta = $run->book_pdf ?? [];
                $recovery = $meta['recovery'] ?? ['children' => []];
                if (isset($recovery['reviewed_pages'])) {
                    $this->denyReview('سُجلت مراجعة ومحاولة لهذه الصفحات بالفعل؛ راجع التقدم أو نتيجة المحاولة.');
                }
                $recovery['previous_status'] = $recovery['status'] ?? null;
                $recovery['previous_reason'] = $recovery['reason'] ?? null;
                unset($recovery['reason']);
                $recovery['status'] = 'queued';
                $recovery['children'] = [];
                $recovery['manual_actor_id'] = $actor->id;
                $recovery['control_version'] = $control->version;
                foreach ($plan['pages'] as $number => $text) {
                    $recovery['reviewed_pages'][$number] = ['text_sha256' => hash('sha256', json_encode($text, JSON_THROW_ON_ERROR)),
                        'actor_id' => $actor->id, 'at' => now()->toIso8601String()];
                }
                $meta['recovery'] = $recovery;
                $run->update(['book_pdf' => $meta]);
                if (! AdminActivityLogger::log('order.book_pages_reviewed_retry', 'مراجعة محتوى الصفحات المرفوضة وطلب محاولة واحدة للمحتوى الأصلي دون تغيير ضوابط التوليد.', $run->order,
                    ['run_id' => $run->id, 'pages' => array_keys($plan['pages']), 'control_version' => $control->version], $actor, $request)) {
                    throw new RuntimeException('book_page_review_audit_required');
                }
            });
        });
        $this->advance($original->fresh());
    }

    private function reviewedText(CodexSceneRun $run, int $number, array $recovery, bool $manual): ?array
    {
        $review = $recovery['reviewed_pages'][$number] ?? null;
        $text = collect($run->snapshot['texts'] ?? [])->firstWhere('scene_number', $number);
        if (! $manual || ! $review || ($review['actor_id'] ?? null) !== ($recovery['manual_actor_id'] ?? null)
            || ! is_array($text) || ! filled($text['text'] ?? null)
            || ! hash_equals($review['text_sha256'] ?? '', hash('sha256', json_encode($text, JSON_THROW_ON_ERROR)))) {
            return null;
        }

        return $text;
    }

    public function pending(): void
    {
        // Only explicit/newly collected recoveries. Do not replay historical jobs.
        foreach (CodexSceneRun::where('production_kind', 'coloring_book')
            ->whereIn('book_pdf->recovery->status', ['queued', 'working'])->limit(5)->get() as $run) {
            try {
                $this->advance($run);
            } catch (\Throwable $error) {
                $meta = $run->fresh()->book_pdf ?? [];
                $meta['recovery']['status'] = 'blocked';
                $meta['recovery']['reason'] = 'تعذر التحقق من الصور المحفوظة أو تجميعها؛ الصور محفوظة ولا نعيد توليدها. راجع التخزين ثم أعد الفحص.';
                $run->update(['book_pdf' => $meta]);
                report(new RuntimeException('Book recovery verification failed: '.$run->id.' ('.class_basename($error).')'));
            }
        }
    }

    public function advance(CodexSceneRun $original, bool $initiate = false, ?User $actor = null): ?CodexSceneRun
    {
        $final = null;
        Cache::lock('codex-scenes-start', 30)->block(3, function () use ($original, $initiate, $actor, &$final) {
            DB::transaction(function () use ($original, $initiate, $actor, &$final) {
                $orders = Order::where('checkout_group_key', $original->checkout_key)->orderBy('id')->lockForUpdate()->get();
                $parent = CodexSceneRun::lockForUpdate()->findOrFail($original->id);
                $meta = $parent->book_pdf ?? [];
                $recovery = $meta['recovery'] ?? null;
                if (($recovery['status'] ?? '') === 'completed') {
                    $final = CodexSceneRun::find($recovery['final_run_id']);

                    return;
                }
                if ((! $recovery && ! $initiate) || ($recovery && ! in_array($recovery['status'], ['queued', 'working'], true))) {
                    return;
                }
                $order = $orders->firstWhere('id', $parent->order_id);
                if ($actor) {
                    abort_unless($order && $this->authorized($order, $actor), 403);
                }
                $deny = function (string $reason) use ($parent, $meta, $recovery) {
                    $parent->update(['book_pdf' => $meta + ['status' => 'withheld']]);
                    $next = $parent->book_pdf;
                    $next['recovery'] = array_merge($recovery ?? [], ['status' => 'blocked', 'reason' => $reason]);
                    $parent->update(['book_pdf' => $next]);
                };
                if (! $order || $parent->production_kind !== 'coloring_book' || $parent->status !== 'needs_manual' || $parent->active_slot
                    || isset($parent->snapshot['book_repair']) || isset($parent->snapshot['book_recovery'])
                    || ! in_array($order->printing_status, ['not_started', 'in_progress'], true) || ! in_array($order->shipping_status, ['not_ready', 'ready'], true)
                    || in_array($order->status, ['cancelled', 'new'], true)) {
                    $deny('حالة الكتاب أو الشحن لا تسمح باستكمال الصفحات تلقائيًا.');

                    return;
                }
                if (! $recovery) {
                    $recovery = ['status' => 'queued', 'missing_pages' => array_values(array_diff(range(1, $parent->scene_count), array_map('intval', array_keys($parent->scenes ?? [])))), 'children' => []];
                    $meta['recovery'] = $recovery;
                    $meta['status'] ??= 'withheld';
                    $parent->update(['book_pdf' => $meta]);
                }
                if ($actor) {
                    $recovery['manual_actor_id'] = $actor->id;
                    $recovery['control_version'] = DB::table('order_automation_controls')->where('checkout_key', $parent->checkout_key)->value('version');
                    $meta['recovery'] = $recovery;
                    $parent->update(['book_pdf' => $meta]);
                }
                $manual = $this->manualAuthorization($order, $recovery);
                if ((AutomationControl::blocked($parent->checkout_key) || AutomationHandoff::held($parent->checkout_key)) && ! $manual) {
                    // A pause is a fence, not a reason to discard a recovery.
                    return;
                }
                if (! app(BookPageService::class)->workerReady()) {
                    return;
                }
                try {
                    if (! hash_equals($parent->fingerprint, app(BookPageService::class)->fingerprint($parent, $manual))) {
                        throw new RuntimeException;
                    }
                } catch (\Throwable) {
                    $deny('تغيرت بيانات الكتاب أو موافقة الكفر؛ راجع النسخة الحالية قبل الاستكمال.');

                    return;
                }
                if (! $orders->contains(fn ($row) => $row->paid_amount_cents > 0 && in_array($row->payment_status, ['partially_paid', 'paid_in_full', 'paid_without_shipping'], true))) {
                    $deny('يلزم وجود دفعة فعلية مسجلة قبل استكمال الصفحات.');

                    return;
                }
                $missing = array_values(array_diff(range(1, $parent->scene_count), array_map('intval', array_keys($parent->scenes ?? []))));
                if (! $missing || ! $this->settled($parent, $missing)) {
                    $deny('لم يتأكد انتهاء العامل أو توجد نتيجة لم تُحفظ بعد؛ يلزم مراجعتها قبل توليد بديل.');

                    return;
                }
                foreach ($missing as $number) {
                    $code = $parent->scene_progress[$number]['code'] ?? '';
                    if (! in_array($code, [...SceneProgress::QUALITY, 'generation_result_uncertain'], true)
                        && ! ($code === 'content_review' && $this->reviewedText($parent, $number, $recovery, $manual))) {
                        $deny($code === 'content_review' ? 'رفض محتوى صفحة '.$number.' يحتاج مراجعة؛ لا نغيّر المحتوى لتجاوز الرفض.' : 'سبب نقص صفحة '.$number.' يحتاج مراجعة قبل الاستكمال.');

                        return;
                    }
                }
                $pages = $parent->scenes ?? [];
                foreach ($recovery['children'] as $number => $id) {
                    $child = CodexSceneRun::find($id);
                    if (! $child || ($child->snapshot['book_repair']['parent_id'] ?? null) !== $parent->id
                        || ($child->snapshot['book_repair']['page'] ?? null) !== (int) $number
                        || $child->order_id !== $parent->order_id || $child->order_item_id !== $parent->order_item_id
                        || $child->checkout_key !== $parent->checkout_key || ! hash_equals($parent->fingerprint, $child->fingerprint)) {
                        $deny('سجل استكمال الصفحات يحتاج مراجعة.');

                        return;
                    }
                    if ($child->status === 'completed' && count($child->scenes ?? []) === 1 && isset($child->scenes[1])) {
                        $pages[$number] = $child->scenes[1] + ['source_scene' => 1];
                    } elseif (in_array($child->status, ['needs_manual', 'quality_review', 'uncertain', 'stopped'], true)) {
                        $deny('تعذر توليد بديل صفحة '.$number.'؛ الصور الأخرى محفوظة، ولن نكرر المحاولة بلا نهاية.');

                        return;
                    } else {
                        return;
                    }
                }
                if (CodexSceneRun::where('checkout_key', $parent->checkout_key)->where('active_slot', 1)->exists()) {
                    return;
                }
                if (! AutomationControl::admits($parent->checkout_key, $manual)) {
                    return;
                }
                $remaining = array_values(array_diff($missing, array_map('intval', array_keys($recovery['children']))));
                if ($remaining) {
                    $number = $remaining[0];
                    $snapshot = $parent->snapshot;
                    $snapshot['book_repair'] = ['parent_id' => $parent->id, 'page' => $number, 'page_count' => $parent->scene_count];
                    if ($manual) {
                        $snapshot['book_repair'] += ['manual_actor_id' => $recovery['manual_actor_id'], 'control_version' => $recovery['control_version']];
                    }
                    $snapshot['texts'] = [['scene_number' => 1, 'title' => 'صفحة بديلة '.$number,
                        'text' => 'Create a NEW distinct child-friendly coloring activity for position '.$number.' in this approved '.$parent->scene_count.'-page book. Preserve the exact child, approved cover theme and production brief. This replaces an unavailable page; do not repeat an earlier image request, recreate an existing page, or bypass a content refusal. Choose a fresh simple age-appropriate activity and composition.']];
                    if (($parent->scene_progress[$number]['code'] ?? '') === 'content_review') {
                        $text = $this->reviewedText($parent, $number, $recovery, $manual);
                        $snapshot['texts'] = [array_replace($text, ['scene_number' => 1])];
                        $snapshot['book_repair']['reviewed_content'] = $recovery['reviewed_pages'][$number];
                    }
                    $snapshot['references'] = collect($snapshot['references'] ?? [])->filter(fn ($ref) => $ref['role'] === 'child_photo'
                        || ($ref['scene'] ?? null) === $number)->map(function ($ref) {
                            if (isset($ref['scene'])) {
                                $ref['scene'] = 1;
                            }

                            return $ref;
                        })->values()->all();
                    $child = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $parent->order_id, 'checkout_key' => $parent->checkout_key,
                        'order_item_id' => $parent->order_item_id, 'production_kind' => 'coloring_book', 'protocol' => 6, 'scene_count' => 1,
                        'source' => $manual ? 'manual' : 'automatic', 'requested_by' => $manual ? $recovery['manual_actor_id'] : null, 'status' => 'waiting_slot', 'active_slot' => 1, 'previous_status' => $parent->previous_status,
                        'scenes' => [], 'snapshot' => $snapshot, 'fingerprint' => $parent->fingerprint,
                        'book_pdf' => ['status' => 'withheld', 'repair_target' => $number, 'repair_parent' => $parent->id,
                            'repair_total' => $parent->scene_count, 'repair_saved' => count($pages)]]);
                    $recovery['children'][$number] = $child->id;
                    $recovery['status'] = 'working';
                    $meta['recovery'] = $recovery;
                    $meta['status'] ??= 'withheld';
                    $parent->update(['book_pdf' => $meta]);
                    $order->update(['status' => 'generating']);
                    AutomationActivity::record($order, 'coloring_book_missing_page_enrolled', 'استكمال تلقائي: صفحة بديلة '.$number.' فقط، مع الاحتفاظ بكل الصفحات المحفوظة.',
                        ['parent_run_id' => $parent->id, 'run_id' => $child->id, 'page' => $number]);

                    return;
                }
                if (count($pages) !== $parent->scene_count) {
                    $deny('عدد الصفحات بعد الاستكمال غير مطابق للكتاب.');

                    return;
                }
                // Verify every retained image before publishing any final print file.
                $hashes = [];
                foreach ($pages as $number => $scene) {
                    $source = CodexSceneRun::findOrFail($scene['source_run_id'] ?? $parent->id);
                    BookPdfService::pageBytes($source, (int) ($scene['source_scene'] ?? $number), $scene);
                    if (isset($hashes[$scene['sha256']])) {
                        $deny('توجد صفحة مكررة في الكتاب؛ يلزم مراجعة الجودة.');

                        return;
                    }
                    $hashes[$scene['sha256']] = true;
                }
                ksort($pages, SORT_NUMERIC);
                $snapshot = $parent->snapshot;
                $snapshot['book_recovery'] = ['parent_id' => $parent->id, 'children' => $recovery['children']];
                $final = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $parent->order_id, 'checkout_key' => $parent->checkout_key,
                    'order_item_id' => $parent->order_item_id, 'production_kind' => 'coloring_book', 'protocol' => 6, 'scene_count' => $parent->scene_count,
                    'source' => 'automatic', 'status' => 'completed', 'active_slot' => null, 'previous_status' => $parent->previous_status,
                    'scenes' => $pages, 'snapshot' => $snapshot, 'fingerprint' => $parent->fingerprint, 'finished_at' => now()]);
                app(BookPdfService::class)->request($final);
                $final->save();
                $recovery['status'] = 'completed';
                $recovery['final_run_id'] = $final->id;
                $meta['recovery'] = $recovery;
                $parent->update(['book_pdf' => $meta]);
                $otherIncomplete = CodexSceneRun::where('order_id', $order->id)->where('production_kind', 'coloring_book')
                    ->where('order_item_id', '!=', $parent->order_item_id)->orderBy('created_at')->get()->keyBy('order_item_id')
                    ->contains(fn ($run) => $run->status !== 'completed');
                $order->update(['status' => $otherIncomplete ? 'pending_team_review' : 'delivered']);
                AutomationActivity::record($order, 'coloring_book_missing_pages_completed', 'اكتمل عدد صفحات كتاب التلوين؛ تجهيز ملف PDF النهائي دون علامة النقص.',
                    ['parent_run_id' => $parent->id, 'run_id' => $final->id, 'pages' => $parent->scene_count]);
            }, 3);
        });
        if ($final) {
            app(BookPdfService::class)->publish($final->fresh());
        }

        return $final;
    }

    private function authorized(Order $order, User $actor): bool
    {
        return app(OrderAccess::class)->controls($order, $actor) && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage');
    }

    private function manualAuthorization(Order $order, array $authorization): bool
    {
        $actor = isset($authorization['manual_actor_id']) ? User::find($authorization['manual_actor_id']) : null;
        if (! $actor || ! $this->authorized($order, $actor)) {
            return false;
        }
        $query = DB::table('order_automation_controls')->where('checkout_key', $order->checkoutGroupKey());
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $control = $query->first();

        return $control?->state === 'paused' && $control->version === ($authorization['control_version'] ?? null);
    }

    public function manualAllowed(CodexSceneRun $run): bool
    {
        return isset($run->snapshot['book_repair']['manual_actor_id']) && $this->manualAuthorization($run->order, $run->snapshot['book_repair']);
    }

    public function requesterAuthorized(CodexSceneRun $run): bool
    {
        $id = $run->snapshot['book_repair']['manual_actor_id'] ?? null;
        $actor = $id ? User::find($id) : null;

        return ! $id || ($actor && $this->authorized($run->order, $actor));
    }

    private function settled(CodexSceneRun $run, array $missing): bool
    {
        $root = config('codex_scenes.spool');
        $dir = $root.'/outbox/'.$run->id;
        $manifest = $root.'/inbox/'.$run->id.'/manifest.json';
        $status = $dir.'/status.json';
        if (is_link($dir) || is_link($manifest) || is_link($status) || ! is_file($manifest) || ! is_file($status)) {
            return false;
        }
        $state = json_decode(file_get_contents($status), true);
        if (($state['id'] ?? null) !== $run->id || ($state['status'] ?? null) !== 'needs_manual'
            || ! hash_equals($state['manifest_sha256'] ?? '', hash_file('sha256', $manifest))) {
            return false;
        }
        foreach ($missing as $number) {
            if (file_exists($dir.'/scene-'.sprintf('%02d', $number).'.json') || file_exists($dir.'/scene-'.sprintf('%02d', $number).'.png')
                || ($state['scene_progress'][$number]['status'] ?? '') !== 'failed'
                || ($state['scene_progress'][$number]['code'] ?? '') !== ($run->scene_progress[$number]['code'] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
