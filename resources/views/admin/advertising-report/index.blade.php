<x-admin-layout>
    <x-slot name="title">تقرير استهداف الإعلانات</x-slot>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-gray-900">تقرير استهداف الإعلانات</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">اعرف أين يوجد الطلب، وما الذي يشتريه العملاء في كل منطقة — بتوقيت القاهرة.</p>
        </div>
    </x-slot>
    @php
        $dates = $report['dates'];
        $summary = $report['summary'];
        $coverage = $report['coverage'];
        $money = fn ($cents) => number_format($cents / 100, 2).' ج.م';
        $types = ['story' => 'قصة', 'product' => 'منتج', 'product_add_on' => 'إضافة قصة'];
        $query = request()->except('page', 'items_page', 'campaigns_page', 'section');
    @endphp
    <div class="space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <section class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.advertising-report.index') }}" class="grid items-end gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div><label for="ad-range" class="mb-2 block text-sm font-bold">الفترة</label><select id="ad-range" name="range" class="w-full rounded-xl border-gray-200">
                    @foreach(['last_30_days' => 'آخر 30 يومًا', 'last_7_days' => 'آخر 7 أيام', 'today' => 'اليوم', 'yesterday' => 'أمس', 'this_month' => 'هذا الشهر', 'last_month' => 'الشهر الماضي', 'this_year' => 'هذا العام', 'custom' => 'فترة مخصصة'] as $key => $label)
                        <option value="{{ $key }}" @selected($dates->range === $key)>{{ $label }}</option>
                    @endforeach
                </select></div>
                <div><label for="ad-from" class="mb-2 block text-sm font-bold">من تاريخ</label><input id="ad-from" type="date" name="start_date" value="{{ $dates->startDate }}" onchange="document.getElementById('ad-range').value = 'custom'" class="w-full rounded-xl border-gray-200"></div>
                <div><label for="ad-to" class="mb-2 block text-sm font-bold">إلى تاريخ</label><input id="ad-to" type="date" name="end_date" value="{{ $dates->endDate }}" onchange="document.getElementById('ad-range').value = 'custom'" class="w-full rounded-xl border-gray-200"></div>
                <div><label for="ad-basis" class="mb-2 block text-sm font-bold">عمليات الشراء المحتسبة</label><select id="ad-basis" name="basis" class="w-full rounded-xl border-gray-200">
                    <option value="ordered" @selected($report['basis'] === 'ordered')>الطلبات بتاريخ الشراء الأصلي</option><option value="collected" @selected($report['basis'] === 'collected')>طلبات بها حركة دفع خلال الفترة</option>
                </select></div>
                <div><label for="ad-level" class="mb-2 block text-sm font-bold">دقة المنطقة</label><select id="ad-level" name="level" class="w-full rounded-xl border-gray-200" onchange="document.getElementById('ad-area').value = ''">
                    @foreach(['district' => 'المنطقة / الحي / القرية', 'city' => 'المدينة / المركز', 'governorate' => 'المحافظة'] as $key => $label)<option value="{{ $key }}" @selected($report['level'] === $key)>{{ $label }}</option>@endforeach
                </select></div>
                <div><label for="ad-item" class="mb-2 block text-sm font-bold">عمليات شراء تحتوي منتجًا أو قصة</label><select id="ad-item" name="item" class="w-full rounded-xl border-gray-200"><option value="">كل المنتجات والقصص</option>
                    @foreach($report['item_options'] as $option)<option value="{{ $option['key'] }}" @selected($report['item'] === $option['key'])>{{ $option['label'] }}</option>@endforeach
                </select></div>
                <div><label for="ad-area" class="mb-2 block text-sm font-bold">تفاصيل منطقة</label><select id="ad-area" name="area" class="w-full rounded-xl border-gray-200"><option value="">كل المناطق</option>
                    @foreach($report['area_options'] as $option)<option value="{{ $option['key'] }}" @selected($report['area'] === $option['key'])>{{ $option['label'] }}</option>@endforeach
                </select></div>
                <div><label for="ad-location" class="mb-2 block text-sm font-bold">بحث باسم المحافظة أو المدينة أو المنطقة</label><input id="ad-location" type="search" name="location" maxlength="100" value="{{ $report['location'] }}" placeholder="مثال: مدينة نصر" class="w-full rounded-xl border-gray-200"></div>
                <div class="flex gap-2"><button class="flex-1 rounded-xl bg-indigo-600 px-5 py-3 font-black text-white hover:bg-indigo-700">عرض التقرير</button><a href="{{ route('admin.advertising-report.index') }}" class="rounded-xl border border-gray-200 px-4 py-3 text-sm font-bold">مسح</a></div>
            </form>
            <div class="mt-4 rounded-xl bg-indigo-50 p-4 text-sm leading-7 text-indigo-900">
                عملية الشراء تُحسب مرة واحدة. وضع الطلبات يختار بتاريخ الشراء الأصلي، ووضع الدفعات يختار بتاريخ حركة الدفع ولو كان الطلب قديمًا. قيمة الطلب ومتوسطه بعد الخصم <strong>بدون الشحن</strong>، وليست تحصيلًا. التحصيل المعروض هو صافي حركات الدفع داخل الفترة للطلبات المختارة ويشمل الشحن والدفع الجزئي. هذا تقرير استهداف يستبعد الملغي والمحذوف؛ إجمالي التحصيل الشامل متاح في <a class="font-bold underline" href="{{ route('admin.payment-report.index', ['range' => 'custom', 'start_date' => $dates->startDate, 'end_date' => $dates->endDate]) }}">تقرير الدفعات</a>.
                فلتر المنتج يختار السلات التي تحتويه، وتظل أرقام السلة كاملة؛ توزيع المنتجات يوضح قيمة كل عنصر بعد توزيع الخصم عليه.
            </div>
        </section>
        @include('admin.payment-report._reconciliation')
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach(['checkouts' => 'عمليات الشراء', 'customers' => 'العملاء المختلفون', 'net_cents' => 'قيمة الطلبات بدون الشحن', 'average_cents' => 'متوسط الطلب بدون الشحن'] as $key => $label)
                <div class="rounded-3xl border border-indigo-100 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-indigo-600">{{ $label }}</p><p class="mt-3 text-3xl font-black text-gray-900">{{ str_ends_with($key, '_cents') ? $money($summary[$key]) : number_format($summary[$key]) }}</p></div>
            @endforeach
        </section>
        <section class="rounded-3xl border border-gray-100 bg-white p-5 text-sm leading-7 shadow-sm">
            <h3 class="font-black">جودة البيانات قبل اختيار الاستهداف</h3>
            <p>من {{ $coverage['eligible'] }} عملية شراء مطابقة للفترة والبحث وفلاتر التقرير: {{ $coverage['structured_district'] }} بها منطقة بوسطة محددة، {{ $coverage['structured_city'] }} بها مدينة/مركز محدد، و{{ $coverage['unknown_attribution'] }} بدون مصدر تتبع معروف. {{ $coverage['cancelled'] }} عملية ملغاة مستبعدة خلال الفترة.</p>
            <p class="text-gray-500">العناوين القديمة المكتوبة يدويًا تظهر كما سُجلت ولا يتم تحليل الشارع أو تخمين المنطقة. اسم المنطقة ليس بالضرورة نفس اسم استهداف ميتا؛ راجعه داخل مدير الإعلانات. «عملاء متكررون» يعني أكثر من عملية شراء خلال الفترة المحددة، وليس تاريخ العميل كله.</p>
        </section>
        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 p-5"><div><h3 class="text-lg font-black">أين يوجد الطلب؟</h3><p class="text-xs text-gray-500">مرتبة بقيمة الطلبات بدون الشحن. اضغط المنطقة لعرض منتجاتها ومصادرها.</p></div><a class="font-bold text-emerald-700 underline" href="{{ route('admin.advertising-report.export', array_merge($query, ['section' => 'areas'])) }}">تصدير المناطق CSV</a></div>
            <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead class="bg-gray-50 text-gray-500"><tr>
                @foreach(['المنطقة', 'عمليات شراء / العملاء', 'القطع', 'قيمة الطلبات بدون الشحن', 'متوسط الطلب', 'دفع / تسليم', 'الأكثر طلبًا'] as $label)<th class="p-4">{{ $label }}</th>@endforeach
            </tr></thead><tbody>
                @forelse($report['areas'] as $row)<tr class="border-t border-gray-100 {{ $report['area'] === $row['key'] ? 'bg-indigo-50' : '' }}">
                    <td class="p-4"><a class="font-bold text-indigo-600 underline" href="{{ route('admin.advertising-report.index', array_merge($query, ['area' => $row['key']])) }}">{{ $row['label'] }}</a>@if($row['legacy'])<p class="mt-1 text-xs text-amber-700">مدينة/منطقة مكتوبة يدويًا</p>@endif @if($row['checkouts'] < 5)<p class="mt-1 text-xs text-gray-500">عينة صغيرة — أقل من 5 طلبات</p>@endif</td>
                    <td class="p-4">{{ $row['checkouts'] }} / {{ $row['customers'] }}<p class="text-xs text-gray-500">{{ $row['repeat_customers'] }} متكررون خلال الفترة</p></td><td class="p-4">{{ $row['quantity'] }}</td><td class="p-4 font-bold">{{ $money($row['net_cents']) }}</td><td class="p-4">{{ $money($row['average_cents']) }}</td><td class="p-4">{{ $row['paid_checkouts'] }} برصيد مدفوع حالي<br>{{ $row['delivered'] }} مسلمة</td>
                    <td class="p-4">@foreach($row['top_items'] as $item)<p>{{ $item['title'] }} × {{ $item['quantity'] }}</p>@endforeach</td>
                </tr>@empty<tr><td colspan="7" class="p-8 text-center text-gray-500">لا توجد عمليات شراء مطابقة للفلاتر.</td></tr>@endforelse
            </tbody></table></div><div class="border-t border-gray-100 p-5">{{ $report['areas']->links() }}</div>
        </section>
        <div class="rounded-xl bg-indigo-50 p-4 font-bold text-indigo-900">
            التفاصيل: {{ $report['selected_area']['label'] ?? ($report['area'] !== '' ? 'المنطقة المحددة غير متاحة للفلاتر الحالية — امسح اختيار المنطقة.' : 'كل المناطق') }}
            @if($report['area'] !== '')<a class="mr-3 underline" href="{{ route('admin.advertising-report.index', array_merge($query, ['area' => ''])) }}">عرض كل المناطق</a>@endif
        </div>
        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 p-5"><div><h3 class="text-lg font-black">ماذا يباع في المنطقة؟</h3><p class="text-xs text-gray-500">كل منتج/قصة بهويته، حتى لو تغيّر اسمه. أعداد طلبات العناصر لا تُجمع لأنها قد تشترك في نفس السلة.</p></div><a class="font-bold text-emerald-700 underline" href="{{ route('admin.advertising-report.export', array_merge($query, ['section' => 'items'])) }}">تصدير المنتجات CSV</a></div>
            <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead class="bg-gray-50 text-gray-500"><tr>@foreach(['المنتج / القصة', 'النوع', 'عمليات شراء تحتويه', 'القطع', 'قبل الخصم بدون شحن', 'بعد توزيع الخصم بدون شحن'] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead><tbody>
                @forelse($report['items'] as $row)<tr class="border-t border-gray-100"><td class="p-4 font-bold">{{ $row['title'] }}</td><td class="p-4">{{ $types[$row['type']] ?? 'إضافة' }}</td><td class="p-4">{{ $row['checkouts'] }}</td><td class="p-4">{{ $row['quantity'] }}</td><td class="p-4">{{ $money($row['gross_cents']) }}</td><td class="p-4 font-bold">{{ $money($row['net_cents']) }}</td></tr>@empty<tr><td colspan="6" class="p-8 text-center text-gray-500">لا توجد منتجات أو قصص مطابقة.</td></tr>@endforelse
            </tbody></table></div><div class="border-t border-gray-100 p-5">{{ $report['items']->links() }}</div>
        </section>
        <section class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 p-5"><div><h3 class="text-lg font-black">المصادر والحملات والإعلانات</h3><p class="text-xs text-gray-500">بيانات التتبع المسجلة مع الشراء؛ عدم وجود تتبع لا يعني أن العميل جاء مباشرة.</p></div><a class="font-bold text-emerald-700 underline" href="{{ route('admin.advertising-report.export', array_merge($query, ['section' => 'campaigns'])) }}">تصدير المصادر CSV</a></div>
            <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead class="bg-gray-50 text-gray-500"><tr>@foreach(['المصدر', 'الحملة', 'مجموعة الإعلان', 'الإعلان', 'عمليات الشراء', 'قيمة الطلبات بدون الشحن', 'تحصيل الفترة لهذه الطلبات شامل الشحن'] as $label)<th class="p-4">{{ $label }}</th>@endforeach</tr></thead><tbody>
                @forelse($report['campaigns'] as $row)<tr class="border-t border-gray-100"><td class="p-4 font-bold">{{ $row['label'] }}<p class="text-xs font-normal text-gray-500">{{ $row['source'] }} {{ $row['medium'] }}</p></td><td class="p-4">{{ $row['campaign_name'] ?? $row['campaign'] ?? 'غير مسجلة' }}<p class="text-xs text-gray-500">{{ $row['campaign_id'] }}</p></td><td class="p-4">{{ $row['adset_name'] ?? 'غير مسجلة' }}<p class="text-xs text-gray-500">{{ $row['adset_id'] }}</p></td><td class="p-4">{{ $row['ad_name'] ?? $row['content'] ?? 'غير مسجل' }}<p class="text-xs text-gray-500">{{ $row['ad_id'] }}</p></td><td class="p-4">{{ $row['checkouts'] }}</td><td class="p-4">{{ $money($row['net_cents']) }}</td><td class="p-4">{{ $money($row['collected_cents']) }}</td></tr>@empty<tr><td colspan="7" class="p-8 text-center text-gray-500">لا توجد بيانات للفلاتر الحالية.</td></tr>@endforelse
            </tbody></table></div><div class="border-t border-gray-100 p-5">{{ $report['campaigns']->links() }}</div>
        </section>
        <p class="px-2 text-sm leading-7 text-gray-500">استخدم المناطق الأعلى طلبًا والمنتجات المناسبة لها لتجربة استهداف مستقل، وقارن النتائج بعد جمع عينة كافية. التقرير لا يحسب ROAS أو تكلفة اكتساب العميل أو معدل التحويل؛ لا توجد هنا بيانات تكلفة الإعلانات أو الزيارات. لا يتم إرسال بيانات إلى ميتا أو تعديل أي طلب.</p>
    </div>
</x-admin-layout>
