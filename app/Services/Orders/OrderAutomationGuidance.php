<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\PuzzleGeneration;

/** Customer-safe explanations shared by list, detail and polling. */
class OrderAutomationGuidance
{
    private const REASONS = [
        'manual_artwork_review' => 'سُجل اعتماد كفر بديل بواسطة الفريق. تابع صفحات الكتاب من بند الكتاب؛ باقي عناصر الطلب تحتاج اعتمادها ومراجعتها بشكل مستقل.',
        'order_ineligible' => 'أكد بيانات كل أجزاء الطلب أولًا؛ الاستكمال متاح للطلب المؤكد قبل بدء الطباعة والشحن.',
        'identity_disabled' => 'توليد هوية القصة غير مفعّل. يراجع المدير إعداد الهوية قبل استكمال المعاينة.',
        'identity_scope_incomplete' => 'مسار المعاينة لا يشمل كل قصص الطلب. يراجع المدير نطاق المسار.',
        'identity_already_claimed' => 'توجد محاولة هوية سابقة لهذه القصة. راجعها من قسم الهوية؛ لن تُعاد تلقائيًا.',
        'preview_disabled' => 'تجهيز معاينات المنتجات غير مفعّل. يراجع المدير إعداد تشغيل المعاينات وتوليد المنتجات.',
        'preview_budget_exceeded' => 'مجموع تكلفة معاينات الطلب يتجاوز ميزانية هذه المرحلة. يراجع المدير ميزانية المسار؛ لم يبدأ توليد جديد.',
        'preview_product_changed' => 'بيانات هذا المنتج تغيّرت بعد تجهيز معاينته. راجع الصورة الحالية: سجّل موافقة العميل عليها إن كانت مناسبة، أو أعد توليد هذا المنتج فقط بالبيانات الجديدة.',
        'preview_product_result_review' => 'محاولة هذا المنتج تحتاج مراجعة. افتح بند المنتج لرؤية الصورة المحفوظة أو سبب التعثر وخيار إعادة التوليد؛ لن تُكرر تلقائيًا.',
        'preview_existing_attachment' => 'يوجد ملف إنتاج سابق لهذا المنتج أو ملف غير مرتبط بمنتج محدد. راجعه من المرفقات وحدد علاقته بالمنتج قبل بدء توليد جديد.',
        'preview_worker_delayed' => 'لم يكتمل تجهيز هذا المنتج خلال 6 ساعات. يراجع المدير العامل والمحاولة الحالية قبل إعادة التشغيل.',
        'preview_image_unavailable' => 'صورة المعاينة غير متاحة أو ارتباطها بهذا المنتج غير مطابق. راجع الصورة من المعاينات؛ يلزم إصلاح الربط قبل الإرسال.',
        'preview_requires_review' => 'توقف تجهيز المعاينة قبل اكتمالها. افحص بيانات المنتجات والصور من الإجراء الموضح أدناه.',
        'inputs_changed' => 'تغيّرت البيانات بعد بدء المسار. راجع الصور والبيانات الحالية قبل الاستكمال.',
        'live_policy_changed' => 'إعداد تشغيل الأوتوميشن أو صلاحياته تغيّرت. يحتاج المدير لمراجعتها.',
        'actor_revoked' => 'حساب المسؤول عن تشغيل المسار لم يعد لديه الصلاحية المطلوبة.',
        'contact_invalid' => 'رقم تواصل العميل غير صالح. صححه من تعديل الطلب.',
        'missing_inputs' => 'بيانات الطفل أو صوره ناقصة. أكملها من تعديل الطلب.',
        'invalid_photo' => 'صورة الطفل غير مدعومة أو غير صالحة. ارفع JPG أو PNG أو WebP.',
        'photo_too_large' => 'صورة الطفل تتجاوز الحجم المسموح. ارفع نسخة أصغر واضحة.',
        'identity_approval_missing' => 'لم تُسجل موافقة على الهوية الحالية. راجعها وسجّل الموافقة إذا وافق العميل.',
        'identity_provider_unavailable' => 'خدمة توليد الهوية غير متاحة. يراجع المدير إعداد مزود الصور.',
        'identity_budget_required' => 'ميزانية توليد الهوية تحتاج مراجعة من المدير.',
        'identity_existing_review' => 'توجد هوية محفوظة تحتاج مراجعة قبل إعادة التوليد.',
        'identity_requires_review' => 'توليد الهوية يحتاج مراجعة. افتح الهوية المحفوظة وتفاصيل محاولتها.',
        'missing_send_receipt' => 'إرسال الرسالة غير محسوم. تحقق من المحادثة قبل أي إعادة إرسال.',
        'send_receipt_missing' => 'إرسال الرسالة غير محسوم. تحقق من المحادثة قبل أي إعادة إرسال.',
        'send_result_uncertain' => 'قد تكون الرسالة وصلت بالفعل. راجع المحادثة؛ لا تُكرر الإرسال تلقائيًا.',
        'worker_stop_unconfirmed' => 'لم يتأكد انتهاء العامل. لا تبدأ تنفيذًا موازيًا؛ يلزم فحص العامل.',
        'settlement_requires_review' => 'الإيقاف لم يكتمل بسبب خطوة غير محسومة. راجع تفاصيل التنفيذ قبل الاستكمال.',
    ];

