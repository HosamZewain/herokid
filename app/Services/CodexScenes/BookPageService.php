<?php

namespace App\Services\CodexScenes;

use App\Exceptions\LegacyBookReviewRequired;
use App\Models\CodexSceneRun;
use App\Models\LegacyBookCoverApproval;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPreview;
use App\Models\OrderProcessEvent;
use App\Models\OrderReviewManifest;
use App\Models\PuzzleGeneration;
use App\Models\Setting;
use App\Services\OrderAutomation\Process\ProcessProductReview;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\AutomationControl;
use App\Services\Orders\AutomationHandoff;
use App\Services\Orders\BookPromptSync;
use App\Services\Orders\LegacyBookAdmission;
use App\Services\Orders\PreviewProductReuse;
use App\Services\Orders\StaffPreviewInputs;
use App\Services\ProductAutomation\PuzzleInputs;
use App\Support\ProductProductionPrompt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Book interiors share the bounded Codex pool, but never a story's identity or unit key. */
class BookPageService
{
    public function workerReady(): bool
    {
        if (! app(ManualSceneService::class)->health()) {
            return false;
        }
        $health = json_decode(file_get_contents(config('codex_scenes.spool').'/outbox/health.json'), true);

        return in_array(6, $health['supported_protocols'] ?? [], true);
    }

    private function approvedInputs(OrderItem $item, ?OrderReviewManifest $manifest, bool $pausedResume = false): array
    {
        $manualApproval = LegacyBookCoverApproval::where('order_item_id', $item->id)->latest('id')->first();
        if ($manualApproval && (! $manifest || ($manualApproval->configuration_before['source'] ?? null) === 'manual_replacement')) {
            return app(LegacyBookAdmission::class)->approvedInputs($item);
        }
        if (! $manifest) {
            throw new RuntimeException('Book approval unavailable');
        }
        $item->loadMissing('order', 'productionComponents', 'product.productionComponents');
        if (! $manifest->approved_at || $manifest->checkout_key !== $item->order->checkoutGroupKey()
            || OrderReviewManifest::where('checkout_key', $manifest->checkout_key)->max('id') !== $manifest->id
            || ! in_array($manifest->run?->status, $pausedResume ? ['paused', 'waiting_staff'] : ['running', 'waiting_staff', 'completed'], true)) {
            throw new RuntimeException('Book approval unavailable');
        }
        $image = collect($manifest->images)->first(fn ($image) => ($image['kind'] ?? '') === 'book_cover' && ($image['item_id'] ?? 0) === $item->id);
        $interior = ProductProductionPrompt::forItem($item, true)->firstWhere('production_stage', 'book_interior');
        $generation = PuzzleGeneration::where('order_item_id', $item->id)->latest('id')->first();
        $staffInputs = app(StaffPreviewInputs::class)->recorded($manifest);
        $currentCoverInputs = app(PuzzleInputs::class)->captureCurrent($item);
        $currentCover = $currentCoverInputs['fingerprint'];
        $retained = isset($image['retained_input_fingerprint']) && hash_equals($image['retained_input_fingerprint'], $currentCover)
            && $generation && PreviewProductReuse::matches($manifest->run, $item, $generation, $currentCoverInputs);
        $currentInterior = app(ProcessProductReview::class)->interiorFingerprint($item);
        if (! $image || ! $interior || ! $generation || $generation->id !== $image['generation_id'] || $generation->status !== 'succeeded'
            || (! $retained && ! hash_equals($image['input_fingerprint'], $currentCover) && ! hash_equals($staffInputs[(string) $item->id]['input_fingerprint'] ?? '', $currentCover))
            || (! app(BookPromptSync::class)->matchesInterior($item, $image['interior_fingerprint'] ?? '') && ! app(BookPromptSync::class)->matchesInterior($item, $staffInputs[(string) $item->id]['interior_fingerprint'] ?? ''))
            || ! $generation->preview_id || $generation->preview_id !== $image['preview_id']) {
            throw new RuntimeException('Book inputs changed after approval');
        }
        $preview = OrderPreview::find($generation->preview_id);
        if (! $preview || $preview->productGallery?->status !== 'active' || $preview->file_path !== $image['path'] || $preview->checksum !== $image['sha256']) {
            throw new RuntimeException('Book preview revoked');
        }
        app(ProcessProductReview::class)->bytes($image);
        $count = (int) $interior['quantity_per_item'];
        if ($count < 1 || $count > config('product_review.max_pages', 1000) || trim($interior['prompt']) === '' || preg_match('/\{\{|\[MISSING/', $interior['prompt'])) {
            throw new RuntimeException('Book pages incomplete');
        }

        $references = app(BookReferences::class)->capture($item, $retained ? $currentCoverInputs['photos'] : $generation->context['photos'], $count);

        return ['manifest_id' => $manifest->id, 'item_id' => $item->id, 'unit_key' => $interior['unit_key'], 'page_count' => $count,
            'artwork_contract' => 'a4-interior-v1', 'references' => $references['references'], 'texts' => $references['texts'],
            'input_fingerprint' => $currentCover, 'interior_fingerprint' => app(ProcessProductReview::class)->interiorFingerprint($item),
            'prompt' => $interior['prompt'], 'identity_disk' => $image['disk'], 'identity_path' => $image['path'], 'identity_checksum' => $image['sha256']];
    }

