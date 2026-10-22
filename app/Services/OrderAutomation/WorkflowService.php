<?php

namespace App\Services\OrderAutomation;

use App\Jobs\AdvanceOrderAutomation;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderAutomationRun;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function publish(OrderAutomation $automation, int $version, User $actor): void
    {
        DB::transaction(function () use ($automation, $version, $actor): void {
            $locked = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            abort_unless((int) $locked->draft_version === $version, 409, 'المسودة تغيرت؛ حدّث الصفحة.');
            $definition = app(WorkflowDefinition::class)->validate($locked->draft, true);
            $revision = OrderAutomationRevision::create(['automation_id' => $locked->id, 'definition' => $definition,
                'checksum' => hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR)), 'published_by' => $actor->id, 'created_at' => now()]);
            // Editing/publishing must not bypass the separate activation/spending permission.
            $locked->update(['revision_id' => $revision->id, 'enabled' => false]);
            AdminActivityLogger::log('order_automation.published', 'نشر نسخة أتمتة', $locked, ['revision_id' => $revision->id], $actor);
        });
    }

    public function activate(OrderAutomation $automation, bool $enabled, User $actor): void
    {
        DB::transaction(function () use ($automation, $enabled, $actor): void {
            $locked = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            if ($enabled) {
                if (! config('order_automation.enabled')) {
                    throw ValidationException::withMessages(['enabled' => 'التشغيل العام مقفول على الخادم. يمكن إجراء تجربة منفردة فقط.']);
                }
                abort_unless($locked->revision_id, 422, 'انشر نسخة أولًا.');
                if (in_array('whatsapp', $locked->revision->definition['steps'], true) && ! config('order_automation.delivery_ready')) {
                    throw ValidationException::withMessages(['enabled' => 'ربط قالب واتساب لم يُعتمد بعد؛ أزل خطوة الإرسال أو انتظر تجهيز العقد.']);
                }
            }
            if ($enabled && ! $locked->enabled) {
                $locked->scan_after_id = (int) Order::withTrashed()->max('id');
            }
            $locked->enabled = $enabled;
            $locked->save();
            AdminActivityLogger::log('order_automation.activation', $enabled ? 'تفعيل أتمتة الطلبات الجديدة' : 'إيقاف الأتمتة', $locked, ['enabled' => $enabled], $actor);
        });
    }

    public function start(OrderAutomation $automation, Order $order, bool $dry, bool $trial, ?User $actor, string $requestKey): OrderAutomationRun
    {
        return DB::transaction(function () use ($automation, $order, $dry, $trial, $actor, $requestKey): OrderAutomationRun {
            $locked = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
            abort_unless($locked->revision_id, 422, 'انشر نسخة أولًا.');
            abort_unless($dry || $trial || ($locked->enabled && config('order_automation.enabled')), 422);
            $existing = OrderAutomationRun::where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->automation_id === $locked->id && $existing->order_id === $order->id && $existing->dry_run === $dry && $existing->trial === $trial, 409);

                return $existing;
            }
            // Serialize across different workflows too: the same story/child must not generate twice.
            $order = Order::lockForUpdate()->findOrFail($order->id);
            abort_unless(app(WorkflowInputs::class)->eligible($order, $locked->revision->definition), 422, 'الطلب غير مناسب لهذا المسار أو له هوية معتمدة بالفعل.');
            $key = 'story-identity:'.$order->id;
            if (! $dry && ($existing = OrderAutomationRun::where('live_key', $key)->first())) {
                return $existing;
            }
            $run = OrderAutomationRun::create(['automation_id' => $locked->id, 'revision_id' => $locked->revision_id, 'order_id' => $order->id,
                'actor_id' => $actor?->id, 'request_key' => $requestKey, 'live_key' => $dry ? null : $key, 'dry_run' => $dry, 'trial' => $trial,
                'status' => 'queued', 'next_at' => now(), 'steps' => [], 'context' => []]);
            AdminActivityLogger::log('order_automation.started', $dry ? 'محاكاة أتمتة بدون إرسال أو توليد' : 'تشغيل أتمتة', $order, ['run_id' => $run->id, 'trial' => $trial, 'dry_run' => $dry], $actor);
            AdvanceOrderAutomation::dispatch($run->id)->afterCommit();

            return $run;
        });
    }

    public function tick(): int
    {
        $count = 0;
        if (config('order_automation.enabled')) {
            foreach (OrderAutomation::where('enabled', true)->whereNotNull('revision_id')->get() as $automation) {
                DB::transaction(function () use ($automation, &$count): void {
                    $workflow = OrderAutomation::lockForUpdate()->findOrFail($automation->id);
                    if (! $workflow->enabled) {
                        return;
                    }
                    $orders = Order::where('id', '>', $workflow->scan_after_id)->orderBy('id')->limit(100)->get();
                    foreach ($orders as $order) {
                        if (app(WorkflowInputs::class)->eligible($order, $workflow->revision->definition)) {
                            $this->start($workflow, $order, false, false, null, 'automatic:'.$workflow->id.':'.$order->id);
                            $count++;
                        }
                        $workflow->scan_after_id = $order->id;
                    }
                    $workflow->save();
                });
            }
        }
        OrderAutomationRun::whereIn('status', ['queued', 'waiting_inputs', 'polling', 'generating'])
            ->where(fn ($q) => $q->whereNull('next_at')->orWhere('next_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
            ->orderBy('next_at')->limit(100)->get()->each(fn ($run) => AdvanceOrderAutomation::dispatch($run->id));

        return $count;
    }
}
