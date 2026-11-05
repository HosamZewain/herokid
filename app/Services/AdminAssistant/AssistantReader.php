<?php

namespace App\Services\AdminAssistant;

use App\Models\Order;
use App\Models\User;
use App\Services\Agentic\AutoPilotOrderScope;
use App\Services\Agentic\CapabilityRegistry;
use App\Services\Agentic\DashboardMonitor;
use App\Services\Agentic\OrderContextBuilder;
use App\Services\Orders\OrderAccess;
use App\Services\Payments\PaymentCollectionReportService;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use App\Support\OrderStatusRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Bounded, explicit read adapters. No SQL, URL, model or method supplied by the agent. */
final class AssistantReader
{
    public function read(User $user, array $plan, array $filters): array
    {
        abort_unless(in_array($plan['tool'], app(AssistantAccess::class)->tools($user), true), 403);

        return match ($plan['tool']) {
            'order' => $this->order($user, $plan['reference']),
            'queue' => $this->queue(),
            'autopilot' => $this->autopilot(),
            'sales', 'payments' => $this->report($plan['tool'], $filters),
            default => ['text' => 'أقدر أوضح حالة أوردر برقم HK أو رقم الأوردر، الطلبات النشطة، ملخص Auto Pilot، والمبيعات والتحصيل حسب صلاحياتك. التقارير الأخرى والتنفيذ من الشات غير متاحين في هذه المرحلة.', 'facts' => [], 'sources' => []],
        };
    }

    private function order(User $user, ?string $reference): array
    {
        if (! $reference) {
            return ['text' => 'اكتب رقم الأوردر أو افتح صفحته ثم اسألني عنه.', 'facts' => [], 'sources' => []];
        }
        $query = app(OrderAccess::class)->scope(Order::query());
        if (ctype_digit($reference)) {
            $query->whereKey((int) $reference);
        } else {
            $query->whereHas('checkoutReference', fn ($q) => $q->where('short_reference', $reference));
        }
        $order = $query->first();
        abort_unless($order, 404, 'الأوردر غير متاح في نطاق صلاحياتك أو الرقم غير صحيح.');
        app(OrderAccess::class)->assert($order);
        $builder = app(OrderContextBuilder::class);
        $canonical = $builder->canonicalKey($order->checkoutGroupKey());
        $order = app(OrderAccess::class)->scope(Order::query())->where('checkout_group_key', $canonical)->orderBy('id')->first();
        abort_unless($order, 404);
        $context = $builder->build($canonical);
        // A merge alias cannot expand an employee's assigned checkout scope.
        foreach ($context['orders'] as $row) {
            $member = Order::withTrashed()->findOrFail((int) substr($row['order_ref'], 6));
            app(OrderAccess::class)->assert($member);
        }
        // All siblings must belong to the actor's visible group; no broad MCP service identity.
        $finance = $user->hasPermission('orders.statistics.view');
        $facts = [
            ['label' => 'الأوردر', 'value' => $order->checkoutReference?->short_reference ?? (string) $order->id],
            ['label' => 'الحالة', 'value' => OrderStatusRegistry::label('order', $order->status)],
            ['label' => 'الطباعة', 'value' => OrderStatusRegistry::label('printing', $order->printing_status)],
            ['label' => 'الشحن', 'value' => OrderStatusRegistry::label('shipping', $order->shipping_status)],
            ['label' => 'عدد وحدات الإنتاج', 'value' => count($context['units'])],
        ];
        $labels = [];
        foreach ($context['units'] as $index => $unit) {
            $kind = match ($unit['capability']) {
                'story_scenes' => 'مشاهد القصة', 'book_cover' => 'غلاف الكتاب', 'book_interior' => 'صفحات الكتاب',
                'product_image' => 'تصميم المنتج', default => 'منتج يحتاج مراجعة',
            };
            $labels[$unit['unit_key']] = 'الجزء '.($index + 1).' — '.$kind;
            if ($index < 20) {
                $facts[] = ['label' => $labels[$unit['unit_key']], 'value' => 'مخرجات '.($unit['output_count'] ?? 0).' / '.($unit['expected_outputs'] ?? 0)];
            }
        }
        foreach (array_slice(app(CapabilityRegistry::class)->targets($context), 0, 20) as $target) {
            $reason = $target['reason_code'];
            $restrictedReason = ! $finance && (str_contains($reason, 'PAYMENT') || str_contains($reason, 'PAID'));
            $facts[] = ['label' => $labels[$target['target_id']] ?? 'حالة الأوردر', 'value' => $restrictedReason ? 'هذه الخطوة تحتاج مراجعة المسؤول المخوّل؛ التفاصيل غير متاحة بصلاحياتك.' : $this->translation('reasons', $reason)];
        }
        if ($finance) {
            $facts[] = ['label' => 'الدفعات المسجلة بالجنيه', 'value' => isset($context['operational']['paid_amount_cents']) ? number_format($context['operational']['paid_amount_cents'] / 100, 2) : 'غير محسوم؛ راجع تعارض الأرصدة'];
        }
        $runs = collect($context['process_runs'])->countBy('status');
        foreach ($runs as $status => $count) {
            $label = match ($status) {
                'running' => 'قيد التشغيل', 'queued' => 'في الطابور', 'completed' => 'مكتملة', 'failed' => 'فشلت',
                'waiting_response' => 'تنتظر ردًا', 'waiting_staff' => 'تنتظر الفريق', 'paused' => 'متوقفة',
                'cancelled' => 'ملغاة', default => 'تحتاج متابعة من صفحة الأوردر',
            };
            $facts[] = ['label' => 'مسارات التشغيل: '.$label, 'value' => $count];
        }

        return ['text' => 'دي الحالة الحالية والأسباب المسجلة لكل جزء من الأوردر. الإجراء المقترح يحتاج فحص الخدمة عند التنفيذ؛ وجود مخرجات لا يعني اعتمادها أو تسليم الأوردر. أعرض حتى 20 وحدة و20 سببًا؛ التفاصيل الكاملة في صفحة الأوردر.',
            'facts' => $facts, 'sources' => [['label' => 'فتح الأوردر والخطوات المتاحة', 'url' => route('admin.orders.show', $order)]], 'reference' => (string) $order->id];
    }

