<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Orders\AdminOrderReportService;
use App\Support\AdminActivityLogger;
use App\Support\AppDateTime;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderReportController extends Controller
{
    public function index(Request $request, AdminOrderReportService $reports): View
    {
        $report = $reports->report($request, paginate: true);

        return view('admin.order-report.index', compact('report'));
    }

    public function export(Request $request, AdminOrderReportService $reports): StreamedResponse
    {
        // Export does not need to calculate on-screen breakdowns or load filter options.
        $export = $reports->export($request);
        $rows = $export['rows'];
        $filename = 'herokid-orders-report-'.now()->format('Ymd-His').'.csv';

        AdminActivityLogger::log(
            action: 'order_report.exported',
            description: 'تصدير تقرير الطلبات الشامل',
            properties: [
                'row_count' => $export['count'],
                'filters' => $request->only([
                    'from', 'to', 'catalog_type', 'lifecycle', 'status', 'payment_status',
                    'printing_status', 'shipping_status', 'order_source', 'payment_method',
                    'assignment', 'tag_id', 'q',
                ]),
            ],
            request: $request,
        );

        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'التاريخ', 'مرجع عملية الشراء', 'أرقام الطلبات', 'نوع الطلب', 'دورة الطلب',
                'المصدر', 'العميل', 'الهاتف', 'الأطفال', 'القصص', 'المنتجات والإضافات',
                'عدد القصص', 'عدد المنتجات والإضافات', 'حالة الطلب', 'حالة الدفع',
                'حالة الطباعة', 'حالة الشحن', 'قيمة العناصر', 'التوصيل', 'الخصم',
                'إجمالي الطلب', 'المدفوع فعليًا', 'المتبقي', 'طريقة الدفع', 'مسؤول الطلب',
                'الدولة', 'المحافظة', 'المدينة', 'الشارع', 'العنوان',
            ]);

            foreach ($rows as $row) {
                $delivery = $row['delivery'];
                fputcsv($output, array_map($this->csvCell(...), [
                    AppDateTime::format($row['created_at'], 'Y-m-d H:i'),
                    $row['short_reference'] ?: $row['key'],
                    implode('، ', $row['order_numbers']),
                    $row['catalog_type_label'],
                    $row['lifecycle_label'],
                    $row['order_source_label'],
                    $row['customer_name'],
                    $row['phone'],
                    implode('، ', $row['child_names']),
                    implode('، ', $row['story_titles']),
                    implode('، ', array_merge($row['product_titles'], $row['add_on_titles'])),
                    $row['story_count'],
                    $row['product_quantity'] + $row['add_on_quantity'],
                    $row['status_label'],
                    $row['payment_status_label'],
                    $row['printing_status_label'],
                    $row['shipping_status_label'],
                    number_format($row['items_cents'] / 100, 2, '.', ''),
                    number_format($row['delivery_cents'] / 100, 2, '.', ''),
                    number_format($row['discount_cents'] / 100, 2, '.', ''),
                    number_format($row['total_cents'] / 100, 2, '.', ''),
                    number_format($row['paid_amount_cents'] / 100, 2, '.', ''),
                    number_format($row['remaining_amount_cents'] / 100, 2, '.', ''),
                    $row['payment_method'],
                    $row['assigned_admin']?->name,
                    data_get($delivery, 'country'),
                    data_get($delivery, 'governorate'),
                    data_get($delivery, 'city'),
                    data_get($delivery, 'street'),
                    data_get($delivery, 'address_details'),
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
