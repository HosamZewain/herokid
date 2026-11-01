<?php

namespace App\Observers;

use App\Models\OrderPaymentReview;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessMessageAttempt;
use App\Models\OrderProcessRun;
use App\Models\RoboDeskIntegrationEvent;
use App\Services\Orders\AutomationActivity;
use Illuminate\Database\Eloquent\Model;

class AutomationActivityObserver
{
    public function saved(Model $model): void
    {
        $created = $model->wasRecentlyCreated && ! $model->getOriginal('id');
        $before = $model->getOriginal('status');
        $after = $model->getAttribute('status');
        $receiptField = $model instanceof RoboDeskIntegrationEvent ? 'response_payload' : 'receipt';
        $oldDelivery = data_get($model->getOriginal($receiptField), 'delivery.status');
        $delivery = data_get($model->getAttribute($receiptField), 'delivery.status');
        if (! $created && $before === $after && $delivery === $oldDelivery && ! $model->wasChanged('node_id')) {
            return;
        }
        $stageLabels = ['generate_identity' => 'توليد هويات القصص', 'approve_identity' => 'اعتماد هوية الطفل', 'whatsapp' => 'إرسال قالب واتساب', 'wait_response' => 'انتظار رد العميل', 'change_status' => 'تغيير حالة الطلب', 'manual_review' => 'مراجعة الفريق', 'condition' => 'فحص شرط المسار', 'end' => 'إنهاء المسار'];
        $stage = $stageLabels[$model->type ?? ''] ?? null;
        $key = null;
        $label = '';
        if ($model instanceof RoboDeskIntegrationEvent) {
            if ($model->event_type !== 'order.pending_confirmation' || data_get($model->payload, 'confirmation.eligible_at_creation') !== true) {
                return;
            }
            $key = $model->checkout_group_key;
            $label = 'رسالة تأكيد الطلب';
        } elseif ($model instanceof OrderProcessRun) {
            $key = $model->checkout_key;
            $label = 'مسار الأوتوميشن';
        } elseif ($model instanceof OrderPaymentReview) {
            $key = $model->checkout_key;
            $label = 'مراجعة مرفقات الدفع';
        } elseif ($model instanceof OrderProcessMessageAttempt) {
            $key = $model->step?->run?->checkout_key;
            $label = 'محاولة إرسال قالب واتساب';
        } else {
            $key = $model->run?->checkout_key;
            $label = $model instanceof OrderProcessIdentity ? 'توليد وحفظ هوية القصة' : 'خطوة الأوتوميشن: '.($stage ?? 'تنفيذ الخطوة');
        }
        if (! $key) {
            return;
        }
        $labels = ['pending' => 'في الانتظار', 'queued' => 'في انتظار التنفيذ', 'processing' => 'جاري التنفيذ',
            'running' => 'جاري التنفيذ', 'sending' => 'جاري الإرسال', 'succeeded' => 'نجحت', 'completed' => 'اكتملت',
            'failed' => 'فشلت', 'uncertain' => 'النتيجة غير مؤكدة', 'unknown' => 'نتيجة الإرسال غير مؤكدة', 'blocked' => 'تحتاج تدخل الفريق',
            'waiting_identity' => 'جاري توليد الهويات', 'waiting_response' => 'انتظار رد العميل',
            'waiting_staff' => 'انتظار مراجعة الفريق', 'waiting' => 'انتظار الرد', 'closed' => 'انتهت المراجعة',
            'cancelled' => 'متوقف', 'held' => 'موقوف للمراجعة', 'timed_out' => 'انتهت مهلة الرد', 'retry_pending' => 'إعادة إرسال في الانتظار', 'retry_queued' => 'إعادة إرسال في الانتظار'];
        $description = $label.' — '.($labels[$after] ?? $after ?? 'تم التسجيل');
        if (($model instanceof OrderProcessMessageAttempt && $after === 'completed') || ($model instanceof RoboDeskIntegrationEvent && $after === 'succeeded')) {
            $description = $label.' — قبلها RoboDesk؛ التسليم غير معروف حتى وصول إشعار التسليم';
        }
        if ($delivery !== $oldDelivery) {
            $description = $label.' — '.(['delivered' => 'وصلت للعميل', 'sent' => 'قيد التسليم', 'failed' => 'فشل التسليم — تواصل بقناة أخرى'][$delivery] ?? 'التسليم غير معروف');
        }
        // Explicit human commands log their actor in the command service. Automated
        // lifecycle effects stay Automation even when an admin request initiated a sync.
        if (($model instanceof OrderPaymentReview && $after === 'closed')
            || ($model instanceof OrderProcessRun && $after === 'cancelled')) {
            return;
        }
        AutomationActivity::record($model instanceof OrderProcessIdentity && $model->order ? $model->order : $key,
            'progress', $description, ['source_type' => class_basename($model), 'source_id' => $model->id,
                'stage' => $stage, 'from' => $before, 'to' => $after, 'delivery' => $delivery,
                'story_title' => $model instanceof OrderProcessIdentity ? $model->order?->story?->title : null,
                'child_name' => $model instanceof OrderProcessIdentity ? $model->order?->child_name : null]);
    }
}
