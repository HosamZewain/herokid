<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderProcessRun;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProcessLivePolicy
{
    public function enabled(): bool
    {
        $floor = config('order_process.after_order_id');

        return config('order_process.enabled') && $floor !== null && ctype_digit((string) $floor)
            && config('queue.connections.order-process.driver') === 'redis';
    }

    public function ownerReady(): bool
    {
        return $this->enabled() && config('order_process.confirmation_owner') === 'process';
    }

    public function ownsConfirmation(Order $order): bool
    {
        $floor = config('order_process.after_order_id');
        // Pausing execution must not silently re-enable the legacy sender.
        if (config('order_process.confirmation_owner') === 'process' && $floor !== null && ctype_digit((string) $floor) && $order->id > (int) $floor) {
            return true;
        }

        // Keep durable ownership when a later child is added after a config rollback.
        return Schema::hasTable('order_process_runs') && OrderProcessRun::where('checkout_key', $order->checkoutGroupKey())->exists();
    }

    public function check(array $definition): void
    {
        $errors = [];
        if (($definition['schema'] ?? null) !== 2 || $definition['scope'] !== 'checkout') {
            $errors[] = 'التنفيذ الحالي يدعم نطاق عملية الشراء؛ تنفيذ وحدات القصص والمنتجات يحتاج ربط الإنتاج.';
        }
        foreach ($definition['nodes'] ?? [] as $node) {
            if (in_array($node['type'], ['generate_identity', 'approve_identity'], true)
                && (! config('order_process.identity_enabled') || (float) config('order_process.identity_budget_usd') <= 0
                    || config('queue.connections.order-process-ai.retry_after') <= 270
                    || $definition['conditions']['type'] !== 'story' || $definition['conditions']['status'] !== 'new_confirmed')) {
                $errors[] = 'توليد الهوية يحتاج تفعيلًا مستقلًا وميزانية وعامل التوليد وطلبات قصص مؤكدة.';
            }
            if ($node['type'] === 'whatsapp') {
                $template = $node['config']['template'];
                if (! ProcessTemplates::fields($template) || $node['config']['variables'] !== ProcessTemplates::fields($template)) {
                    $errors[] = 'القالب أو ترتيب متغيراته غير معتمد للتنفيذ.';
                } elseif ($template === ProcessTemplates::IDENTITY) {
                    if (! config('order_process.identity_template_ready') || ! config('order_process.identity_enabled')) {
                        $errors[] = 'قالب معاينة الهوية يحتاج تأكيد موافقة ميتا ومزامنته مع روبوديسك وتفعيل الإرسال.';
                    }
                } elseif (! $this->ownerReady() || $definition['conditions']['status'] !== 'new') {
                    $errors[] = 'نقل ملكية التأكيد مطلوب؛ قالب التأكيد للطلبات الجديدة غير المؤكدة فقط.';
                }
            }
            if ($node['type'] === 'change_status' && ! in_array([$node['config']['from'], $node['config']['to']], [['new', 'new_confirmed'], ['new_confirmed', 'under_review']], true)) {
                $errors[] = 'انتقال الحالة يحتاج ربط قواعده قبل التنفيذ؛ المتاح حاليًا التأكيد ثم المراجعة فقط.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['runtime' => array_unique($errors)]);
        }
    }

    public function authorized(OrderAutomation $automation): bool
    {
        $actor = User::find($automation->process_activated_by);

        $needsPhotos = collect($automation->revision?->definition['nodes'] ?? [])->contains(fn ($n) => in_array($n['type'], ['generate_identity', 'approve_identity'], true));

        return $actor && (! $needsPhotos || $actor->hasPermission('orders.photos.view')) && $actor->is_active && $actor->role === 'admin'
            && collect(['order_automation.view', 'order_automation.activate', 'order_automation.execute', 'orders.view', 'orders.update'])
                ->every(fn ($permission) => $actor->hasPermission($permission));
    }
}
