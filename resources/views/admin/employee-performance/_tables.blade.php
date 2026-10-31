<section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
    <div class="border-b border-gray-100 p-5"><h3 class="text-lg font-black">{{ $report['canCompare'] ? 'جدول مقارنة أداء الموظفين' : 'ملخص أدائك' }}</h3><p class="mt-1 text-xs text-gray-500">مرتّب حسب عدد الشحنات؛ الموظفون بدون شحنات يظهرون بصفر.</p></div>
    <div class="overflow-x-auto"><table class="w-full whitespace-nowrap text-right text-sm">
        <thead class="bg-gray-50 text-gray-600"><tr><th class="p-4">الموظف</th><th class="p-4">اتشحنت</th><th class="p-4">منها اتسلّمت</th><th class="p-4">ما زالت مشحونة</th><th class="p-4">نسبة التسليم</th><th class="p-4">قصص</th><th class="p-4">منتجات</th><th class="p-4">إضافات</th>@if($report['canViewValues'])<th class="p-4">القيمة الصافية</th>@endif</tr></thead>
        <tbody class="divide-y divide-gray-100">
        @foreach($report['teams'] as $team)
            <tr class="hover:bg-gray-50"><th scope="row" class="p-4">@if($report['canCompare'])<a href="{{ route('admin.employee-performance.index', $period + ['employees' => [$team['id']]]) }}" class="font-bold text-indigo-600 underline">{{ $team['name'] }}</a>@else{{ $team['name'] }}@endif @if(!$team['is_active'])<span class="text-xs font-normal text-gray-500">(غير نشط)</span>@endif</th><td class="p-4 font-black">{{ $team['shipments'] }}</td><td class="p-4 text-emerald-700">{{ $team['delivered'] }}</td><td class="p-4">{{ $team['in_transit'] }}</td><td class="p-4">{{ $team['delivery_rate'] === null ? '—' : $team['delivery_rate'].'%' }}</td><td class="p-4">{{ $team['stories'] }}</td><td class="p-4">{{ $team['products'] }}</td><td class="p-4">{{ $team['add_ons'] }}</td>@if($report['canViewValues'])<td class="p-4 font-bold">{{ $money($team['items_net_cents']) }}</td>@endif</tr>
        @endforeach
        </tbody>
        <tfoot class="bg-indigo-50 font-bold"><tr><th class="p-4">الإجمالي</th><td class="p-4">{{ $summary['shipments'] }}</td><td class="p-4">{{ $summary['delivered'] }}</td><td class="p-4">{{ $summary['in_transit'] }}</td><td class="p-4">{{ $summary['shipments'] ? round(100 * $summary['delivered'] / $summary['shipments'], 1).'%' : '—' }}</td><td class="p-4">{{ $summary['stories'] }}</td><td class="p-4">{{ $summary['products'] }}</td><td class="p-4">{{ $summary['add_ons'] }}</td>@if($report['canViewValues'])<td class="p-4">{{ $money($summary['items_net_cents']) }}</td>@endif</tr></tfoot>
    </table></div>
</section>
@if($report['canCompare'] && $report['teams']->count() > 1)
    <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm" aria-label="مقارنة الشحن والتسليم">
        <h3 class="text-lg font-black">مقارنة الشحن والتسليم</h3><p class="mt-1 text-xs text-gray-500">بنفس المقياس لكل الموظفين · الشحن يشمل المسلّم</p>
        <div class="mt-5 grid gap-5 lg:grid-cols-2">
        @foreach($report['teams'] as $team)
            <div><p class="mb-2 text-sm font-bold">{{ $team['name'] }}</p>
                <div class="flex items-center gap-2"><span class="w-16 text-xs">اتشحنت</span><div role="meter" aria-valuemin="0" aria-valuemax="{{ $maxShipments }}" aria-valuenow="{{ $team['shipments'] }}" aria-label="شحنات {{ $team['name'] }}" class="h-3 w-full overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-indigo-600" style="width: {{ 100 * $team['shipments'] / $maxShipments }}%"></div></div><span class="w-10 text-left text-sm font-bold">{{ $team['shipments'] }}</span></div>
                <div class="mt-2 flex items-center gap-2"><span class="w-16 text-xs">اتسلّمت</span><div role="meter" aria-valuemin="0" aria-valuemax="{{ $maxShipments }}" aria-valuenow="{{ $team['delivered'] }}" aria-label="المسلّمة {{ $team['name'] }}" class="h-3 w-full overflow-hidden rounded-full bg-gray-100"><div class="h-full rounded-full bg-emerald-600" style="width: {{ 100 * $team['delivered'] / $maxShipments }}%"></div></div><span class="w-10 text-left text-sm font-bold">{{ $team['delivered'] }}</span></div>
            </div>
        @endforeach
        </div>
    </section>
@endif
<section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
    <div class="p-5"><h3 class="text-lg font-black">الأداء يوم بيوم</h3><p class="mt-1 text-xs text-gray-500">للموظفين الظاهرين في التقرير فقط، حسب يوم الشحن</p></div>
    <div class="max-h-96 overflow-auto"><table class="w-full whitespace-nowrap text-right text-sm">
        <thead class="sticky top-0 bg-gray-50"><tr><th class="p-4">اليوم</th><th class="p-4">اتشحنت</th><th class="p-4">منها اتسلّمت</th><th class="p-4">قصص</th><th class="p-4">منتجات</th><th class="p-4">إضافات</th>@if($report['canViewValues'])<th class="p-4">القيمة الصافية</th>@endif</tr></thead>
        <tbody class="divide-y divide-gray-100">@foreach($report['daily'] as $day)<tr><th class="p-4 font-medium">{{ $day['day'] }}</th><td class="p-4">{{ $day['shipments'] }}</td><td class="p-4">{{ $day['delivered'] }}</td><td class="p-4">{{ $day['stories'] }}</td><td class="p-4">{{ $day['products'] }}</td><td class="p-4">{{ $day['add_ons'] }}</td>@if($report['canViewValues'])<td class="p-4">{{ $money($day['items_net_cents']) }}</td>@endif</tr>@endforeach</tbody>
    </table></div>
</section>
@if($report['teams']->count() === 1)
    <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
        <div class="p-5"><h3 class="text-lg font-black">تجميع المنتجات والقصص (SKU)</h3><p class="mt-1 text-xs text-gray-500">{{ $report['teams']->first()['name'] }} · خلال فترة الشحن المختارة</p></div>
        <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead class="bg-gray-50"><tr><th class="p-4">المنتج / القصة</th><th class="p-4">SKU</th><th class="p-4">العدد</th>@if($report['canViewValues'])<th class="p-4">القيمة الصافية</th>@endif</tr></thead>
        <tbody class="divide-y divide-gray-100">@forelse($report['sku_breakdown'] as $item)<tr><td class="p-4">{{ $item['title'] }}</td><td class="p-4">{{ $item['sku'] ?: '—' }}</td><td class="p-4">{{ $item['quantity'] }}</td>@if($report['canViewValues'])<td class="p-4">{{ $money($item['net_cents']) }}</td>@endif</tr>@empty<tr><td colspan="4" class="p-6 text-center text-gray-500">لا توجد شحنات موثقة خلال الفترة المختارة.</td></tr>@endforelse</tbody></table></div>
    </section>
@endif
