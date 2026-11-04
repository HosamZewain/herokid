<?php

namespace App\Models;

use App\Services\Orders\AutomationActivity;
use Illuminate\Database\Eloquent\Model;

class PuzzleGeneration extends Model
{
    protected $guarded = [];

    protected $casts = ['attempt_number' => 'integer', 'requested_by' => 'integer', 'previous_run_id' => 'integer', 'context' => 'encrypted:array', 'usage' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'lease_until' => 'datetime'];

    public const LABELS = ['queued' => 'بانتظار توليد صورة المنتج', 'processing' => 'جاري توليد صورة المنتج',
        'succeeded' => 'الصورة محفوظة في المرفقات والمعاينة', 'blocked' => 'يحتاج تدخل الفريق — لم يبدأ الإرسال',
        'retry_authorized' => 'نتيجة سابقة غير مؤكدة — أذن الفريق بمحاولة جديدة',
        'uncertain' => 'نتيجة التوليد غير مؤكدة — تحتاج قرار الفريق', 'review' => 'الصورة محفوظة للمراجعة — لم تُنشر في المعاينة'];

    public const ERRORS = [
        'puzzle_no_usable_photos' => 'لا توجد صورة صالحة للتوليد. ارفع صورة واضحة للطفل بصيغة JPG أو PNG أو WebP، أقل من 15 ميجابايت و25 مليون بكسل، ثم أعد الفحص.',
        'puzzle_photos_required' => 'صور الطفل ناقصة أو تتجاوز 10 صور. عدّل صور هذا الطفل ثم أعد الفحص.',
        'puzzle_invalid_photo' => 'أحد مراجع صور الطفل غير صالح. أعد رفع الصورة من تعديل الطلب ثم أعد الفحص.',
        'puzzle_photo_missing' => 'تعذر العثور على صورة الطفل المحفوظة. أعد رفعها؛ لا تبدأ التوليد من غير مراجع الطفل.',
        'puzzle_prompt_incomplete' => 'برومبت المنتج يحتوي بيانات ناقصة. أكمل بيانات الطفل والمنتج وحدّث البرومبت ثم أعد الفحص.',
        'puzzle_budget_required' => 'تكلفة التوليد تتجاوز الحد المسموح أو لم تُضبط. يراجع المدير إعدادات مزود الصور والميزانية.',
        'puzzle_single_image_required' => 'إعداد المنتج لا يحدد صورة واحدة للكفر أو المنتج. يراجع المدير مكونات الإنتاج.',
        'puzzle_book_child_requires_review' => 'اسم الطفل في تخصيص المنتج لا يطابق الطفل المرتبط به. صحح الربط من تعديل الطلب.',
        'puzzle_book_story_requires_review' => 'القصة المرتبطة بالكتاب لا تخص نفس الطفل. صحح ربط المنتج قبل التوليد.',
        'puzzle_book_variant_requires_review' => 'اختيار مقاس الكتاب أو عدد الصفحات غير مكتمل. يراجع المدير الاختيار ومكوناته.',
        'puzzle_book_structure_invalid' => 'مكونات الكتاب تحتاج كفرًا واحدًا وقسمًا مستقلًا للصفحات. يراجع المدير إعداد المنتج.',
        'puzzle_ineligible' => 'حالة الطلب أو نوع المنتج لا يسمحان ببدء التوليد. راجع تأكيد الطلب وإعداد المنتج.',

        'puzzle_result_uncertain' => 'انتهت المحاولة دون نتيجة مؤكدة؛ السبب التفصيلي غير متاح للمحاولة القديمة.',
        'puzzle_receipt_missing' => 'لم يؤكد العامل انتهاء المحاولة؛ يلزم فحص التنفيذ قبل إعادة التوليد.',
        'puzzle_provider_connection' => 'انقطع الاتصال بمزود الصور أو انتهت مهلة الانتظار دون استلام نتيجة مؤكدة.',
        'puzzle_provider_rejected' => 'رفض مزود الصور الطلب؛ راجع الصور والبرومبت قبل محاولة جديدة.',
        'puzzle_provider_rate_limit' => 'وصل مزود الصور إلى حد الطلبات؛ انتظر قليلًا قبل محاولة جديدة.',
        'puzzle_provider_unavailable' => 'تعذر الاتصال بخدمة الصور أو تهيئتها.',
        'puzzle_provider_billing' => 'رصيد مزود الصور أو حد الاستخدام يحتاج مراجعة.',
        'puzzle_provider_output' => 'لم تصل صورة صالحة من مزود الصور.',
        'puzzle_provider_failed' => 'فشل طلب مزود الصور؛ راجع تفاصيل المحاولة المسجلة.',
        'puzzle_inputs_failed' => 'تعذر تجهيز صور أو بيانات المنتج قبل الإرسال.',
        'puzzle_storage_failed' => 'تعذر التأكد من حفظ الصورة؛ راجع التخزين قبل محاولة جديدة.',
    ];

    public function photoWarning(): ?string
    {
        $excluded = $this->context['excluded_photos'] ?? [];
        if (! $excluded) {
            return null;
        }
        $numbers = implode('، ', array_map(fn ($photo) => (int) $photo['number'], $excluded));

        return 'يستخدم التوليد '.count($this->context['photos'] ?? []).' صورة صالحة لنفس الطفل. استُبعدت الصور رقم '.$numbers.' لأنها غير مدعومة أو تتجاوز حدود الصورة؛ الملفات الأصلية محفوظة.';
    }

    /** Worker completion is distinct from provider/billing certainty. */
    public function canAuthorizeUncertainRetry(): bool
    {
        return $this->status === 'uncertain' && $this->finished_at !== null && $this->lease_until === null
            && ! $this->output_path && ! $this->attachment_id && ! $this->preview_id;
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function item()
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $run) {
            $run->active_item_id = in_array($run->status, ['queued', 'processing', 'uncertain'], true) ? $run->order_item_id : null;
        });
        static::saved(function (self $run) {
            if (($run->wasRecentlyCreated && ! $run->getOriginal('id')) || $run->wasChanged('status')) {
                AutomationActivity::record($run->order, 'puzzle_progress', self::LABELS[$run->status] ?? $run->status,
                    ['run_id' => $run->id, 'attempt_number' => $run->attempt_number ?: 1, 'requested_by' => $run->requested_by, 'previous_run_id' => $run->previous_run_id, 'production_unit_key' => $run->unit_key, 'order_item_id' => $run->order_item_id,
                        'from' => $run->getOriginal('status'), 'to' => $run->status,
                        'attachment_id' => $run->attachment_id, 'preview_id' => $run->preview_id, 'error_code' => $run->error_code]);
            }
        });
    }
}
