<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CodexSceneRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['snapshot' => 'encrypted:array', 'timing' => 'array', 'scenes' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public const LABELS = [
        'waiting_slot' => 'في طابور الانتظار', 'preparing' => 'تجهيز الملفات',
        'queued' => 'جاهزة لبدء Codex', 'running' => 'جاري توليد المشاهد',
        'completed' => 'مكتملة — جاهزة للمعاينة', 'quality_review' => 'تحتاج مراجعة جودة — الصور محفوظة', 'uncertain' => 'تحتاج مراجعة — لن نعيد التوليد تلقائيًا',
        'stopping' => 'جارٍ الإيقاف', 'stopped' => 'متوقفة',
    ];

    public const ERRORS = [
        'invalid_dimensions' => 'أبعاد الصورة لا تطابق الحد المطلوب: صورة أفقية والضلع الأقصر 1024 بكسل على الأقل.',
        'invalid_png' => 'ملف الصورة الناتج ليس PNG صالحًا.',
        'oversized_scene' => 'ملف المشهد تجاوز الحجم المسموح به.',
        'duplicate_scene' => 'الصورة الناتجة تطابق مشهدًا سابقًا؛ يلزم مراجعتها.',
        'codex_requires_review' => 'نتيجة التوليد غير مؤكدة؛ راجع المحاولة قبل أي إعادة.',
        'sync_requires_review' => 'تعذر التحقق من بيانات القصة أو حفظ نتائجها؛ يلزم مراجعة المحاولة.',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