    /** Validate actual approved artwork and references before explicitly releasing a manual hold. */
    public function validatePausedResume(OrderItem $item, OrderReviewManifest $manifest): void
    {
        try {
            $this->approvedInputs($item, $manifest, true);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['assignment' => $this->inputFailureReason($e)]);
        }
    }

    private function inputFailureReason(RuntimeException $e): string
    {
        if ($e instanceof LegacyBookReviewRequired) {
            return $e->getMessage();
        }

        return match ($e->getMessage()) {
            'Book approval unavailable' => 'اعتماد الكفر غير صالح للمسار الحالي؛ راجع المعاينة.',
            'Book inputs changed after approval' => 'تغيرت بيانات الكتاب أو صوره بعد اعتماد الكفر؛ راجع المعاينة الحالية وسجل موافقة العميل عليها قبل التشغيل.',
            'Book preview revoked' => 'معاينة الكفر سُحبت أو تغيرت؛ يلزم اعتماد المعاينة الحالية.',
            'Book pages incomplete' => 'برومبت صفحات الكتاب ناقص أو يحتوي متغيرات غير مكتملة؛ راجع برومبت الإنتاج وعدد الصفحات.',
            'book_story_link_ambiguous' => 'ربط كتاب التلوين بالقصة غير مطابق للطفل؛ راجع المنتج والقصة المرتبطة به.',
            'book_waiting_linked_story' => 'صفحات التلوين تنتظر اكتمال القصة المرتبطة بالطفل واعتماد هويتها.',
            'book_story_pages_incomplete' => 'مشاهد القصة المرتبطة غير مكتملة أو عدد صفحات الكتاب أقل منها؛ راجع المشاهد وعدد الصفحات.',
            'book_story_reference_changed' => 'أحد مراجع القصة المرتبطة تغير أو لم يعد متاحًا؛ راجع مرفقات القصة.',
            'book_reference_checksum' => 'تعذر التحقق من إحدى صور الطفل أو القصة؛ راجع الصور الأصلية المرفوعة.',
            default => 'تعذر قراءة مراجع الكتاب حاليًا؛ أعد الفحص، وإذا استمر الخطأ راجع الصور المرفوعة.',
        };
    }

    public function fingerprint(CodexSceneRun $run): string
    {
        $item = OrderItem::where('order_id', $run->order_id)->findOrFail($run->order_item_id);
        $manifest = isset($run->snapshot['legacy_approval_id']) ? null : OrderReviewManifest::findOrFail($run->snapshot['manifest_id']);

        return hash('sha256', json_encode($this->approvedInputs($item, $manifest), JSON_THROW_ON_ERROR));
    }

    public function statusEligible(Order $order, bool $explicit = false): bool
    {
        if ($order->printing_status !== 'not_started' || $order->shipping_status !== 'not_ready') {
            return false;
        }
        if ($explicit && in_array($order->status, ['waiting_customer', 'preview_uploaded'], true)
            && LegacyBookCoverApproval::whereIn('order_item_id', $order->items()->select('id'))->exists()) {
            return true;
        }
        if (in_array($order->status, ['new_confirmed', 'costomer_confirmed', 'ready_preview', 'pending_team_review'], true)) {
            return true;
        }
        // The owner's existing "تم الإنتاج والموافقة" order status is stored as
        // delivered. Fulfilment is tracked separately; never enroll printed/shipped work.
        if ($order->status !== 'delivered' || $order->printing_status !== 'not_started' || $order->shipping_status !== 'not_ready') {
            return false;
        }
        $cutoff = Setting::where('key', 'book_approved_enrollment_after')->value('value');

        $resumed = OrderProcessEvent::where('checkout_key', $order->checkoutGroupKey())->where('type', 'book_pages_resume')
            ->where('order_id', $order->id)->exists();

        return $explicit || $resumed || ($cutoff && $order->statusLogs()->where('status_type', 'order')->where('status', 'delivered')->where('created_at', '>', $cutoff)->exists());
    }

    /** Read-only detail diagnostics, using exactly the admission checks before enqueue. */
    public function admissionReason(OrderItem $item): ?string
    {
        $order = $item->order;
        if (CodexSceneRun::where('production_kind', 'coloring_book')->where('order_item_id', $item->id)->exists()) {
            return 'له تشغيل مسجل بالفعل؛ راجع تقدمه أو استأنف المسار، دون إنشاء تشغيل مكرر.';
        }
        if (! config('product_review.enabled') || ! config('product_review.book_pages_enabled') || ! $this->workerReady()) {
            return 'عامل صفحات التلوين غير متاح حاليًا.';
        }
        if (AutomationHandoff::held($order->checkoutGroupKey()) || AutomationControl::blocked($order->checkoutGroupKey())) {
            return 'الأوتوميشن متوقف؛ استخدم الاستئناف أولًا.';
        }
        if (! $this->statusEligible($order, true)) {
            return 'حالة الطلب أو الطباعة أو الشحن لا تسمح ببدء الصفحات.';
        }
        if (! Order::where('checkout_group_key', $order->checkoutGroupKey())->where('paid_amount_cents', '>', 0)->whereIn('payment_status', ['partially_paid', 'paid_in_full', 'paid_without_shipping'])->exists()) {
            return 'بانتظار تسجيل دفعة فعلية؛ إثبات التحويل وحده لا يكفي.';
        }
        if (CodexSceneRun::where('order_id', $order->id)->where('active_slot', 1)->exists() || app(AutomationAssignment::class)->owns($order, false)) {
            return 'بانتظار انتهاء خطوة التنفيذ الجارية على الطلب.';
        }
        if ($order->story_id && ! CodexSceneRun::where('order_id', $order->id)->where('production_kind', 'story')->where('status', 'completed')->exists()) {
            return 'بانتظار اكتمال القصة المرتبطة بالطفل.';
        }
        $manifest = OrderReviewManifest::where('checkout_key', $order->checkoutGroupKey())->latest('id')->first();
        if (! $manifest?->approved_at && ! LegacyBookCoverApproval::where('order_item_id', $item->id)->exists()) {
            return 'بانتظار اعتماد العميل للكفر في النسخة الحالية من المعاينة.';
        }
        try {
            $this->approvedInputs($item, $manifest);
        } catch (RuntimeException $e) {
            return $this->inputFailureReason($e);
        }

        return null;
    }

    public function start(OrderItem $item, ?OrderReviewManifest $manifest, bool $explicit = false, ?\Closure $admissionGuard = null): CodexSceneRun
    {
        if (! config('product_review.enabled') || ! config('product_review.book_pages_enabled') || ! $this->workerReady()) {
            throw new RuntimeException('Book production disabled');
        }

        return Cache::lock('codex-scenes-start', 30)->block(3, function () use ($item, $manifest, $explicit, $admissionGuard) {
            return DB::transaction(function () use ($item, $manifest, $explicit, $admissionGuard) {
                $rows = Order::where('checkout_group_key', $item->order->checkoutGroupKey())->orderBy('id')->lockForUpdate()->get();
                $order = $rows->firstWhere('id', $item->order_id);
                $item = OrderItem::lockForUpdate()->findOrFail($item->id);
                $admissionGuard?->__invoke();
                if (! AutomationControl::admits($item->order->checkoutGroupKey())) {
                    throw new RuntimeException('Automation paused');
                }
                if (! $order || AutomationHandoff::held($order->checkoutGroupKey()) || app(AutomationAssignment::class)->owns($order, false)
                    || ! $this->statusEligible($order, $explicit)
                    || ! $rows->contains(fn ($row) => $row->paid_amount_cents > 0 && in_array($row->payment_status, ['partially_paid', 'paid_in_full', 'paid_without_shipping'], true))
                    || CodexSceneRun::where('order_id', $order->id)->where('active_slot', 1)->exists()
                    || CodexSceneRun::where('production_kind', 'coloring_book')->where('order_item_id', $item->id)->exists()
                    || ($order->story_id && ! CodexSceneRun::where('order_id', $order->id)->where('production_kind', 'story')->where('status', 'completed')->exists())) {
                    throw new RuntimeException('Book not eligible');
                }
                // Re-read after checkout locking: the HTTP preflight may hold stale
                // item/component relations while another request edits personalization.
                $item = OrderItem::where('order_id', $order->id)->lockForUpdate()->findOrFail($item->id);
                $inputs = $this->approvedInputs($item, $manifest?->fresh());
                $admissionGuard?->__invoke();
                $run = CodexSceneRun::create(['id' => (string) Str::uuid(), 'order_id' => $order->id, 'checkout_key' => $order->checkoutGroupKey(),
                    'order_item_id' => $item->id, 'production_kind' => 'coloring_book', 'protocol' => 6, 'scene_count' => $inputs['page_count'],
                    'source' => 'automatic', 'status' => 'waiting_slot', 'active_slot' => 1, 'previous_status' => $order->status, 'scenes' => [],
                    'fingerprint' => hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR)),
                    'snapshot' => $inputs]);
                $before = $order->status;
                $order->update(['status' => 'generating']);
                $order->statusLogs()->create(['status' => 'generating', 'notes' => 'توليد صفحات كتاب التلوين بعد اعتماد الكفر وتسجيل دفعة.']);
                AutomationActivity::record($order, 'coloring_book_enrolled', 'بدء صفحات كتاب التلوين؛ الدفعة مسجلة يدويًا والكفر معتمد.',
                    ['run_id' => $run->id, 'item_id' => $item->id, 'unit_key' => $inputs['unit_key'], 'page_count' => $inputs['page_count'], 'from' => $before, 'to' => 'generating']);

                return $run;
            });
        });
    }

    public function enroll(): void
    {
        if (! config('product_review.enabled') || ! config('product_review.book_pages_enabled') || ! $this->workerReady()) {
            return;
        }
        $room = max(0, 50 - CodexSceneRun::where('active_slot', 1)->count());
        if (! $room) {
            return;
        }
        $query = OrderItem::whereHas('productionComponents', fn ($q) => $q->where('production_stage', 'book_interior'))
            ->whereNotIn('id', CodexSceneRun::where('production_kind', 'coloring_book')->whereNotNull('order_item_id')->select('order_item_id'))->orderBy('id');
        $cursor = (int) Cache::get('coloring-book-enrollment-cursor', 0);
        $batch = (clone $query)->where('id', '>', $cursor)->limit(min(25, $room))->get();
        if ($batch->isEmpty()) {
            $batch = $query->limit(min(25, $room))->get();
        }
        foreach ($batch as $item) {
            $manifest = OrderReviewManifest::where('checkout_key', $item->order->checkoutGroupKey())->latest('id')->first();
            try {
                if ($manifest?->approved_at) {
                    $this->start($item, $manifest);
                }
            } catch (RuntimeException $error) {
                $reason = $error->getMessage();
                if (preg_match('/\Abook_[a-z_]+\z/', $reason)
                    && Cache::add('book-admission-review:'.$item->id.':'.$reason, true, now()->addDay())) {
                    AutomationActivity::record($item->order, 'book_pages_waiting', 'توليد الكتاب ينتظر اكتمال أو مراجعة المراجع المرتبطة؛ لم يبدأ طلب توليد جديد.',
                        ['item_id' => $item->id, 'reason' => $reason]);
                }
            }
            Cache::put('coloring-book-enrollment-cursor', $item->id, now()->addDay());
        }
    }
}
