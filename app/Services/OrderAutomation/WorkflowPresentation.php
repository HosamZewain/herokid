<?php

namespace App\Services\OrderAutomation;

class WorkflowPresentation
{
    public static function status(string $status): string
    {
        return ['queued' => 'في الانتظار', 'waiting_inputs' => 'انتظار البيانات أو مساحة تشغيل', 'generating' => 'جاري إرسال طلب التوليد', 'polling' => 'انتظار نتيجة المزوّد', 'review' => 'تحتاج مراجعة موظف', 'preview_ready' => 'المعاينة محفوظة — لم ترسل للعميل', 'awaiting_customer' => 'انتهى المسار — انتظار موافقة العميل', 'simulated' => 'اكتملت المحاكاة بدون تكلفة', 'blocked' => 'متوقف — يحتاج تدخل', 'uncertain' => 'نتيجة التوليد غير مؤكدة — لا تعِد الإرسال', 'cancelled' => 'أُلغي التشغيل'][$status] ?? $status;
    }

    public static function error(?string $code): string
    {
        if ($code === 'trial_delivery_prohibited') {
            return 'انتهت تجربة التوليد؛ المعاينة محفوظة. إرسال واتساب ممنوع في التجربة المنفردة.';
        }
        if ($code === 'estimate_exceeds_limit') {
            return 'تقدير تكلفة الموديل غير متاح أو أعلى من حد المحاولة.';
        }
        if ($code === 'actor_revoked') {
            return 'صلاحية الموظف الذي بدأ التجربة أُلغيت؛ توقف التشغيل.';
        }

        return ['missing_inputs' => 'بيانات الطفل أو صوره غير مكتملة.', 'inputs_changed' => 'بيانات الطفل أو الصور أو سياق القصة تغيرت؛ النتيجة تحتاج مراجعة.', 'order_ineligible' => 'الطلب ألغي أو حُذف أو لديه هوية معتمدة أو لم يعد يطابق المسار.', 'budget_limit' => 'حد الميزانية اليومية لا يسمح بمحاولة أخرى.', 'concurrency_limit' => 'انتظار انتهاء التوليدات الحالية.', 'provider_unavailable' => 'المزوّد أو الموديل أو بيانات الربط غير جاهزة.', 'provider_unknown' => 'قد يكون المزوّد استلم الطلب واحتسب تكلفته؛ يلزم التحقق قبل أي توليد جديد.', 'delivery_not_configured' => 'لم يُعتمد ربط قالب واتساب بعد؛ المعاينة محفوظة ويمكن مراجعتها.', 'provider_timeout' => 'انتهت مهلة متابعة المزوّد. يلزم التحقق من النتيجة.', 'output_missing' => 'ملف المعاينة غير متاح أو تغير.', 'invalid_photo' => 'صيغة صورة مرجعية غير صالحة.', 'photo_too_large' => 'صورة مرجعية تتجاوز الحد المسموح.'][$code] ?? ($code ? 'تعذر استكمال الخطوة. راجع الإعدادات والبيانات.' : '');
    }
}
