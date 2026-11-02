<?php

namespace App\Models;

use App\Services\Orders\AutomationActivity;
use Illuminate\Database\Eloquent\Model;

class PuzzleGeneration extends Model
{
    protected $guarded = [];

    protected $casts = ['context' => 'encrypted:array', 'usage' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'lease_until' => 'datetime'];

    public const LABELS = ['queued' => 'بانتظار توليد صورة المنتج', 'processing' => 'جاري توليد صورة المنتج',
        'succeeded' => 'الصورة محفوظة في المرفقات والمعاينة', 'blocked' => 'يحتاج تدخل الفريق — لم يبدأ الإرسال',
        'uncertain' => 'نتيجة التوليد غير مؤكدة — لا تُعِد التوليد تلقائيًا', 'review' => 'الصورة محفوظة للمراجعة — لم تُنشر في المعاينة'];

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
        static::saved(function (self $run) {
            if (($run->wasRecentlyCreated && ! $run->getOriginal('id')) || $run->wasChanged('status')) {
                AutomationActivity::record($run->order, 'puzzle_progress', self::LABELS[$run->status] ?? $run->status,
                    ['run_id' => $run->id, 'production_unit_key' => $run->unit_key, 'order_item_id' => $run->order_item_id,
                        'from' => $run->getOriginal('status'), 'to' => $run->status,
                        'attachment_id' => $run->attachment_id, 'preview_id' => $run->preview_id, 'error_code' => $run->error_code]);
            }
        });
    }
}
