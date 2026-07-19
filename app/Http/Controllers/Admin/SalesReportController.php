<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesReportFilters;
use App\Services\Sales\SalesReportService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function index(Request $request, SalesReportService $sales): View
    {
        $filters = SalesReportFilters::fromRequest($request);
        $report = $sales->report($filters);
        $page = max(1, $request->integer('page', 1));
        $rows = $report['rows'];

        $report['rows'] = (new LengthAwarePaginator(
            items: $rows->forPage($page, $filters->perPage)->values(),
            total: $rows->count(),
            perPage: $filters->perPage,
            currentPage: $page,
            options: ['path' => route('admin.sales-report.index')],
        ))->appends($request->query());

        return view('admin.sales-report.index', [
            'filters' => $filters,
            'report' => $report,
        ]);
    }

    public function export(Request $request, SalesReportService $sales): StreamedResponse
    {
        $filters = SalesReportFilters::fromRequest($request);
        $rows = $sales->rows($filters);
        $filename = 'herokid-sales-'.$filters->startDate.'-'.$filters->endDate.'.csv';

        AdminActivityLogger::log(
            action: 'sales_report.exported',
            description: 'تصدير تقرير المبيعات للفترة '.$filters->startDate.' إلى '.$filters->endDate,
            properties: [
                'start_date' => $filters->startDate,
                'end_date' => $filters->endDate,
                'row_count' => $rows->count(),
                'status' => $filters->status,
                'type' => $filters->type,
            ],
            request: $request,
        );

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'التاريخ', 'مجموعة الشراء', 'أرقام الطلبات', 'العميل', 'الهاتف', 'نوع العميل',
                'العناصر', 'عدد القطع', 'قيمة العناصر', 'التوصيل', 'الإجمالي', 'الحالة',
                'الدولة', 'المحافظة', 'المدينة', 'المصدر', 'الحملة',
            ]);

            foreach ($rows as $row) {
                fputcsv($output, array_map($this->csvCell(...), [
                    $row['date'],
                    $row['key'],
                    implode('، ', $row['order_numbers']),
                    $row['customer_name'],
                    $row['phone'],
                    $row['customer_type'] === 'registered' ? 'مسجل' : 'زائر',
                    $row['items_summary'],
                    $row['items_quantity'],
                    number_format($row['items_total_cents'] / 100, 2, '.', ''),
                    number_format($row['delivery_cents'] / 100, 2, '.', ''),
                    number_format($row['total_cents'] / 100, 2, '.', ''),
                    $row['status_label'],
                    $row['country'],
                    $row['governorate'],
                    $row['city'],
                    $row['source'],
                    $row['campaign'],
                ]));
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
