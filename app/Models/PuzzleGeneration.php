<?php

namespace App\Models;

use App\Services\Orders\AutomationActivity;
use App\Services\ProductAutomation\ImageRecoveryPolicy;
use Illuminate\Database\Eloquent\Model;

class PuzzleGeneration extends Model
{
    protected $guarded = [];

    protected $casts = ['attempt_number' => 'integer', 'requested_by' => 'integer', 'previous_run_id' => 'integer', 'context' => 'encrypted:array', 'usage' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'lease_until' => 'datetime'];

    public const LABELS = ['queued' => 'بانتظار توليد صورة المنتج', 'processing' => 'جاري توليد صورة المنتج',
        'manual_artwork' => 'استخدام معاينة رفعها الفريق — المحاولة السابقة محفوظة',
        'succeeded' => 'الصورة محفوظة في المرفقات والمعاينة', 'blocked' => 'التوليد متعثر — راجع السبب والإجراء التالي',
        'retry_authorized' => 'نتيجة سابقة غير مؤكدة — أذن الفريق بمحاولة جديدة',
        'uncertain' => 'نتيجة التوليد غير مؤكدة — تحتاج قرار الفريق', 'review' => 'الصورة محفوظة للمراجعة — لم تُنشر في المعاينة'];

    public const ERRORS = [
        'puzzle_recovery_inputs_changed' => 'تغيّرت صور أو بيانات المنتج منذ المحاولة السابقة؛ راجع النسخة الحالية قبل بدء محاولة جديدة.',
        'puzzle_recovery_output_requires_review' => 'توجد نتيجة محفوظة أو تعذر التحقق من التخزين؛ راجع الملف قبل إعادة التوليد.',
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

    public function artworkContext(): array
    {
        return $this->status === 'manual_artwork' ? ($this->context['manual_artwork']['inputs'] ?? []) : ($this->context ?? []);
    }

    public function artworkPreviewId(): ?int
    {
        return $this->status === 'manual_artwork' ? ($this->context['manual_artwork']['preview_id'] ?? null) : $this->preview_id;
    }

    public function artworkChecksum(): ?string
    {
        return $this->status === 'manual_artwork' ? ($this->context['manual_artwork']['sha256'] ?? null) : $this->checksum;
    }

    public function hasArtwork(): bool
    {
        return in_array($this->status, ['succeeded', 'manual_artwork'], true);
    }

    public function failureDescription(bool $paused = false): ?string
    {
        if ($this->status === 'manual_artwork') {
            return 'اختار الفريق معاينة مرفوعة لهذا المنتج؛ تُستخدم بدل المحاولة السابقة، دون اعتبارها صورة ولّدها المزود. موافقة العميل تُسجل بشكل مستقل.';
        }
        if (! $this->error_code) {
            return null;
        }
        $recovery = app(ImageRecoveryPolicy::class)->forRun($this);
        if (in_array($recovery['reason_code'], ['IMAGE_RECOVERY_READY', 'IMAGE_RECOVERY_BACKOFF'], true)) {
            if ($paused) {
                return 'هذه المحاولة قابلة للتعافي الآلي، لكن الطلب متوقف يدويًا. بعد مراجعة البيانات استخدم استئناف الأوتوميشن.';
            }

            return 'الأوتو بايلوت يعالج العطل تلقائيًا بمحاولات محدودة بعد الانتظار؛ لا تكرر التوليد يدويًا. سيُعاد فحص الطلب قبل التشغيل.';
        }

        return match ($recovery['reason_code']) {
            'IMAGE_CONTENT_REVIEW_REQUIRED' => 'مزود الصور رفض المحتوى. راجع وصف التصميم وصور الطفل؛ لن تُعاد المحاولة تلقائيًا أو يُغيّر وصف العميل لتجاوز الرفض.',
            'IMAGE_PROVIDER_CONFIGURATION_REQUIRED' => 'المطلوب من المدير: مراجعة رصيد مزود الصور أو صلاحيات الموديل أو بيانات الاتصال حسب سبب المحاولة.',
            'IMAGE_RECOVERY_LIMIT_REACHED' => 'استُنفدت محاولات التعافي التلقائي لهذا المنتج. راجع سبب رفض المزود قبل محاولة جديدة.',
            default => self::ERRORS[$this->error_code] ?? 'تعذر إكمال التوليد؛ راجع بيانات المنتج والمحاولة الحالية.',
        };
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
