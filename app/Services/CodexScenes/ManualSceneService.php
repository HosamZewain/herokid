<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\User;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\OrderSceneTextService;
use App\Support\AdminActivityLogger;
use App\Support\BookArtwork;
use App\Support\OrderStatusRegistry;
use App\Support\StoryProductionPrompt;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManualSceneService
{
    public function health(): bool
    {
        $path = config('codex_scenes.spool').'/outbox/health.json';
        if (! config('codex_scenes.enabled') || ! is_file($path) || is_link($path) || filemtime($path) < time() - 90) {
            return false;
        }
        $data = json_decode(file_get_contents($path), true);

        return ($data['authenticated'] ?? false) === true;
    }

    public function reason(Order $order): ?string
    {
        if (! $this->health()) {
            return 'Codex غير متصل بالاشتراك حاليًا؛ التوليد غير متاح.';
        }
        if (CodexSceneRun::where('order_id', $order->id)->where('active_slot', 1)->exists()) {
            return 'هذه القصة موجودة بالفعل في طابور التوليد.';
        }
        if ($order->trashed() || ! $order->story_id || ! in_array($order->status, ['costomer_confirmed', 'new_confirmed'], true)) {
            return 'التوليد متاح لقصة مؤكدة قبل بدء الإنتاج فقط.';
        }
        $attempt = $order->childIdentityApprovedAttempt;
        if (! $attempt || $attempt->status !== 'succeeded' || ! $attempt->output_storage_path || ! $attempt->output_checksum) {
            return 'ارفع واعتمد هوية الطفل أولًا.';
        }
        if (! Order::where('checkout_group_key', $order->checkoutGroupKey())->where('paid_amount_cents', '>', 0)->exists()) {
            return 'يلزم تسجيل أي مبلغ مدفوع فعليًا على الطلب.';
        }
        if (app(AutomationAssignment::class)->owns($order, false)) {
            return 'الطلب ما زال تحت مسؤولية مسار آخر.';
        }

        return null;
    }

    public function fingerprint(Order $order): string
    {
        $order->loadMissing(['story', 'productionPromptOverride', 'childIdentityApprovedAttempt']);

        return hash('sha256', json_encode([$order->only(['story_id', 'child_name', 'child_age', 'child_gender', 'interests', 'lesson', 'gift_note', 'uploaded_photos', 'child_identity_approved_attempt_id']), $order->childIdentityApprovedAttempt?->output_checksum, $order->story?->updated_at?->toISOString(), $order->productionPromptOverride?->updated_at?->toISOString(), app(OrderSceneTextService::class)->present($order)['scenes']], JSON_UNESCAPED_UNICODE));
    }

    public function start(Order $order, ?User $user = null): CodexSceneRun
    {
        return Cache::lock('codex-scenes-start', 30)->block(3, function () use ($order, $user) {
            return DB::transaction(function () use ($order, $user) {
                $rows = Order::where('checkout_group_key', $order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
                $order = $rows->firstWhere('id', $order->id);
                if (! $order || ($reason = $this->reason($order))) {
                    throw ValidationException::withMessages(['codex_scenes' => $reason ?? 'الطلب غير متاح.']);
                }
                if ($user === null && (! $this->automaticEligible($order) || CodexSceneRun::where('order_id', $order->id)->exists())) {
                    throw ValidationException::withMessages(['codex_scenes' => 'القصة غير مؤهلة للتسجيل التلقائي.']);
                }
                $texts = app(OrderSceneTextService::class)->present($order);
                if (! $texts['all_ready'] || count($texts['scenes']) !== 13) {
                    throw ValidationException::withMessages(['codex_scenes' => 'نصوص المشاهد الثلاثة عشر غير مكتملة.']);
                }
                foreach (['generating', 'ready_preview', 'pending_team_review'] as $status) {
                    if (! OrderStatusRegistry::isValid('order', $status)) {
                        throw ValidationException::withMessages(['codex_scenes' => 'حالات الإنتاج المطلوبة غير مفعلة.']);
                    }
                }
                $attempt = $order->childIdentityApprovedAttempt;
                $run = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'checkout_key' => $order->checkoutGroupKey(), 'requested_by' => $user?->id, 'source' => $user ? 'manual' : 'automatic', 'protocol' => 4, 'status' => 'waiting_slot', 'active_slot' => 1, 'previous_status' => $order->status, 'fingerprint' => $this->fingerprint($order), 'scenes' => [],
                    'snapshot' => ['prompt' => StoryProductionPrompt::forOrder($order), 'texts' => $texts['scenes'], 'identity_disk' => $attempt->output_disk, 'identity_path' => $attempt->output_storage_path, 'identity_checksum' => $attempt->output_checksum]]);
                $this->setStatus($order, 'generating', 'إضافة القصة إلى طابور توليد مشاهد Codex.');
                if ($user) {
                    AdminActivityLogger::log(action: 'order.codex_scenes_requested', description: 'طلب توليد 13 مشهدًا عبر اشتراك Codex.', subject: $order, properties: ['run_id' => $run->id], admin: $user);
                } else {
                    AutomationActivity::record($order, 'codex_scene_enrolled', 'تسجيل القصة تلقائيًا بعد اعتماد الهوية وتسجيل دفعة فعلية.', ['run_id' => $run->id]);
                }

                return $run;
            });
        });
    }

    /** Explicit owner/operator action only. The original attempt and its bridge files remain immutable. */
    public function resumeQuality(CodexSceneRun $original, string $authorization, ?int $skipContentScene = null): CodexSceneRun
    {
        if ($original->production_kind === 'coloring_book') {
            throw ValidationException::withMessages(['codex_scenes' => 'استكمال صفحات الكتاب يحتاج مراجعة خاصة؛ لا تستخدم استكمال القصص.']);
        }
        if (trim($authorization) === '') {
            throw ValidationException::withMessages(['codex_scenes' => 'يلزم مرجع اعتماد الاستكمال.']);
        }

        return Cache::lock('codex-scenes-start', 30)->block(3, function () use ($original, $authorization, $skipContentScene) {
            return DB::transaction(function () use ($original, $authorization, $skipContentScene) {
                $rows = Order::where('checkout_group_key', $original->checkout_key)->orderBy('id')->lockForUpdate()->get();
                $old = CodexSceneRun::lockForUpdate()->findOrFail($original->id);
                $order = $rows->firstWhere('id', $old->order_id);
                if (! $this->health() || ! $order || $order->status !== 'generating'
                    || $old->status !== 'quality_review' || $old->active_slot !== 1
                    || ! in_array($old->error_code, $skipContentScene !== null ? ['content_review'] : SceneProgress::QUALITY, true)
                    || AutomationHandoff::held($old->checkout_key) || ! $this->hasPayment($order)
                    || $order->childIdentityApprovedAttempt?->status !== 'succeeded'
                    || ! hash_equals($old->fingerprint, $this->fingerprint($order))) {
                    throw ValidationException::withMessages(['codex_scenes' => 'الاستكمال غير آمن: راجع حالة القصة والهوية والدفع والاستلام اليدوي.']);
                }
                $dir = config('codex_scenes.spool').'/outbox/'.$old->id;
                $statePath = $dir.'/status.json';
                $state = $this->safeFile($statePath, $dir) ? json_decode(file_get_contents($statePath), true) : null;
                if (($state['id'] ?? null) !== $old->id || ($state['status'] ?? null) !== 'quality_review'
                    || ($state['code'] ?? null) !== $old->error_code) {
                    throw ValidationException::withMessages(['codex_scenes' => 'لم يتأكد توقف عامل التوليد بسبب جودة الصورة.']);
                }
                $saved = $old->scenes ?? [];
                if (count($saved) >= 13 || array_map('intval', array_keys($saved)) !== (count($saved) ? range(1, count($saved)) : [])) {
                    throw ValidationException::withMessages(['codex_scenes' => 'تسلسل المشاهد المحفوظة يحتاج مراجعة.']);
                }
                if ($skipContentScene !== null && ($skipContentScene !== count($saved) + 1 || $skipContentScene > 13)) {
                    throw ValidationException::withMessages(['codex_scenes' => 'المشهد المتجاوز يجب أن يكون مشهد رفض المحتوى التالي مباشرة.']);
                }
                foreach ($saved as $number => $scene) {
                    $this->retainedBytes($old, (int) $number, $scene);
                }
                foreach (range(count($saved) + 1, 13) as $number) {
                    if (is_file($dir.'/scene-'.sprintf('%02d', $number).'.json')) {
                        throw new \RuntimeException('Accepted worker output not collected yet');
                    }
                }
                $snapshot = $old->snapshot;
                $history = $snapshot['resume_history'] ?? [];
                $clock = SceneTiming::describe($old);
                $history[] = ['run_id' => $old->id, 'seconds' => $clock['seconds'], 'source' => $clock['source']];
                $snapshot['resume_from'] = $old->id;
                $snapshot['resume_history'] = $history;
                $snapshot['retained_scenes'] = $saved;
                $snapshot['skipped_scenes'] = $skipContentScene === null ? [] : [['scene' => $skipContentScene, 'code' => 'content_review']];
                $old->update(['active_slot' => null]);
                $run = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $old->order_id,
                    'checkout_key' => $old->checkout_key, 'requested_by' => null, 'source' => 'automatic',
                    'protocol' => 4, 'status' => 'waiting_slot', 'active_slot' => 1,
                    'previous_status' => $old->previous_status, 'fingerprint' => $old->fingerprint,
                    'snapshot' => $snapshot, 'scenes' => $saved]);
                AutomationActivity::record($order, 'codex_scene_resume_authorized', 'استكمال مشاهد Codex بعد مراجعة الجودة؛ الاحتفاظ بالمشاهد السليمة وبدء محاولة مستقلة للمشاهد المتبقية.',
                    ['run_id' => $run->id, 'previous_run_id' => $old->id, 'retained_scenes' => count($saved), 'authorization' => $authorization, 'skipped_content_scene' => $skipContentScene]);

                return $run;
            });
        });
    }

    private function retainedBytes(CodexSceneRun $run, int $number, array $scene): string
    {
        $attachment = OrderAttachment::find($scene['attachment_id'] ?? 0);
        if (! $attachment || $attachment->order_id !== $run->order_id || $attachment->production_unit_key !== 'story:'.$run->order_id
            || $attachment->disk !== 's3_private' || $attachment->path !== ($scene['path'] ?? null)
            || ! str_starts_with($attachment->path, 'orders/'.$run->order_id.'/codex-scenes/')) {
            throw new \RuntimeException('Retained scene belongs to another story');
        }
        $bytes = Storage::disk('s3_private')->get($attachment->path);
        $size = @getimagesizefromstring($bytes);
        if (! hash_equals($scene['sha256'] ?? '', hash('sha256', $bytes)) || ! $size || $size['mime'] !== 'image/png'
            || min($size[0], $size[1]) < 1024 || $size[0] <= $size[1]) {
            throw new \RuntimeException('Retained scene integrity failed');
        }

        return $bytes;
    }

    public function automaticEligible(Order $order): bool
    {
        return config('codex_scenes.automatic') && ! AutomationHandoff::held($order->checkoutGroupKey())
            && in_array($order->status, ['costomer_confirmed', 'new_confirmed'], true)
            && $this->hasPayment($order)
            && ! CodexSceneRun::where('order_id', $order->id)->exists();
    }

    private function hasPayment(Order $order): bool
    {
        return Order::where('checkout_group_key', $order->checkoutGroupKey())->where('paid_amount_cents', '>', 0)
            ->whereIn('payment_status', ['partially_paid', 'paid_in_full', 'paid_without_shipping'])->exists();
    }

    public function enroll(): void
    {
        if (! config('codex_scenes.automatic') || ! $this->health()) {
            return;
        }
        // No historical retries: one automatic admission per story, including stopped/uncertain work.
        $room = max(0, 50 - CodexSceneRun::where('active_slot', 1)->count());
        if ($room === 0) {
            return;
        }
        $query = Order::whereNotNull('story_id')->whereNotNull('child_identity_approved_attempt_id')
            ->whereIn('status', ['costomer_confirmed', 'new_confirmed'])
            ->whereNotIn('id', CodexSceneRun::select('order_id'))
            ->whereNotIn('checkout_group_key', DB::table('order_automation_holds')->select('checkout_key'))
            ->whereIn('checkout_group_key', Order::select('checkout_group_key')->where('paid_amount_cents', '>', 0)
                ->whereIn('payment_status', ['partially_paid', 'paid_in_full', 'paid_without_shipping']))
            ->orderBy('id');
        $cursor = (int) Cache::get('codex-scenes-enrollment-cursor', 0);
        $batch = (clone $query)->where('id', '>', $cursor)->limit(min(25, $room))->get();
        if ($batch->isEmpty()) {
            $batch = $query->limit(min(25, $room))->get();
        }
        foreach ($batch as $order) {
            try {
                $this->start($order);
            } catch (ValidationException $e) {
                // Eligibility/ownership gates; no provider request or automatic retry.
            }
            Cache::put('codex-scenes-enrollment-cursor', $order->id, now()->addDay());
        }
    }

    private function setStatus(Order $order, string $status, string $note): void
    {
        if ($order->status === $status) {
            return;
        }
        $before = $order->status;
        $order->update(['status' => $status]);
        $order->statusLogs()->create(['status' => $status, 'notes' => $note]);
        AutomationActivity::record($order, 'codex_scene_status', $note, ['from' => $before, 'to' => $status]);
    }

    public function cancel(CodexSceneRun $run, User $user): void
    {
        DB::transaction(function () use ($run, $user) {
            $run = CodexSceneRun::lockForUpdate()->findOrFail($run->id);
            if (! $run->active_slot) {
                return;
            }
            if ($run->status === 'waiting_slot') {
                $run->update(['status' => 'stopped', 'active_slot' => null, 'finished_at' => now()]);
                $this->setStatus($run->order, $run->previous_status, 'إيقاف القصة قبل بدء التوليد.');
                AdminActivityLogger::log(action: 'order.codex_scenes_stop_requested', description: 'إيقاف القصة من طابور Codex قبل التوليد.', subject: $run->order, properties: ['run_id' => $run->id], admin: $user);

                return;
            }
            $dir = config('codex_scenes.spool').'/inbox/'.$run->id;
            if (! is_dir($dir)) {
                mkdir($dir, 02750, true);
                chmod($dir, 02750);
            }
            file_put_contents($dir.'/cancel', '1');
            chmod($dir.'/cancel', 0640);
            $run->update(['status' => 'stopping']);
            AdminActivityLogger::log(action: 'order.codex_scenes_stop_requested', description: 'طلب إيقاف توليد Codex مع الاحتفاظ بالمشاهد المحفوظة.', subject: $run->order, properties: ['run_id' => $run->id], admin: $user);
        });
    }

    public function tick(): void
    {
        Cache::lock('codex-scenes-sync', 110)->get(function () {
            $this->enroll();
            app(BookPageService::class)->enroll();
            foreach (CodexSceneRun::where('active_slot', 1)->orderBy('created_at')->get() as $run) {
                if ($run->status === 'waiting_slot') {
                    // An uncertain provider result pauses further dispatch until reviewed.
                    if (CodexSceneRun::where('active_slot', 1)->where('status', 'uncertain')->exists()
                        || CodexSceneRun::where('active_slot', 1)->whereNotIn('status', ['waiting_slot', 'quality_review'])->count() >= config('codex_scenes.concurrency', 2)) {
                        continue;
                    }
                    $claimed = CodexSceneRun::whereKey($run->id)->where('active_slot', 1)->where('status', 'waiting_slot')->update(['status' => 'preparing']);
                    if (! $claimed) {
                        continue;
                    }
                    $run->refresh();
                }
                try {
                    if ($run->status === 'preparing') {
                        $this->prepare($run);
                    }
                    $this->collect($run->fresh());
                } catch (\Throwable $e) {
                    // Do not erase the reservation or retry generation on an uncertain result.
                    $run->refresh();
                    if ($run->status !== 'uncertain') {
                        $run->update(['status' => 'uncertain', 'error_code' => 'sync_requires_review']);
                        AutomationActivity::record($run->order, 'codex_scene_error', 'توليد Codex تحتاج مراجعة؛ لن تتم إعادة التوليد تلقائيًا.', ['run_id' => $run->id]);
                        report(new \RuntimeException('Codex scene sync requires review: '.$run->id.' ('.class_basename($e).')'));
                    }
                }
            }
        });
    }

    private function prepare(CodexSceneRun $run): void
    {
        $order = $run->order;
        if ($run->production_kind === 'coloring_book' && ! app(BookPageService::class)->workerReady()) {
            throw new \RuntimeException('Book worker unavailable');
        }
        if (! hash_equals($run->fingerprint, $this->runFingerprint($run, $order))) {
            throw new \RuntimeException('Input changed');
        }
        if ($run->source === 'automatic' && (! $this->hasPayment($order) || AutomationHandoff::held($order->checkoutGroupKey()))) {
            throw new \RuntimeException('Automatic eligibility changed');
        }
        $snapshot = $run->snapshot;
        $image = Storage::disk($snapshot['identity_disk'])->get($snapshot['identity_path']);
        if (! hash_equals($snapshot['identity_checksum'], hash('sha256', $image)) || ! @getimagesizefromstring($image)) {
            throw new \RuntimeException('Identity mismatch');
        }
        $dir = config('codex_scenes.spool').'/inbox/'.$run->id;
        if (! is_dir($dir)) {
            mkdir($dir, 02750, true);
            chmod($dir, 02750);
        }
        file_put_contents($dir.'/identity.img', $image);
        chmod($dir.'/identity.img', 0640);
        $data = ['protocol' => $run->protocol, 'id' => $run->id, 'order_id' => $run->order_id, 'identity_checksum' => $snapshot['identity_checksum'], 'scene_count' => $run->scene_count ?? 13, 'prompt' => $snapshot['prompt'], 'texts' => $snapshot['texts']];
        if ($run->production_kind === 'coloring_book') {
            $data += ['production_kind' => 'coloring_book', 'order_item_id' => $run->order_item_id,
                'artwork_contract' => $snapshot['artwork_contract'] ?? null, 'references' => []];
            foreach ($snapshot['references'] ?? [] as $reference) {
                if (! preg_match('/\A(?:child-reference-[0-9]+\.(?:img|png|jpg|webp)|linked-story-[0-9]+\.png)\z/', $reference['file'])) {
                    throw new \RuntimeException('Invalid book reference name');
                }
                file_put_contents($dir.'/'.$reference['file'], app(BookReferences::class)->bytes($reference));
                chmod($dir.'/'.$reference['file'], 0640);
                $data['references'][] = array_intersect_key($reference, array_flip(['file', 'role', 'sha256', 'scene']));
            }
        }
        if ($run->protocol >= 3 && isset($snapshot['resume_from'])) {
            $data['resume_from'] = $snapshot['resume_from'];
            $data['retained_scenes'] = [];
            foreach ($snapshot['retained_scenes'] as $number => $scene) {
                $file = $dir.'/scene-'.sprintf('%02d', $number).'.png';
                file_put_contents($file, $this->retainedBytes($run, (int) $number, $scene));
                chmod($file, 0640);
                $data['retained_scenes'][] = ['scene' => (int) $number, 'sha256' => $scene['sha256']];
            }
        }
        if ($run->protocol >= 4) {
            $data['skipped_scenes'] = $snapshot['skipped_scenes'] ?? [];
        }
        file_put_contents($dir.'/manifest.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($dir.'/manifest.tmp', 0640);
        DB::transaction(function () use ($run, $dir) {
            $locked = CodexSceneRun::lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'preparing') {
                return;
            }
            $rows = Order::where('checkout_group_key', $locked->checkout_key)->orderBy('id')->lockForUpdate()->get();
            $order = $rows->firstWhere('id', $locked->order_id);
            if (! $order || ! hash_equals($locked->fingerprint, $this->runFingerprint($locked, $order))
                || ($locked->source === 'automatic' && (! $this->hasPayment($order) || AutomationHandoff::held($locked->checkout_key)))) {
                throw new \RuntimeException('Eligibility changed before dispatch');
            }
            rename($dir.'/manifest.tmp', $dir.'/manifest.json');
            $locked->update(['status' => 'queued']);
            AutomationActivity::record($locked->order, 'codex_scene_queued', 'تجهيز برومبت القصة والهوية المعتمدة لتوليد Codex.', ['run_id' => $locked->id]);
        });
    }

    private function runFingerprint(CodexSceneRun $run, Order $order): string
    {
        return $run->production_kind === 'coloring_book' ? app(BookPageService::class)->fingerprint($run) : $this->fingerprint($order);
    }

    private function safeFile(string $path, string $directory): bool
    {
        $real = realpath($path);
        $root = realpath($directory);

        return $real && $root && ! is_link($directory) && ! is_link($path) && is_file($path) && str_starts_with($real, $root.'/');
    }

    public function collect(CodexSceneRun $run): void
    {
        $dir = config('codex_scenes.spool').'/outbox/'.$run->id;
        if (! $this->safeFile($dir.'/status.json', $dir)) {
            return;
        }
        $state = json_decode(file_get_contents($dir.'/status.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($state['id'] ?? null) !== $run->id) {
            throw new \RuntimeException('Run mismatch');
        }
        DB::transaction(function () use ($run, $dir, $state) {
            $run = CodexSceneRun::lockForUpdate()->findOrFail($run->id);
            if (! $run->active_slot) {
                return;
            }
            $order = Order::withTrashed()->lockForUpdate()->findOrFail($run->order_id);
            $timing = SceneTiming::validated($state['timing'] ?? null, $run);
            if ($timing !== null) {
                $run->timing = $timing;
            }
            if (($state['status'] ?? '') === 'stopped') {
                $run->update(['status' => 'stopped', 'active_slot' => null, 'finished_at' => now()]);
                if (! $order->trashed() && $order->status === 'generating') {
                    $this->setStatus($order, $run->previous_status, 'توقف توليد Codex مع الاحتفاظ بالمشاهد المحفوظة.');
                }
                AutomationActivity::record($order, 'codex_scene_stopped', 'تم إيقاف توليد Codex وتحرير الطلب للتيم.', ['run_id' => $run->id]);

                return;
            }
            if ($order->trashed() || ! hash_equals($run->fingerprint, $this->runFingerprint($run, $order)) || $order->status !== 'generating') {
                throw new \RuntimeException('Input changed');
            }
            $saved = $run->scenes ?? [];
            foreach (range(1, $run->scene_count ?? 13) as $n) {
                $key = (string) $n;
                if ($run->protocol >= 4 && ! in_array($state['scene_progress'][$key]['status'] ?? '', ['succeeded', 'retained'], true)) {
                    continue;
                }
                if (isset($saved[$key])) {
                    continue;
                }
                $metaPath = $dir.'/scene-'.sprintf('%02d', $n).'.json';
                $path = $dir.'/scene-'.sprintf('%02d', $n).'.png';
                if (! $this->safeFile($metaPath, $dir) || ! $this->safeFile($path, $dir)) {
                    continue;
                }
                if (filesize($path) > 30 * 1024 * 1024) {
                    throw new \RuntimeException('Oversized output');
                }
                $meta = json_decode(file_get_contents($metaPath), true, 16, JSON_THROW_ON_ERROR);
                if ($run->protocol >= 2 && (($meta['id'] ?? '') !== $run->id || ($meta['order_id'] ?? 0) !== $run->order_id
                    || ($meta['identity_checksum'] ?? '') !== $run->snapshot['identity_checksum'])) {
                    throw new \RuntimeException('Scene belongs to another story or identity');
                }
                if ($run->production_kind === 'coloring_book' && (($meta['production_kind'] ?? '') !== 'coloring_book' || ($meta['order_item_id'] ?? null) !== $run->order_item_id)) {
                    throw new \RuntimeException('Page belongs to another product');
                }
                $bytes = file_get_contents($path);
                $sha = hash('sha256', $bytes);
                $size = @getimagesizefromstring($bytes);
                if (($meta['scene'] ?? 0) !== $n || ! hash_equals($meta['sha256'] ?? '', $sha) || ! $size || $size['mime'] !== 'image/png' || min($size[0], $size[1]) < 1024 || ($run->production_kind === 'coloring_book' ? $size[1] <= $size[0] : $size[0] <= $size[1])) {
                    throw new \RuntimeException('Invalid scene');
                }
                if (collect($saved)->contains(fn ($s) => ($s['source_sha256'] ?? $s['sha256']) === $sha)) {
                    throw new \RuntimeException('Duplicate scene');
                }
                $dest = 'orders/'.$order->id.'/codex-scenes/'.$run->id.'/scene-'.sprintf('%02d', $n).'.png';
                $disk = Storage::disk('s3_private');
                if (! $disk->put($dest, $bytes, ['visibility' => 'private']) || ! hash_equals($sha, hash('sha256', $disk->get($dest)))) {
                    throw new \RuntimeException('Storage verification failed');
                }
                $artwork = null;
                $sourceSha = $sha;
                if (($run->snapshot['artwork_contract'] ?? '') === 'a4-interior-v1') {
                    $artwork = BookArtwork::a4($bytes);
                    $bytes = $artwork['bytes'];
                    unset($artwork['bytes']);
                    $dest = 'orders/'.$order->id.'/codex-scenes/'.$run->id.'/page-'.sprintf('%02d', $n).'-a4.png';
                    $sha = hash('sha256', $bytes);
                    $size = getimagesizefromstring($bytes);
                    if (! $disk->put($dest, $bytes, ['visibility' => 'private']) || ! hash_equals($sha, hash('sha256', $disk->get($dest)))) {
                        throw new \RuntimeException('Storage verification failed');
                    }
                }
                $attachment = OrderAttachment::create(['order_id' => $order->id, 'uploaded_by_user_id' => null, 'production_unit_key' => $run->production_kind === 'coloring_book' ? $run->snapshot['unit_key'] : 'story:'.$order->id, 'disk' => 's3_private', 'path' => $dest, 'original_name' => 'Codex-scene-'.sprintf('%02d', $n).'.png', 'mime_type' => 'image/png', 'size' => strlen($bytes), 'note' => 'Codex / '.$run->id.' / مشهد '.$n.($artwork ? ' / A4 / '.$artwork['effective_ppi'].' PPI فعلية — راجع جودة الطباعة' : '')]);
                $saved[$key] = ['attachment_id' => $attachment->id, 'path' => $dest, 'sha256' => $sha, 'width' => $size[0], 'height' => $size[1], 'source_sha256' => $sourceSha, 'artwork' => $artwork];
                AutomationActivity::record($order, 'codex_scene_saved', 'توليد وحفظ مشهد '.$n.' من '.($run->scene_count ?? 13).' عبر Codex.', ['run_id' => $run->id, 'scene' => $n, 'sha256' => $sha]);
            }
            ksort($saved, SORT_NUMERIC);
            SceneProgress::collect($run, $state, $saved, $order);
            $run->scenes = $saved;
            $phase = $state['status'] ?? '';
            if ($phase === 'running' && in_array($run->status, ['queued', 'preparing', 'waiting_slot'], true)) {
                $run->status = 'running';
                $run->started_at = now();
            }
            if (in_array($phase, ['completed', 'needs_manual', 'stopped', 'failed', 'uncertain', 'quality_review'], true)) {
                if ($phase === 'completed' && count($saved) !== ($run->scene_count ?? 13)) {
                    throw new \RuntimeException('Missing scenes');
                }
                if ($phase === 'completed') {
                    $run->status = 'completed';
                    $run->active_slot = null;
                    $run->finished_at = now();
                    $incompleteSibling = $run->production_kind === 'coloring_book' && CodexSceneRun::where('order_id', $order->id)
                        ->where('production_kind', 'coloring_book')->whereKeyNot($run->id)->whereIn('status', ['needs_manual', 'quality_review', 'uncertain', 'stopped'])->exists();
                    $this->setStatus($order, $incompleteSibling ? 'pending_team_review' : 'ready_preview', $incompleteSibling
                        ? 'اكتملت صفحات هذا الكتاب؛ يوجد كتاب آخر في الطلب يحتاج مراجعة الفريق.'
                        : 'اكتملت مشاهد Codex؛ راجع الصور داخليًا ثم جهّز النص والملف في Studio.');
                } elseif ($phase === 'needs_manual') {
                    $run->status = 'needs_manual';
                    $run->error_code = 'partial_scenes';
                    $run->active_slot = null;
                    $run->finished_at = now();
                    $missing = array_values(array_diff(range(1, $run->scene_count ?? 13), array_map('intval', array_keys($saved))));
                    $this->setStatus($order, 'pending_team_review', 'انتهت محاولات Codex؛ استكمال المشاهد الناقصة يدويًا: '.implode('، ', $missing));
                    AutomationActivity::record($order, 'codex_scene_manual_handoff', 'تسليم القصة للتيم لاستكمال المشاهد الناقصة فقط.', ['run_id' => $run->id, 'missing_scenes' => $missing, 'saved_scenes' => count($saved)]);
                } elseif ($phase === 'stopped') {
                    $run->status = 'stopped';
                    $run->active_slot = null;
                    $run->finished_at = now();
                    $this->setStatus($order, $run->previous_status, 'توقف توليد Codex مع الاحتفاظ بالمشاهد المحفوظة.');
                } elseif ($phase === 'quality_review' && in_array($state['code'] ?? null, ['invalid_dimensions', 'invalid_png', 'oversized_scene', 'duplicate_scene', 'content_review'], true)) {
                    $run->status = 'quality_review';
                    $run->error_code = $state['code'];
                    if (isset($run->timing['finished_at'])) {
                        $run->finished_at = Carbon::createFromTimestampUTC($run->timing['finished_at']);
                    }
                } else {
                    $run->status = 'uncertain';
                    $run->error_code = 'codex_requires_review';
                }
            }
            $changed = $run->isDirty('status');
            $run->save();
            if ($changed) {
                AutomationActivity::record($order, 'codex_scene_progress', 'حالة توليد Codex: '.(['needs_manual' => 'تسليم المشاهد الناقصة للتيم', 'running' => 'جاري التوليد', 'completed' => 'اكتملت المشاهد للمعاينة الداخلية', 'stopped' => 'متوقفة', 'quality_review' => 'تحتاج مراجعة جودة: '.(CodexSceneRun::ERRORS[$run->error_code] ?? ''), 'uncertain' => 'تحتاج مراجعة'][$run->status] ?? $run->status), ['run_id' => $run->id, 'scene_count' => count($saved)]);
            }
        });
    }
}
