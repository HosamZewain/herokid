<?php

namespace App\Services\Orders;

use App\Models\AdminActivityLog;
use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderPaymentReview;
use App\Models\OrderReviewManifest;
use App\Models\OrderShippingMergeRequest;
use App\Models\PuzzleGeneration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** One projection for rows, tabs, filters and detail. Queries are batched, never per row. */
class OrderAutomationSummary
{
    private array $cache = [];

    private ?Request $request = null;

    public const STATES = ['intervention' => 'يحتاج تدخل', 'running' => 'تنفيذ جارٍ', 'waiting' => 'انتظار العميل أو الدفع', 'queued' => 'في الطابور', 'idle' => 'مع الفريق'];

    public const STAGES = ['confirmation' => 'تأكيد الطلب', 'identity' => 'هوية الطفل', 'product' => 'صور المنتجات', 'book_cover' => 'كفر التلوين', 'preview' => 'المعاينة', 'payment' => 'مراجعة الدفع', 'story_pages' => 'مشاهد القصة — Codex', 'book_pages' => 'صفحات التلوين — Codex', 'control' => 'التحكم', 'team' => 'التيم'];

    public function forKeys(iterable $keys): array
    {
        if ($this->request !== request()) {
            $this->cache = [];
            $this->request = request();
        }
        $keys = collect($keys)->filter()->unique()->values();
        foreach ($keys->reject(fn ($key) => isset($this->cache[$key]))->chunk(500) as $batch) {
            $this->load($batch);
        }

        return array_intersect_key($this->cache, array_flip($keys->all()));
    }

