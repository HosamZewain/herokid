<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderProcessRun;
use App\Models\OrderStatusDefinition;
use App\Models\User;
use App\Services\Orders\AutomationHandoff;
use App\Services\ProductAutomation\PuzzleAutomation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ProcessLivePolicy
{
    public function enabled(): bool
    {
        $floor = config('order_process.after_order_id');

        $mode = config('order_process.enrollment_mode', 'all_new');
        $trialReady = $mode === 'all_new' || ($mode === 'trial'
            && $this->trialOrderIds() !== []
            && ctype_digit((string) config('order_process.trial_automation_id')) && (int) config('order_process.trial_automation_id') > 0);

        return $trialReady && config('order_process.enabled') && $floor !== null && ctype_digit((string) $floor)
            && config('queue.connections.order-process.driver') === 'redis';
    }

    public function allowsAutomation(int $id): bool
    {
        return config('order_process.enrollment_mode', 'all_new') === 'all_new'
            || (config('order_process.enrollment_mode') === 'trial' && $id === (int) config('order_process.trial_automation_id'));
    }

    public function allowsCheckout(string $key): bool
    {
        if (AutomationHandoff::held($key)) {
            return false;
        }
        if (config('order_process.enrollment_mode', 'all_new') === 'all_new') {
            return true;
        }
        if (config('order_process.enrollment_mode') !== 'trial') {
            return false;
        }
        // Include deleted siblings: the trial must never silently omit part of a checkout.
        $orders = Order::withTrashed()->where('checkout_group_key', $key)->orderBy('id')->get(['id', 'deleted_at']);

        return $orders->isNotEmpty() && ! $orders->contains(fn ($order) => $order->trashed())
            && $orders->pluck('id')->all() === $this->trialOrderIds();
    }

    private function trialOrderIds(): array
    {
        $primary = (string) config('order_process.trial_order_id');
        if (! ctype_digit($primary) || (int) $primary <= 0) {
            return [];
        }
        $manifest = config('order_process.trial_checkout_order_ids');
        if ($manifest === null) {
            return [(int) $primary];
        }
        if (! is_string($manifest)) {
            return [];
        }
        $values = array_map('trim', explode(',', $manifest));
        if (count($values) > 10 || collect($values)->contains(fn ($id) => ! ctype_digit($id) || (int) $id <= 0)) {
            return [];
        }
        $ids = array_map('intval', $values);
        sort($ids, SORT_NUMERIC);

        // Runtime uses the first checkout row as representative; never silently drop a row.
        return count(array_unique($ids)) === count($ids) && $ids[0] === (int) $primary ? $ids : [];
    }

    public function ownerReady(): bool
    {
        return $this->enabled() && config('order_process.confirmation_owner') === 'process';
    }

    public function ownsConfirmation(Order $order): bool
    {
        $floor = config('order_process.after_order_id');
        // Pausing execution must not silently re-enable the legacy sender.
        if (config('order_process.confirmation_owner') === 'process' && $floor !== null && ctype_digit((string) $floor) && $order->id > (int) $floor && $this->allowsCheckout($order->checkoutGroupKey())) {
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
            if (in_array($node['type'], ['generate_previews', 'approve_previews'], true) && (! config('product_review.enabled') || ! config('order_process.identity_enabled') || ! app(PuzzleAutomation::class)->enabled() || $definition['conditions']['type'] !== 'any' || $definition['conditions']['status'] !== 'new_confirmed' || $definition['conditions']['story_ids'] || $definition['conditions']['product_ids'])) {
                $errors[] = 'المعاينة المجمعة تحتاج تفعيلًا مستقلًا وتشمل كل أجزاء الطلب المؤكد.';
            }
            if (in_array($node['type'], ['approve_identity', 'approve_previews'], true) && ! OrderStatusDefinition::where('type', 'order')
                ->where('key', ProcessIdentityService::APPROVED_ORDER_STATUS)->where('is_active', true)->where('behavior', 'standard')->exists()) {
                $errors[] = 'اعتماد الهوية يحتاج حالة هوية مؤكدة وجاهزة للتوليد مفعلة كحالة تشغيل عادية.';
            }
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
                } elseif ($template === ProcessTemplates::REVIEW) {
                    if (! config('product_review.enabled') || ! config('product_review.template_ready')) {
                        $errors[] = 'قالب المعاينة المجمعة لم يعتمد للتنفيذ بعد.';
                    }
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

        $needsPhotos = collect($automation->revision?->definition['nodes'] ?? [])->contains(fn ($n) => in_array($n['type'], ['generate_identity', 'approve_identity', 'generate_previews', 'approve_previews'], true));

        return $actor && (! $needsPhotos || $actor->hasPermission('orders.photos.view')) && $actor->is_active && $actor->role === 'admin'
            && collect(['order_automation.view', 'order_automation.activate', 'order_automation.execute', 'orders.view', 'orders.update'])
                ->every(fn ($permission) => $actor->hasPermission($permission));
    }
}