    private function queue(): array
    {
        $query = app(OrderAccess::class)->scope(AutoPilotOrderScope::activeOrdersQuery())
            ->whereNotIn('checkout_group_key', DB::table('order_group_merge_aliases')->select('source_checkout_group_key'));
        $count = (clone $query)->distinct()->count('checkout_group_key');
        $ids = (clone $query)->selectRaw('MIN(orders.id) AS id')->groupBy('checkout_group_key')->orderByRaw('MIN(orders.id)')->limit(20)->pluck('id');
        $orders = Order::with('checkoutReference')->whereIn('id', $ids)->orderBy('id')->get();

        return ['text' => 'في نطاقك '.$count.' أوردر نشط. دي أقدم 20 أوردر حسب ترتيب إنشائها، وليست ترتيب تنفيذ مضمون؛ الطابور يتغيّر حسب الجاهزية والموافقات.',
            'facts' => $orders->map(fn ($o) => ['label' => $o->checkoutReference?->short_reference ?? (string) $o->id,
                'value' => OrderStatusRegistry::label('order', $o->status).' · '.OrderStatusRegistry::label('shipping', $o->shipping_status)])->all(),
            'sources' => $orders->map(fn ($o) => ['label' => $o->checkoutReference?->short_reference ?? (string) $o->id, 'url' => route('admin.orders.show', $o)])->all()];
    }

    private function autopilot(): array
    {
        $data = app(DashboardMonitor::class)->snapshot();
        $facts = [];
        foreach (['active' => 'الطلبات النشطة', 'covered' => 'اكتملت تغطية تقييمها', 'uncovered' => 'تقييم غير مكتمل', 'attempts' => 'تقييمات اليوم'] as $key => $label) {
            $facts[] = ['label' => $label, 'value' => $data[$key]];
        }
        $facts[] = ['label' => 'حصة التقييم اليومية', 'value' => (int) config('agentic.daily_limit')];

        return ['text' => 'ملخص التقييم الحالي. التغطية تخص المراجعة ولا تعني تنفيذ أو تسليم الأوردر. افتح اللوحة لعرض التنفيذ الجاري وسجله.', 'facts' => $facts,
            'sources' => [['label' => 'لوحة Auto Pilot', 'url' => route('admin.agentic.index')]]];
    }

    private function report(string $tool, array $query): array
    {
        Validator::make($query, [
            'range' => 'required|in:today,yesterday,last_7_days,last_30_days,this_month,last_month,this_year,custom',
            'start_date' => 'required_if:range,custom|nullable|date_format:Y-m-d',
            'end_date' => 'required_if:range,custom|nullable|date_format:Y-m-d|after_or_equal:start_date',
        ])->validate();
        $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', $query));
        abort_if($filters->localStart()->diffInDays($filters->localEnd()->startOfDay()) > 365, 422, 'اختر فترة لا تتجاوز سنة.');
        $payments = app(PaymentCollectionReportService::class);
        if ($tool === 'payments') {
            $summary = $payments->summary($payments->events($filters));
        } else {
            $report = app(SalesReportService::class)->report($filters, 1);
            $summary = $report['collection_summary'];
        }
        $facts = [['label' => 'الفترة بتوقيت القاهرة', 'value' => $filters->startDate.' ← '.$filters->endDate]];
        foreach (['received_cents' => 'دفعات موجبة مسجلة', 'reversed_cents' => 'حركات عكسية مسجلة', 'net_cents' => 'صافي حركات الدفعات'] as $key => $label) {
            $facts[] = ['label' => $label.' (جنيه)', 'value' => number_format($summary[$key] / 100, 2)];
        }
        if (isset($report)) {
            $facts[] = ['label' => 'قيمة الأوردرات المعترف بها (جنيه)', 'value' => number_format($report['summary']['order_value'], 2)];
            $facts[] = ['label' => 'عدد الأوردرات المعترف بها', 'value' => $report['summary']['checkouts']];
        }
        $facts[] = ['label' => 'أوردرات لها حركات في الفترة', 'value' => $summary['checkouts']];

        return ['text' => 'الأرقام من نفس خدمة التقرير وبالفلاتر الموضحة في رابط المصدر. صافي الحركات المسجلة ليس إثباتًا مستقلًا لتحصيل نقدي مباشر، ولا إجمالي قيمة الأوردرات أو الربح. تعريفات الفترة وقيمة الأوردرات والتحصيل مختلفة؛ التفاصيل الكاملة في التقرير.',
            'facts' => $facts, 'sources' => [['label' => $tool === 'payments' ? 'تقرير الدفعات بالفلاتر' : 'تقرير المبيعات بالفلاتر',
                'url' => route($tool === 'payments' ? 'admin.payment-report.index' : 'admin.sales-report.index', $query)]]];
    }

    private function translation(string $group, string $key): string
    {
        $value = __('agentic.'.$group.'.'.$key);

        return $value === 'agentic.'.$group.'.'.$key ? 'تحتاج هذه الخطوة مراجعة المسؤول ('.$key.').' : $value;
    }
}