    private function load(Collection $keys): void
    {
        $orders = Order::withTrashed()->whereIn('checkout_group_key', $keys)->get(['id', 'checkout_group_key', 'status', 'payment_status', 'child_name', 'updated_at', 'paid_amount_cents', 'printing_status', 'shipping_status'])->groupBy('checkout_group_key');
        $controls = DB::table('order_automation_controls')->whereIn('checkout_key', $keys)->get()->keyBy('checkout_key');
        $owned = DB::query()->fromSub(app(AutomationAssignment::class)->keys(), 'owned')->whereIn('checkout_key', $keys)->pluck('checkout_key');
        $holds = DB::table('order_automation_holds')->whereIn('checkout_key', $keys)->pluck('checkout_key');
        $processes = DB::table('order_process_runs')->whereIn('checkout_key', $keys)->get(['id', 'checkout_key', 'status', 'error_code', 'updated_at'])->groupBy('checkout_key');
        $confirmations = DB::table('robodesk_integration_events')->whereIn('checkout_group_key', $keys)->where('event_type', 'order.pending_confirmation')->orderBy('id')->get(['id', 'checkout_group_key', 'status', 'updated_at'])->groupBy('checkout_group_key');
        $identities = DB::table('order_process_identities')->whereIn('run_id', $processes->flatten(1)->pluck('id'))->get(['id', 'run_id', 'order_id', 'status', 'error_code', 'updated_at'])->groupBy('run_id');
        $manualIdentities = DB::table('order_identity_regenerations')->whereIn('checkout_key', $keys)->orderBy('id')->get(['id', 'checkout_key', 'order_id', 'status', 'error_code', 'updated_at'])->groupBy('checkout_key');
        $products = PuzzleGeneration::whereIn('checkout_key', $keys)->orderBy('id')->get(['id', 'checkout_key', 'order_id', 'order_item_id', 'status', 'error_code', 'context', 'updated_at', 'finished_at', 'lease_until', 'output_path', 'attachment_id', 'preview_id'])->groupBy('checkout_key');
        $scenes = CodexSceneRun::whereIn('checkout_key', $keys)->orderBy('created_at')->orderBy('id')->get(['id', 'checkout_key', 'order_id', 'order_item_id', 'production_kind', 'status', 'book_pdf', 'scenes', 'scene_count', 'error_code', 'updated_at'])->groupBy('checkout_key');
        $books = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('order_item_production_components as component', 'component.order_item_id', '=', 'order_items.id')
            ->whereIn('orders.checkout_group_key', $keys)->where('component.production_stage', 'book_interior')
            ->select(['orders.checkout_group_key', 'order_items.id', 'order_items.order_id', 'order_items.title', 'component.quantity_per_item'])->get()->groupBy('checkout_group_key');
        $manifests = OrderReviewManifest::whereIn('checkout_key', $keys)->orderBy('id')->get(['id', 'checkout_key', 'approved_at', 'images'])->keyBy('checkout_key');
        $legacyOrderIds = DB::table('order_automation_runs')->whereIn('order_id', $orders->flatten(1)->pluck('id'))->where('dry_run', false)->pluck('order_id');
        $admissionErrors = AdminActivityLog::where('action', 'order.automation_admission_blocked')->where('subject_type', Order::class)
            ->whereIn('subject_id', $orders->flatten(1)->pluck('id'))->orderBy('id')->get(['subject_id', 'properties', 'created_at'])->keyBy('subject_id');
        $reviews = OrderPaymentReview::query()->whereIn('checkout_key', $keys)->actionable()->get(['checkout_key', 'updated_at'])->keyBy('checkout_key');
        $shippingReviews = OrderShippingMergeRequest::where('status', 'pending')
            ->where(fn ($q) => $q->whereHas('sourceOrder', fn ($q) => $q->whereIn('checkout_group_key', $keys))->orWhereHas('targetOrder', fn ($q) => $q->whereIn('checkout_group_key', $keys)))
            ->with(['sourceOrder:id,checkout_group_key', 'targetOrder:id,checkout_group_key'])->get();
        foreach ($keys as $key) {
            $rows = $orders->get($key, collect());
            $parts = [];
            $hasExecution = $processes->get($key, collect())->isNotEmpty() || $products->get($key, collect())->isNotEmpty()
                || $scenes->get($key, collect())->isNotEmpty() || $manualIdentities->get($key, collect())->isNotEmpty()
                || $rows->contains(fn ($o) => $legacyOrderIds->contains($o->id));
            $needsEnrollment = ! $hasExecution && $rows->isNotEmpty() && $rows->every(fn ($o) => $o->status === 'new_confirmed'
                && $o->printing_status === 'not_started' && $o->shipping_status === 'not_ready');
            $fulfilled = $rows->isNotEmpty() && $rows->every(fn ($o) => $o->status === 'cancelled' || $o->printing_status !== 'not_started' || $o->shipping_status !== 'not_ready');
            $activeScenes = $scenes->get($key, collect())->contains(fn ($r) => in_array($r->status, ['waiting_slot', 'queued', 'preparing', 'running'], true));
            $add = function (string $stage, string $state, string $label, $at, ?string $reason = null, ?int $orderId = null, ?int $done = null, ?int $total = null) use (&$parts, $rows) {
                $parts[] = compact('stage', 'state', 'label', 'reason', 'done', 'total') + ['at' => (string) $at, 'child' => $rows->firstWhere('id', $orderId)?->child_name];
            };
            foreach ($shippingReviews->filter(fn ($review) => in_array($key, [$review->sourceOrder?->checkoutGroupKey(), $review->targetOrder?->checkoutGroupKey()], true)) as $review) {
                $add('team', 'intervention', 'طلب ضم الشحن — بانتظار مراجعة الفريق', $review->updated_at,
                    'راجع الطلبين والمبلغ النهائي قبل إنشاء البوليصة. طلب الضم لا يغيّر وضع التوليد؛ لم تُخصم رسوم الشحن.');
            }
            $control = $controls->get($key);
            if ($control && $control->state !== 'running') {
                $add('control', 'intervention', match ($control->state) {
                    'paused' => 'متوقف للعمل اليدوي', 'pause_uncertain' => 'التوقف غير مؤكد', default => 'طلب إيقاف — ننتظر حفظ الخطوة الجارية'
                }, $control->updated_at, $control->reason);
            } elseif ($holds->contains($key)) {
                $add('control', 'intervention', 'متوقف للعمل اليدوي', $rows->max('updated_at'));
            }
            if ($event = $confirmations->get($key)?->last()) {
                if ($rows->contains('status', 'new') || in_array($event->status, ['processing', 'unknown', 'blocked', 'failed'], true)) {
                    $state = match ($event->status) {
                        'processing' => 'running', 'succeeded' => 'waiting', 'pending' => 'queued', default => 'intervention'
                    };
                    $add('confirmation', $state, 'تأكيد الطلب: '.match ($state) {
                        'waiting' => 'بانتظار العميل', 'running' => 'جارٍ الإرسال', 'queued' => 'بانتظار الإرسال', default => 'راجع محاولة الإرسال'
                    }, $event->updated_at);
                }
            }
            foreach ($processes->get($key, collect()) as $run) {
                if (in_array($run->status, ['cancelled', 'completed'], true)) {
                    continue;
                }
                $state = match ($run->status) {
                    'waiting_response' => 'waiting', 'waiting_staff','blocked','uncertain','paused' => 'intervention', 'queued','retry_queued' => 'queued', default => 'running'
                };
                $stage = $run->status === 'waiting_identity' ? 'identity' : 'preview';
                $label = match ($run->status) {
                    'waiting_identity' => 'تجهيز هويات القصص', 'waiting_response' => 'بانتظار موافقة العميل', 'waiting_staff' => 'بانتظار الفريق', 'sending' => 'إرسال المعاينة', 'blocked','uncertain' => 'المسار يحتاج مراجعة', 'paused' => 'المسار متوقف', default => 'متابعة المعاينات'
                };
                if ($run->status === 'waiting_staff' && ($fulfilled || $activeScenes)) {
                    continue;
                }
                if ($run->status === 'waiting_staff' && ! $reviews->has($key) && $rows->sum('paid_amount_cents') === 0) {
                    $state = 'waiting';
                    $stage = 'payment';
                    $label = 'بانتظار دفعة مسجلة';
                }
                $add($stage, $state, $label, $run->updated_at, $run->error_code);
                foreach ($identities->get($run->id, collect()) as $identity) {
                    if ($identity->status === 'succeeded') {
                        continue;
                    }
                    $add('identity', match ($identity->status) {
                        'processing' => 'running','queued' => 'queued',default => 'intervention'
                    }, 'هوية الطفل', $identity->updated_at, $identity->error_code, $identity->order_id);
                }
            }
            foreach ($manualIdentities->get($key, collect())->keyBy('order_id') as $identity) {
                if ($identity->status === 'succeeded') {
                    continue;
                }
                $add('identity', match ($identity->status) {
                    'processing' => 'running','queued' => 'queued',default => 'intervention'
                }, 'إعادة توليد الهوية', $identity->updated_at, $identity->error_code, $identity->order_id);
            }
            foreach ($products->get($key, collect())->keyBy('order_item_id') as $product) {
                $stage = ($product->context['production_stage'] ?? '') === 'book_cover' ? 'book_cover' : 'product';
                $state = match ($product->status) {
                    'processing' => 'running','queued' => 'queued','succeeded' => 'idle',default => 'intervention'
                };
                $add($stage, $state, self::STAGES[$stage].' #'.$product->order_item_id.' — '.(PuzzleGeneration::LABELS[$product->status] ?? $product->status), $product->updated_at, $product->status === 'uncertain' && $product->canAuthorizeUncertainRetry() ? 'أوقف الأوتوميشن بأمان، ثم استخدم إعادة التوليد من بند المنتج مع الموافقة على محاولة جديدة.' : (PuzzleGeneration::ERRORS[$product->error_code] ?? $product->error_code), $product->order_id, $product->status === 'succeeded' ? 1 : 0, 1);
            }
            foreach ($scenes->get($key, collect())->keyBy(fn ($run) => $run->order_id.':'.($run->order_item_id ?? 'story')) as $run) {
                $done = collect($run->scenes ?? [])->filter(fn ($s, $number) => ctype_digit((string) $number) && (int) $number >= 1 && (int) $number <= ($run->scene_count ?? 13) && ! empty($s['path']) && ! empty($s['sha256']))->count();
                $stage = $run->production_kind === 'coloring_book' ? 'book_pages' : 'story_pages';
                if (($run->book_pdf['status'] ?? '') === 'failed') {
                    $add('book_pages', 'intervention', 'تعذر تجميع PDF كتاب التلوين', $run->updated_at, 'الصور محفوظة؛ تعذر تجميع الملف بعد '.$run->book_pdf['attempts'].' محاولة. لا تعِد توليد الصفحات.', $run->order_id);
                }
                $state = match ($run->status) {
                    'running','preparing' => 'running','waiting_slot','queued' => 'queued',default => 'intervention'
                };
                if ($run->status === 'completed' && $fulfilled) {
                    $state = 'idle';
                }
                $add($stage, $state, self::STAGES[$stage].': '.$done.' من '.($run->scene_count ?? 13).' — '.(CodexSceneRun::LABELS[$run->status] ?? $run->status), $run->updated_at, $run->error_code ? (CodexSceneRun::ERRORS[$run->error_code] ?? $run->error_code) : ($run->status === 'completed' && ! $fulfilled ? 'جاهز لمراجعة الفريق' : null), $run->order_id, $done, $run->scene_count ?? 13);
            }
            foreach ($books->get($key, collect()) as $book) {
                if ($fulfilled || $scenes->get($key, collect())->contains('order_item_id', $book->id)) {
                    continue;
                }
                $cover = $products->get($key, collect())->where('order_item_id', $book->id)->last();
                $manifest = $manifests->get($key);
                $approved = $manifest?->approved_at && collect($manifest->images)->contains(fn ($image) => ($image['kind'] ?? '') === 'book_cover' && ($image['item_id'] ?? null) === $book->id && ($image['generation_id'] ?? null) === $cover?->id);
                [$state,$reason] = ! $cover || $cover->status !== 'succeeded'
                    ? ['queued', 'بانتظار تجهيز كفر الكتاب — الكفر بـOpenAI API، والداخل بـCodex.']
                    : (! $approved ? ['waiting', 'بانتظار اعتماد الكفر الحالي.']
                    : ($rows->sum('paid_amount_cents') <= 0 ? ['waiting', 'بانتظار تسجيل دفعة فعلية.']
                    : ['intervention', 'الكفر معتمد والدفعة مسجلة، لكن صفحات Codex لم تبدأ. استخدم فحص وبدء صفحات التلوين؛ سيُراجع شروط التشغيل.']));
                $add('book_pages', $state, 'صفحات التلوين لم تبدأ — '.$book->title, $rows->max('updated_at'), $reason, $book->order_id, 0, $book->quantity_per_item);
            }
            if ($needsEnrollment) {
                $failure = $rows->map(fn ($o) => $admissionErrors->get($o->id))->filter()->sortByDesc('created_at')->first();
                $reason = $failure && $failure->created_at->gte($rows->max('updated_at'))
                    ? OrderAutomationAdmission::message($failure->properties['reason_code'] ?? 'admission_requires_review')
                    : 'لا يوجد تشغيل مسجل لتجهيز الهوية أو المنتجات. استخدم «فحص وإدخال للأوتوميشن» لعرض أي بيانات ناقصة قبل البدء.';
                $add('preview', 'intervention', 'لم يدخل مسار الإنتاج الآلي', $failure?->created_at ?? $rows->max('updated_at'), $reason);
            }
            if ($reviews->has($key) || ($rows->sum('paid_amount_cents') <= 0 && $rows->contains('payment_status', 'pending_review'))) {
                $paid = $rows->sum('paid_amount_cents') > 0;
                $add('payment', 'intervention', $paid ? 'مرفق جديد بعد تسجيل الدفع' : 'بانتظار مراجعة الدفع', $reviews->get($key)?->updated_at ?? $rows->max('updated_at'), $paid ? 'الدفعة مسجلة بالفعل؛ راجع المرفق الجديد في رسالة العميل. لا تسجّل نفس الدفعة مرة أخرى.' : 'راجع رسالة العميل وسجّل الدفع يدويًا.');
            }
            if ($rows->contains(fn ($o) => in_array($o->status, ['pending_team_review', 'modification_requested', 'revision_requested'], true))) {
                $add('team', 'intervention', 'الطلب يحتاج تدخل الفريق', $rows->max('updated_at'));
            }
            $rank = ['intervention' => 0, 'running' => 1, 'waiting' => 2, 'queued' => 3, 'idle' => 4];
            $stageRank = ['control' => 0, 'payment' => 1, 'confirmation' => 2, 'identity' => 2, 'product' => 2, 'book_cover' => 2, 'story_pages' => 2, 'book_pages' => 2, 'team' => 3, 'preview' => 4];
            usort($parts, fn ($a, $b) => ($rank[$a['state']] <=> $rank[$b['state']]) ?: (($stageRank[$a['stage']] ?? 5) <=> ($stageRank[$b['stage']] ?? 5)));
            $top = $parts[0] ?? ['state' => 'idle', 'stage' => 'team', 'label' => 'مع الفريق', 'reason' => null];
            $tracking = $owned->contains($key) || collect($parts)->contains(fn ($p) => in_array($p['state'], ['running', 'waiting', 'queued'], true));
            if ($control?->state === 'paused' || $holds->contains($key) || ($needsEnrollment && ! collect($parts)->contains('state', 'running'))) {
                $tracking = false;
            }
            $this->cache[$key] = $top + ['tracking' => $tracking, 'has_execution' => $hasExecution, 'needs_enrollment' => $needsEnrollment, 'running' => collect($parts)->contains('state', 'running'), 'parts' => $parts,
                'updated_at' => collect($parts)->max('at') ?: (string) $rows->max('updated_at'), 'control_state' => $control?->state ?? ($holds->contains($key) ? 'paused_legacy' : 'running'),
                'control_version' => (int) ($control?->version ?? 1), 'assignment_version' => (int) ($control?->assignment_version ?? 0)];
        }
    }

    public function filter($query, Request $request): void
    {
        $tab = $request->query('workspace', 'all');
        $state = $request->query('automation_state');
        $stage = $request->query('automation_stage');
        if ($tab === 'all' && ! $state && ! $stage) {
            return;
        }
        $all = $this->forKeys(Order::withTrashed()->select('checkout_group_key')->distinct()->pluck('checkout_group_key'));
        $matched = array_keys(array_filter($all, fn ($s) => ($tab !== 'automation' || $s['tracking']) && ($tab !== 'team' || ! $s['tracking']) && ($tab !== 'attention' || $s['state'] === 'intervention')
            && (! $state || $s['state'] === $state) && (! $stage || collect($s['parts'])->contains('stage', $stage))));
        $query->whereIn('orders.checkout_group_key', $matched);
    }
}
