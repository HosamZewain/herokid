<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentCollectionReportService;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Sales\SalesReportFilters;
use App\Support\AdminActivityLogger;
use App\Support\AppDateTime;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentReportController extends Controller
{
    private function filters(Request $request): SalesReportFilters
    {
        $request->validate(['start_date' => ['nullable', 'date_format:Y-m-d'], 'end_date' => ['nullable', 'date_format:Y-m-d'],
            'day' => ['nullable', 'date_format:Y-m-d'], 'page' => ['nullable', 'integer', 'min:1']]);
        $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', [
            ...$request->query(), 'range' => $request->query('range', 'this_month'),
        ]));
        abort_if($filters->localStart()->diffInDays($filters->localEnd()->startOfDay()) > 365, 422, 'اختر فترة لا تتجاوز سنة.');
        abort_if($request->filled('day') && ($request->query('day') < $filters->startDate || $request->query('day') > $filters->endDate), 422, 'اليوم المختار خارج الفترة.');

        return $filters;
    }

    public function index(Request $request, PaymentCollectionReportService $payments): View
    {
        $filters = $this->filters($request);
        $events = $payments->events($filters);
        $day = $request->query('day');
        $selected = collect();
        if ($day) {
            $dayFilters = SalesReportFilters::fromRequest(Request::create('/', 'GET', [...$request->query(), 'range' => 'custom', 'start_date' => $day, 'end_date' => $day]));
            $selected = $payments->events($dayFilters, detailed: true);
        }
        $rows = new LengthAwarePaginator($selected->forPage(max(1, $request->integer('page', 1)), 50)->values(), $selected->count(), 50,
            max(1, $request->integer('page', 1)), ['path' => $request->url(), 'query' => $request->except('page')]);

        return view('admin.payment-report.index', ['filters' => $filters, 'day' => $day, 'rows' => $rows,
            'reconciliation' => app(PaymentReconciliationService::class)->report(),
            'summary' => $payments->summary($events), 'daily' => $payments->daily($events, $filters),
            'selectedSummary' => $payments->summary($selected), 'categories' => PaymentCollectionReportService::CATEGORIES]);
    }

    public function export(Request $request, PaymentCollectionReportService $payments): StreamedResponse
    {
        $filters = $this->filters($request);
        if ($request->filled('day')) {
            $filters = SalesReportFilters::fromRequest(Request::create('/', 'GET', [...$request->query(), 'range' => 'custom', 'start_date' => $request->query('day'), 'end_date' => $request->query('day')]));
        }
        $events = $payments->events($filters, detailed: true);
        AdminActivityLogger::log(action: 'payment_report.exported', description: 'تصدير حركات الدفعات',
            properties: ['start_date' => $filters->startDate, 'end_date' => $filters->endDate, 'row_count' => $events->count()], request: $request);

        return response()->streamDownload(function () use ($events): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['معرف الحركة', 'تاريخ ووقت الدفع', 'مرجع الطلب', 'تاريخ الشراء الأصلي', 'العميل', 'تصنيف الطلب', 'الحركة بالعملة', 'طريقة الدفع', 'الموظف', 'نوع الحركة', 'مصدر التسجيل', 'مجموعة الشراء الأصلية قبل الدمج']);
            foreach ($events as $row) {
                $values = [
                    ($row['historical'] ? 'activity:' : 'payment:').abs($row['id']), AppDateTime::format($row['occurred_at'], 'Y-m-d H:i:s'), $row['reference'],
                    AppDateTime::format($row['original_created_at'], 'Y-m-d H:i'), $row['customer_name'], $row['category_label'],
                    number_format($row['amount_delta_cents'] / 100, 2, '.', ''), $row['payment_method'], $row['actor_name'], $row['event_type'], $row['event_source'], $row['original_checkout_key'],
                ];
                // The signed amount is server-formatted numeric data, not user input.
                fputcsv($output, array_map(fn ($value, $index): string => $index !== 6 && preg_match('/^[\s]*[=+\-@]/u', (string) $value) ? "'".$value : (string) $value, $values, array_keys($values)));
            }
            fclose($output);
        }, 'herokid-payments-'.$filters->startDate.'-'.$filters->endDate.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
