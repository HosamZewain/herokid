<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderProcessRun;
use App\Models\User;
use App\Services\OrderAutomation\Process\PreviewCohort;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\OrderAutomation\Process\ProcessOrderContext;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Explicit admission of one checkout, never replay of its historical jobs. */
class OrderAutomationAdmission
{
    public static function message(string $code): string
    {
        return match ($code) {
            'puzzle_no_usable_photos' => 'لا توجد صورة صالحة لهذا الطفل. ارفع صورة JPG أو PNG أو WebP؛ صيغة HEIC غير مدعومة في هذا المسار.',
            'puzzle_invalid_photo', 'unsupported_image' => 'إحدى صور الطفل غير مدعومة أو تتجاوز الحجم المسموح. ارفع صور JPG أو PNG أو WebP؛ صيغة HEIC غير مدعومة في هذا المسار.',
            'puzzle_photos_required', 'photos_required', 'puzzle_photo_missing' => 'صور الطفل ناقصة أو غير متاحة. راجع صور كل طفل ثم أعد الفحص.',
            'existing_workflow_or_manual_hold', 'existing_identity_or_output', 'existing_book_output_or_attempt', 'existing_product_attempt_requires_review' => 'يوجد عمل سابق أو إيقاف يدوي؛ راجع النتائج أو استأنف المسار الحالي بدل إنشاء مسار مكرر.',
            'book_manual_brief_requires_review' => 'برومبت كتاب التلوين القديم معدل يدويًا؛ يلزم مراجعته وفصل الكفر عن الصفحات قبل التشغيل.',
            'book_variant_requires_review', 'puzzle_book_variant_requires_review' => 'اختيار كتاب التلوين أو عدد صفحاته غير مكتمل. افتح تعديل الطلب وراجع اختيار ٢٠ أو ٤٠ صفحة والمقاس، ثم أعد الفحص. لا يلزم تسجيل دفع لتوليد الكفر.',
            'order_stage_requires_review', 'conditions_not_matched' => 'يلزم تأكيد كل عناصر الطلب، وألا تكون الطباعة أو الشحن قد بدأت.',
            'workflow_unavailable' => 'لا يوجد مسار معاينة واحد مفعّل ومطابق لهذا الطلب حاليًا؛ راجع إعداد الأوتوميشن.',
            default => 'بيانات الطلب أو إعدادات الإنتاج تحتاج مراجعة قبل الإدخال للأوتوميشن. رمز الفحص: '.$code,
        };
    }

    public function start(Order $order, User $actor, Request $request, int $version): OrderProcessRun
    {
        abort_unless(app(OrderAccess::class)->controls($order, $actor)
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
        try {
            return DB::transaction(function () use ($order, $actor, $request, $version) {
                $key = $order->checkoutGroupKey();
                DB::table('order_automation_controls')->insertOrIgnore(['checkout_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
                $control = DB::table('order_automation_controls')->where('checkout_key', $key)->lockForUpdate()->first();
                abort_unless($control->version === $version, 409, 'تغير وضع الطلب؛ حدّث الصفحة.');
                if ($control->state !== 'running' || CodexSceneRun::where('checkout_key', $key)->exists()) {
                    throw new RuntimeException('existing_workflow_or_manual_hold');
                }
                $contexts = app(ProcessOrderContext::class);
                $rows = $contexts->orders($key, true);
                if ($rows->contains(fn ($o) => $o->printing_status !== 'not_started' || $o->shipping_status !== 'not_ready')) {
                    throw new RuntimeException('order_stage_requires_review');
                }
                $snapshot = $contexts->snapshot($rows);
                $policy = app(ProcessLivePolicy::class);
                if (! $policy->enabled() || ! $policy->allowsCheckout($key, true)) {
                    throw new RuntimeException('workflow_unavailable');
                }
                $flows = OrderAutomation::with('revision')->where('enabled', true)->where('accepts_new_runs', true)->get()
                    ->filter(fn ($flow) => ($flow->revision?->definition['nodes'][0]['type'] ?? '') === 'generate_previews'
                        && $contexts->matches($flow->revision->definition, $snapshot) && $policy->allowsAutomation($flow->id) && $policy->authorized($flow));
                if ($flows->count() !== 1) {
                    throw new RuntimeException('workflow_unavailable');
                }
                $run = app(PreviewCohort::class)->enroll($key, $flows->sole());
                DB::table('order_automation_controls')->where('checkout_key', $key)->update(['error_code' => null, 'version' => $version + 1, 'updated_at' => now()]);
                AdminActivityLogger::log('order.automation_admitted_by_staff', 'إدخال الطلب المؤكد للأوتوميشن بطلب الموظف بعد فحص البيانات، دون إعادة رسالة التأكيد.', $order, ['run_id' => $run->id], $actor, $request);

                return $run;
            }, 3);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Persist only known codes, never provider/customer payloads. No admission,
            // upgrades, jobs or images survive the failed transaction.
            $code = preg_match('/\A[a-z_]{1,80}\z/', $e->getMessage()) ? $e->getMessage() : 'admission_requires_review';
            AdminActivityLogger::log('order.automation_admission_blocked', 'فحص إدخال الطلب للأوتوميشن: '.self::message($code), $order, ['reason_code' => $code], $actor, $request);
            throw ValidationException::withMessages(['automation' => self::message($code)]);
        }
    }
}