    public static function reason(?string $reason): ?string
    {
        if ($reason === null || ! preg_match('/\A[a-z][a-z0-9_]+\z/', $reason)) {
            return $reason;
        }

        return PuzzleGeneration::ERRORS[$reason] ?? self::REASONS[$reason] ?? CodexSceneRun::ERRORS[$reason]
            ?? 'تعذر إكمال هذه المرحلة. احتفظ برقم الطلب ورقم المتابعة عند التصعيد للمدير؛ لا تكرر التوليد أو تغيّر حالة الطلب لتجاوز العطل.';
    }

    public static function destination(string $stage): array
    {
        return match ($stage) {
            'control', 'preview' => ['anchor' => 'order-automation-controls', 'label' => 'فتح التحكم في الأوتوميشن'],
            'payment' => ['anchor' => 'order-statuses', 'label' => 'مراجعة الدفع المسجل'],
            'identity', 'story_pages' => ['anchor' => 'order-stories', 'label' => 'فتح الهوية ومشاهد القصة'],
            'product', 'book_cover', 'book_pages' => ['anchor' => 'order-products', 'label' => 'فتح المنتج وإجراءات التوليد'],
            default => ['anchor' => 'order-follow-up', 'label' => 'فتح ملاحظات ومرفقات الطلب'],
        };
    }

    public static function next(string $stage, string $state, ?string $code): ?string
    {
        if ($state === 'running') {
            return 'التنفيذ جارٍ والتقدم يتحدث تلقائيًا؛ لا تبدأ نفس العمل يدويًا.';
        }
        if ($state === 'waiting') {
            return match ($stage) {
                'payment' => 'بعد استلام الدفع، سجّل المبلغ الفعلي من قسم الدفع.',
                'confirmation' => 'انتظر تأكيد العميل للطلب؛ راجع المحادثة إذا تأخر الرد.',
                'book_pages' => 'راجع شرط البدء الموضح أعلاه: اعتماد الكفر وتسجيل دفعة فعلية مطلوبان قبل الصفحات.',
                default => 'انتظر رد العميل؛ لو وافق خارج الزر استخدم تسجيل الموافقة بواسطة الفريق.',
            };
        }
        if ($state === 'queued') {
            return 'الطلب في الطابور؛ تابع آخر نشاط، ولا تُنشئ محاولة مكررة.';
        }
        if ($state !== 'intervention') {
            return null;
        }
        if ($code && (in_array($code, ['puzzle_provider_unavailable', 'puzzle_provider_billing', 'identity_provider_unavailable', 'preview_disabled', 'preview_worker_delayed'], true) || str_contains($code, 'budget') || str_contains($code, 'policy') || $code === 'actor_revoked')) {
            return 'المطلوب من المدير: مراجعة إعداد الخدمة أو صلاحياتها، ثم إعادة الفحص.';
        }

        return match ($stage) {
            'payment' => 'المطلوب من الفريق: مراجعة مرفق العميل والدفع المسجل؛ لا تسجّل نفس الدفعة مرتين.',
            'control' => 'افتح التحكم في الأوتوميشن. بعد تأكيد الإيقاف وتصحيح البيانات استخدم الاستئناف.',
            'confirmation' => 'راجع المحادثة وحالة محاولة التأكيد قبل إعادة الإرسال.',
            'identity' => 'افتح قسم الهوية؛ راجع الصورة واعتمد الموافقة أو استخدم إعادة التوليد بالبيانات الحالية.',
            'product', 'book_cover' => 'افتح بند المنتج أسفل الطلب؛ راجع سبب المحاولة واستخدم الإجراء المتاح لها.',
            'book_pages', 'story_pages' => 'افتح قسم الصفحات؛ راجع شروط البدء أو الصفحات الناقصة وسببها. الصور المكتملة تظل محفوظة.',
            'preview' => 'استخدم فحص واستكمال إن ظهر، أو صحح البيانات من تعديل الطلب ثم استأنف. لا تغيّر حالة الطلب لتجاوز السبب.',
            default => 'راجع تفاصيل المراحل أدناه، وأنهِ الإجراء المطلوب قبل استكمال الطلب.',
        };
    }
}
