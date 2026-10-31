<x-admin-layout>
    <x-slot name="header">
        <h2 class="text-xl font-black text-gray-900">تقرير أداء الموظفين</h2>
        <p class="mt-1 text-sm text-gray-500">{{ $report['canCompare'] ? 'مقارنة إنجازات الفريق ومتابعة الشحن والتسليم' : 'أرقامك وإنجازاتك خلال الفترة المختارة' }}</p>
    </x-slot>
    @php
        $money = fn ($cents) => format_report_money($cents / 100);
        $period = ['from' => $report['from'], 'to' => $report['to']];
        $maxShipments = max(1, $report['teams']->max('shipments') ?? 0);
        $scope = $report['canCompare'] ? ($report['selected'] ? 'الموظفون المختارون' : 'كل الموظفين') : auth()->user()->name;
        $summary = $report['summary'];
    @endphp
    <div class="space-y-6 px-4 py-6 sm:px-6 lg:px-8" data-employee-performance>
        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div><h3 class="text-lg font-black text-gray-900">{{ $scope }}</h3><p class="mt-1 text-xs text-gray-500">{{ $report['from'] }} — {{ $report['to'] }} · بتوقيت القاهرة</p></div>
                <span class="rounded-full bg-indigo-50 px-4 py-2 text-xs font-bold text-indigo-700">{{ $report['canCompare'] ? 'عرض ومقارنة الفريق' : 'تقريرك الشخصي فقط' }}</span>
            </div>
            @if($errors->any())<div role="alert" class="mb-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <form method="GET" class="space-y-4">
                <div class="grid items-end gap-4 sm:grid-cols-3">
                    <div><label for="performance-from" class="mb-2 block text-sm font-bold">من تاريخ الشحن</label><input id="performance-from" name="from" type="date" value="{{ $report['from'] }}" required class="w-full rounded-xl border-gray-200"></div>
                    <div><label for="performance-to" class="mb-2 block text-sm font-bold">إلى تاريخ الشحن</label><input id="performance-to" name="to" type="date" value="{{ $report['to'] }}" required class="w-full rounded-xl border-gray-200"></div>
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white hover:bg-indigo-700">عرض التقرير</button>
                </div>
                @if($report['canCompare'])
                    <fieldset class="rounded-2xl border border-gray-100 p-4"><legend class="px-2 text-sm font-bold">اختيار موظف أو أكثر للمقارنة</legend>
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2"><p class="text-xs text-gray-500">بدون تحديد أسماء، يظهر كل الموظفين. اضغط على الاسم في الجدول لعرض موظف منفرد.</p><a class="text-sm font-bold text-indigo-600 underline" href="{{ route('admin.employee-performance.index', $period) }}">عرض كل الموظفين</a></div>
                        <div class="grid max-h-48 gap-3 overflow-y-auto sm:grid-cols-2 xl:grid-cols-4">
                            @foreach($report['employees'] as $employee)
                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border border-gray-100 p-3 text-sm"><input type="checkbox" name="employees[]" value="{{ $employee['id'] }}" @checked(in_array($employee['id'], $report['selected'], true)) class="rounded border-gray-300 text-indigo-600"><span>{{ $employee['name'] }}@if(!$employee['is_active']) <span class="text-xs text-gray-500">(غير نشط)</span>@endif</span></label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
            </form>
        </section>
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="ملخص أداء الموظفين">
            <div class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5"><p class="text-sm font-bold text-indigo-700">أوردرات اتشحنت خلال الفترة</p><p class="mt-3 text-4xl font-black text-gray-900">{{ $summary['shipments'] }}</p><p class="mt-2 text-xs text-indigo-700">تشمل المسلّمة — بدون تكرار</p></div>
            <div class="rounded-3xl border border-emerald-100 bg-emerald-50 p-5"><p class="text-sm font-bold text-emerald-700">المسلّمة من شحنات الفترة</p><p class="mt-3 text-4xl font-black text-gray-900">{{ $summary['delivered'] }}</p><p class="mt-2 text-xs text-emerald-700">كل أجزاء الأوردر تم تسليمها حاليًا</p></div>
            <div class="rounded-3xl border border-amber-100 bg-amber-50 p-5"><p class="text-sm font-bold text-amber-700">ما زالت مشحونة</p><p class="mt-3 text-4xl font-black text-gray-900">{{ $summary['in_transit'] }}</p><p class="mt-2 text-xs text-amber-700">لم يكتمل تسليم كل الأجزاء</p></div>
            <div class="rounded-3xl border border-gray-200 bg-white p-5"><p class="text-sm font-bold text-gray-600">نسبة التسليم الحالية</p><p class="mt-3 text-4xl font-black text-gray-900">{{ $summary['shipments'] ? round(100 * $summary['delivered'] / $summary['shipments'], 1).'%' : '—' }}</p><p class="mt-2 text-xs text-gray-500">المسلّمة ÷ شحنات الفترة</p></div>
        </section>
        <section class="grid gap-4 sm:grid-cols-3" aria-label="الكميات والقيمة">
            <div class="rounded-2xl border border-gray-100 bg-white p-4"><span class="text-sm text-gray-500">القصص</span><p class="mt-2 text-2xl font-black">{{ $summary['stories'] }}</p></div>
            <div class="rounded-2xl border border-gray-100 bg-white p-4"><span class="text-sm text-gray-500">قطع المنتجات والإضافات</span><p class="mt-2 text-2xl font-black">{{ $summary['products'] }} منتج · {{ $summary['add_ons'] }} إضافة</p></div>
            @if($report['canViewValues'])<div class="rounded-2xl border border-gray-100 bg-white p-4"><span class="text-sm text-gray-500">صافي قيمة الأوردرات</span><p class="mt-2 text-2xl font-black text-indigo-700">{{ $money($summary['items_net_cents']) }}</p><p class="mt-1 text-xs text-gray-500">بدون الشحن وبعد الخصومات الفعلية</p></div>@endif
        </section>
        @include('admin.employee-performance._tables')
        <aside class="rounded-2xl border border-amber-100 bg-amber-50 p-5 text-sm leading-7 text-amber-900">
            <h3 class="font-black">طريقة احتساب الأداء</h3>
            <p>الفترة حسب أول شحن موثّق، وليست تاريخ إنشاء الطلب أو تاريخ التسليم. «المسلّمة» هي الحالة الحالية لشحنات الفترة، وليست عمليات التسليم التي حدثت خلال الفترة. النسبة وصف لحالة هذه الشحنات وليست تقييمًا نهائيًا لجودة الموظف.</p>
            <p>الأوردر يُحتسب مرة واحدة بعد شحن كل أجزائه، وينسب للمسؤول الحالي. المرتجع والحالات غير المشحونة لا تدخل في العدد. الأوردرات غير المسندة لموظف لا تدخل في التقرير. إعادة التعيين تنقل أرقام الأوردر لمسؤوله الجديد. القيم بدون الشحن وبعد الخصومات الفعلية.</p>
            @if($report['undated'])<p class="mt-2 font-bold">{{ $report['undated'] }} أوردر للموظفين الظاهرين مشحون أو مسلّم بدون تاريخ شحن موثّق؛ غير محسوب في الفترة.</p>@endif
        </aside>
    </div>
</x-admin-layout>
