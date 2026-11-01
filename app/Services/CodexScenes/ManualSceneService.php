<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\User;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\OrderSceneTextService;
use App\Support\AdminActivityLogger;
use App\Support\OrderStatusRegistry;
use App\Support\StoryProductionPrompt;
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
        if ($order->trashed() || ! $order->story_id || ! in_array($order->status, ['costomer_confirmed', 'new_confirmed'], true)) {
            return 'التجربة متاحة لقصة مؤكدة قبل بدء الإنتاج فقط.';
        }
        $attempt = $order->childIdentityApprovedAttempt;
        if (! $attempt || $attempt->status !== 'succeeded' || ! $attempt->output_storage_path || ! $attempt->output_checksum) {
            return 'ارفع واعتمد هوية الطفل أولًا.';
        }
        if (! Order::where('checkout_group_key', $order->checkoutGroupKey())->where('paid_amount_cents', '>', 0)->exists()) {
            return 'يلزم تسجيل أي مبلغ مدفوع فعليًا على الطلب.';
        }
        if (app(AutomationAssignment::class)->owns($order)) {
            return 'الطلب ما زال تحت مسؤولية مسار آخر.';
        }
        if (CodexSceneRun::where('active_slot', 1)->exists()) {
            return 'هناك تجربة قيد التنفيذ؛ التجربة الحالية تسمح بقصة واحدة في كل مرة.';
        }

        return null;
    }

    public function fingerprint(Order $order): string
    {
        $order->loadMissing(['story', 'productionPromptOverride', 'childIdentityApprovedAttempt']);

        return hash('sha256', json_encode([$order->only(['story_id', 'child_name', 'child_age', 'child_gender', 'interests', 'lesson', 'gift_note', 'uploaded_photos', 'child_identity_approved_attempt_id']), $order->childIdentityApprovedAttempt?->output_checksum, $order->story?->updated_at?->toISOString(), $order->productionPromptOverride?->updated_at?->toISOString(), app(OrderSceneTextService::class)->present($order)['scenes']], JSON_UNESCAPED_UNICODE));
    }

    public function start(Order $order, User $user): CodexSceneRun
    {
        return Cache::lock('codex-scenes-start', 30)->block(3, function () use ($order, $user) {
            return DB::transaction(function () use ($order, $user) {
                $rows = Order::where('checkout_group_key', $order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
                $order = $rows->firstWhere('id', $order->id);
                if (! $order || ($reason = $this->reason($order))) {
                    throw ValidationException::withMessages(['codex_scenes' => $reason ?? 'الطلب غير متاح.']);
                }
                $texts = app(OrderSceneTextService::class)->present($order);
                if (! $texts['all_ready'] || count($texts['scenes']) !== 13) {
                    throw ValidationException::withMessages(['codex_scenes' => 'نصوص المشاهد الثلاثة عشر غير مكتملة.']);
                }
                foreach (['generating', 'ready_preview'] as $status) {
                    if (! OrderStatusRegistry::isValid('order', $status)) {
                        throw ValidationException::withMessages(['codex_scenes' => 'حالات الإنتاج المطلوبة غير مفعلة.']);
                    }
                }
                $attempt = $order->childIdentityApprovedAttempt;
                $run = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'checkout_key' => $order->checkoutGroupKey(), 'requested_by' => $user->id, 'status' => 'preparing', 'active_slot' => 1, 'previous_status' => $order->status, 'fingerprint' => $this->fingerprint($order), 'scenes' => [],
                    'snapshot' => ['prompt' => StoryProductionPrompt::forOrder($order), 'texts' => $texts['scenes'], 'identity_disk' => $attempt->output_disk, 'identity_path' => $attempt->output_storage_path, 'identity_checksum' => $attempt->output_checksum]]);
                $this->setStatus($order, 'generating', 'بدء تجربة المشاهد اليدوية بواسطة Codex.');
                AdminActivityLogger::log(action: 'order.codex_scenes_requested', description: 'طلب تجربة توليد 13 مشهدًا عبر اشتراك Codex.', subject: $order, properties: ['run_id' => $run->id], admin: $user);

                return $run;
            });
        });
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
            $dir = config('codex_scenes.spool').'/inbox/'.$run->id;
            if (! is_dir($dir)) {
                mkdir($dir, 02750, true);
                chmod($dir, 02750);
            }
            file_put_contents($dir.'/cancel', '1');
            chmod($dir.'/cancel', 0640);
            $run->update(['status' => 'stopping']);
            AdminActivityLogger::log(action: 'order.codex_scenes_stop_requested', description: 'طلب إيقاف تجربة Codex مع الاحتفاظ بالمشاهد المحفوظة.', subject: $run->order, properties: ['run_id' => $run->id], admin: $user);
        });
    }

    public function tick(): void
    {
        Cache::lock('codex-scenes-sync', 110)->get(function () {
            foreach (CodexSceneRun::where('active_slot', 1)->get() as $run) {
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
                        AutomationActivity::record($run->order, 'codex_scene_error', 'تجربة Codex تحتاج مراجعة؛ لن تتم إعادة التوليد تلقائيًا.', ['run_id' => $run->id]);
                        report(new \RuntimeException('Codex scene sync requires review: '.$run->id.' ('.class_basename($e).')'));
                    }
                }
            }
        });
    }

    private function prepare(CodexSceneRun $run): void
    {
        $order = $run->order;
        if (! hash_equals($run->fingerprint, $this->fingerprint($order))) {
            throw new \RuntimeException('Input changed');
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
        $data = ['id' => $run->id, 'order_id' => $run->order_id, 'identity_checksum' => $snapshot['identity_checksum'], 'scene_count' => 13, 'prompt' => $snapshot['prompt'], 'texts' => $snapshot['texts']];
        file_put_contents($dir.'/manifest.tmp', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        chmod($dir.'/manifest.tmp', 0640);
        DB::transaction(function () use ($run, $dir) {
            $locked = CodexSceneRun::lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'preparing') {
                return;
            }
            rename($dir.'/manifest.tmp', $dir.'/manifest.json');
            $locked->update(['status' => 'queued']);
            AutomationActivity::record($locked->order, 'codex_scene_queued', 'تجهيز برومبت القصة والهوية المعتمدة لتجربة Codex.', ['run_id' => $locked->id]);
        });
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
            if (($state['status'] ?? '') === 'stopped') {
                $run->update(['status' => 'stopped', 'active_slot' => null, 'finished_at' => now()]);
                if (! $order->trashed() && $order->status === 'generating') {
                    $this->setStatus($order, $run->previous_status, 'توقفت تجربة Codex مع الاحتفاظ بالمشاهد المحفوظة.');
                }
                AutomationActivity::record($order, 'codex_scene_stopped', 'تم إيقاف تجربة Codex وتحرير الطلب للتيم.', ['run_id' => $run->id]);

                return;
            }
            if ($order->trashed() || ! hash_equals($run->fingerprint, $this->fingerprint($order)) || $order->status !== 'generating') {
                throw new \RuntimeException('Input changed');
            }
            $saved = $run->scenes ?? [];
            foreach (range(1, 13) as $n) {
                $key = (string) $n;
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
                $bytes = file_get_contents($path);
                $sha = hash('sha256', $bytes);
                $size = @getimagesizefromstring($bytes);
                if (($meta['scene'] ?? 0) !== $n || ! hash_equals($meta['sha256'] ?? '', $sha) || ! $size || $size['mime'] !== 'image/png' || min($size[0], $size[1]) < 1024 || $size[0] <= $size[1]) {
                    throw new \RuntimeException('Invalid scene');
                }
                if (collect($saved)->contains(fn ($s) => $s['sha256'] === $sha)) {
                    throw new \RuntimeException('Duplicate scene');
                }
                $dest = 'orders/'.$order->id.'/codex-scenes/'.$run->id.'/scene-'.sprintf('%02d', $n).'.png';
                $disk = Storage::disk('s3_private');
                if (! $disk->put($dest, $bytes, ['visibility' => 'private']) || ! hash_equals($sha, hash('sha256', $disk->get($dest)))) {
                    throw new \RuntimeException('Storage verification failed');
                }
                $attachment = OrderAttachment::create(['order_id' => $order->id, 'uploaded_by_user_id' => null, 'production_unit_key' => 'story:'.$order->id, 'disk' => 's3_private', 'path' => $dest, 'original_name' => 'Codex-scene-'.sprintf('%02d', $n).'.png', 'mime_type' => 'image/png', 'size' => strlen($bytes), 'note' => 'Codex / '.$run->id.' / مشهد '.$n]);
                $saved[$key] = ['attachment_id' => $attachment->id, 'path' => $dest, 'sha256' => $sha, 'width' => $size[0], 'height' => $size[1]];
                AutomationActivity::record($order, 'codex_scene_saved', 'توليد وحفظ مشهد '.$n.' من 13 عبر Codex.', ['run_id' => $run->id, 'scene' => $n, 'sha256' => $sha]);
            }
            $run->scenes = $saved;
            $phase = $state['status'] ?? '';
            if ($phase === 'running' && in_array($run->status, ['queued', 'preparing'], true)) {
                $run->status = 'running';
                $run->started_at = now();
            }
            if (in_array($phase, ['completed', 'stopped', 'failed', 'uncertain'], true)) {
                if ($phase === 'completed' && count($saved) !== 13) {
                    throw new \RuntimeException('Missing scenes');
                }
                if ($phase === 'completed') {
                    $run->status = 'completed';
                    $run->active_slot = null;
                    $run->finished_at = now();
                    $this->setStatus($order, 'ready_preview', 'اكتملت مشاهد Codex؛ راجع الصور داخليًا ثم جهّز النص والملف في Studio.');
                } elseif ($phase === 'stopped') {
                    $run->status = 'stopped';
                    $run->active_slot = null;
                    $run->finished_at = now();
                    $this->setStatus($order, $run->previous_status, 'توقفت تجربة Codex مع الاحتفاظ بالمشاهد المحفوظة.');
                } else {
                    $run->status = 'uncertain';
                    $run->error_code = 'codex_requires_review';
                }
            }
            $changed = $run->isDirty('status');
            $run->save();
            if ($changed) {
                AutomationActivity::record($order, 'codex_scene_progress', 'حالة تجربة Codex: '.(['running' => 'جاري التوليد', 'completed' => 'اكتملت المشاهد للمعاينة الداخلية', 'stopped' => 'متوقفة', 'uncertain' => 'تحتاج مراجعة'][$run->status] ?? $run->status), ['run_id' => $run->id, 'scene_count' => count($saved)]);
            }
        });
    }
}
