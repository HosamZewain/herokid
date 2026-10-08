<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\AdvertisingTargetingReportService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdvertisingTargetingReportController extends Controller
{
    public function index(Request $request, AdvertisingTargetingReportService $service): View
    {
        return view('admin.advertising-report.index', ['report' => $service->report($request)]);
    }

    public function export(Request $request, AdvertisingTargetingReportService $service): StreamedResponse
    {
        $report = $service->report($request, export: true);
        $section = in_array($request->query('section'), ['items', 'campaigns'], true) ? $request->query('section') : 'areas';
        AdminActivityLogger::log(action: 'advertising_report.exported', description: 'تصدير تقرير استهداف الإعلانات',
            properties: ['section' => $section, 'start_date' => $report['dates']->startDate, 'end_date' => $report['dates']->endDate], request: $request);

        return response()->streamDownload(function () use ($report, $section): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            $headers = match ($section) {
                'items' => ['المنتج/القصة', 'النوع', 'عمليات شراء تحتوي العنصر', 'القطع', 'قبل الخصم بدون شحن', 'بعد توزيع الخصم بدون شحن'],
                'campaigns' => ['المصدر', 'UTM source', 'الوسيط', 'الحملة', 'معرف الحملة', 'مجموعة الإعلان', 'معرف المجموعة', 'الإعلان', 'معرف الإعلان', 'عمليات الشراء', 'قيمة الطلبات بدون الشحن', 'المدفوع الفعلي شامل الشحن'],
                default => ['المنطقة', 'نوع العنوان', 'عمليات الشراء', 'العملاء', 'عملاء متكررون خلال الفترة', 'القطع', 'قيمة الطلبات بدون الشحن', 'متوسط الطلب بدون الشحن', 'المدفوع الفعلي شامل الشحن', 'طلبات مدفوعة', 'طلبات مسلمة'],
            };
            fputcsv($output, $headers);
            foreach ($report[$section] as $row) {
                $values = match ($section) {
                    'items' => [$row['title'], $row['type'], $row['checkouts'], $row['quantity'], $row['gross_cents'] / 100, $row['net_cents'] / 100],
                    'campaigns' => [$row['label'], $row['source'], $row['medium'], $row['campaign_name'] ?? $row['campaign'], $row['campaign_id'], $row['adset_name'], $row['adset_id'], $row['ad_name'] ?? $row['content'], $row['ad_id'], $row['checkouts'], $row['net_cents'] / 100, $row['collected_cents'] / 100],
                    default => [$row['label'], $row['legacy'] ? 'مدينة/منطقة مكتوبة يدويًا' : 'عنوان مسجل', $row['checkouts'], $row['customers'], $row['repeat_customers'], $row['quantity'], $row['net_cents'] / 100, $row['average_cents'] / 100, $row['collected_cents'] / 100, $row['paid_checkouts'], $row['delivered']],
                };
                fputcsv($output, array_map(fn (mixed $value): string => preg_match('/^[\s]*[=+\-@]/u', (string) $value) ? "'".$value : (string) $value, $values));
            }
            fclose($output);
        }, 'herokid-advertising-'.$section.'-'.$report['dates']->startDate.'-'.$report['dates']->endDate.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private',
        ]);
    }
}
