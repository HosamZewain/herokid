<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-right">
                <h2 class="text-xl font-black text-gray-900">تقرير المبيعات</h2>
                <p class="mt-1 text-xs font-bold text-gray-500">قيم الطلبات والعناصر والتوصيل من قاعدة بيانات HeroKid</p>
            </div>
            <a href="{{ route('admin.sales-report.export', request()->except('page')) }}"
               class="inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-black text-emerald-700 hover:bg-emerald-100">
                تصدير CSV
            </a>
        </div>
    </x-slot>

    @php
        $summary = $report['summary'];
        $comparison = $report['comparison'];
        $rows = $report['rows'];
        $options = $report['options'];
        $trend = collect($report['trend']);
        $trendMax = max(1, (float) $trend->max('total'));
        $statuses = [
            'active' => 'كل الطلبات غير الملغاة',
            'all' => 'كل الحالات بما فيها الملغاة',
            'new' => 'جديد',
            'under_review' => 'قيد المراجعة',
            'generating' => 'جاري التوليد',
            'preview_uploaded' => 'المعاينة مرفوعة',
            'approved_for_print' => 'موافق للطباعة',
            'printing' => 'قيد الطباعة',
            'shipped' => 'تم الشحن',
            'delivered' => 'تم التسليم',
            'cancelled' => 'ملغي',
        ];
        $comparisonBadge = function ($value): string {
            if ($value === null) {
                return '<span class="text-xs font-bold text-gray-400">لا توجد مقارنة سابقة</span>';
            }
            $positive = (float) $value >= 0;
            $class = $positive ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700';
            $prefix = $positive ? '+' : '';
            return '<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-black '.$class.'">'.$prefix.number_format((float) $value, 1).'% عن الفترة السابقة</span>';
        };
        $typeLabels = [
            'story' => 'قصة مخصصة',
            'product' => 'منتج مباشر',
            'product_add_on' => 'إضافة مرتبطة بقصة',
        ];
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-right text-sm leading-7 text-amber-900">
                <span class="font-black">ملاحظة محاسبية:</span>
                يعرض التقرير قيمة الطلبات المسجلة، وليس تحصيلات دفع مؤكدة؛ النظام الحالي لا يحتوي على حالة دفع مستقلة. يتم احتساب مجموعة الشراء مرة واحدة وجمع رسوم التوصيل مرة واحدة لمنع تكرار إجمالي السلات متعددة القصص.
            </div>

            <div class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
                <form method="GET" action="{{ route('admin.sales-report.index') }}" class="space-y-4">
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label class="mb-2 block text-sm font-black text-gray-700">الفترة</label>
                            <select name="range" class="w-full rounded-xl border-gray-200 text-right">
                                <option value="today" @selected($filters->range === 'today')>اليوم</option>
                                <option value="yesterday" @selected($filters->range === 'yesterday')>أمس</option>
                                <option value="last_7_days" @selected($filters->range === 'last_7_days')>آخر 7 أيام</option>
                                <option value="last_30_days" @selected($filters->range === 'last_30_days')>آخر 30 يوماً</option>
                                <option value="this_month" @selected($filters->range === 'this_month')>هذا الشهر</option>
                                <option value="last_month" @selected($filters->range === 'last_month')>الشهر الماضي</option>
                                <option value="this_year" @selected($filters->range === 'this_year')>هذا العام</option>
                                <option value="custom" @selected($filters->range === 'custom')>فترة مخصصة</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-black text-gray-700">من</label>
                            <input type="date" name="start_date" value="{{ $filters->startDate }}" class="w-full rounded-xl border-gray-200">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-black text-gray-700">إلى</label>
                            <input type="date" name="end_date" value="{{ $filters->endDate }}" class="w-full rounded-xl border-gray-200">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-black text-gray-700">حالة الطلب</label>
                            <select name="status" class="w-full rounded-xl border-gray-200 text-right">
                                @foreach($statuses as $value => $label)
                                    <option value="{{ $value }}" @selected($filters->status === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <details class="rounded-2xl border border-gray-100 bg-slate-50 p-4" @if(request()->hasAny(['type', 'item', 'customer_type', 'country_id', 'governorate_id', 'source', 'min_total', 'max_total', 'q', 'group_by', 'sort', 'per_page'])) open @endif>
                        <summary class="cursor-pointer text-sm font-black text-gray-800">فلاتر متقدمة</summary>
                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">نوع البيع</label>
                                <select name="type" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="all" @selected($filters->type === 'all')>كل الأنواع</option>
                                    <option value="story" @selected($filters->type === 'story')>قصص مخصصة</option>
                                    <option value="product" @selected($filters->type === 'product')>منتجات مباشرة</option>
                                    <option value="product_add_on" @selected($filters->type === 'product_add_on')>إضافات مرتبطة بقصة</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">قصة أو منتج محدد</label>
                                <select name="item" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="">كل العناصر</option>
                                    <optgroup label="القصص">
                                        @foreach($options['stories'] as $story)
                                            <option value="story:{{ $story->id }}" @selected($filters->item === 'story:'.$story->id)>{{ $story->title }}</option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="المنتجات">
                                        @foreach($options['products'] as $product)
                                            <option value="product:{{ $product->id }}" @selected($filters->item === 'product:'.$product->id)>{{ $product->name_ar }}</option>
                                        @endforeach
                                    </optgroup>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">نوع العميل</label>
                                <select name="customer_type" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="all" @selected($filters->customerType === 'all')>الكل</option>
                                    <option value="registered" @selected($filters->customerType === 'registered')>عملاء مسجلون</option>
                                    <option value="guest" @selected($filters->customerType === 'guest')>زوار بدون حساب</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">المصدر التسويقي</label>
                                <select name="source" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="">كل المصادر</option>
                                    @foreach($options['sources'] as $source => $label)
                                        <option value="{{ $source }}" @selected($filters->source === $source)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">الدولة</label>
                                <select name="country_id" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="">كل الدول</option>
                                    @foreach($options['countries'] as $country)
                                        <option value="{{ $country->id }}" @selected($filters->countryId === $country->id)>{{ $country->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">المحافظة</label>
                                <select name="governorate_id" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="">كل المحافظات</option>
                                    @foreach($options['countries'] as $country)
                                        <optgroup label="{{ $country->name }}">
                                            @foreach($country->governorates as $governorate)
                                                <option value="{{ $governorate->id }}" @selected($filters->governorateId === $governorate->id)>{{ $governorate->name }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">أقل إجمالي</label>
                                <input type="number" min="0" step="0.01" name="min_total" value="{{ $filters->minimumTotal }}" class="w-full rounded-xl border-gray-200 text-sm" placeholder="0">
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">أعلى إجمالي</label>
                                <input type="number" min="0" step="0.01" name="max_total" value="{{ $filters->maximumTotal }}" class="w-full rounded-xl border-gray-200 text-sm" placeholder="بدون حد">
                            </div>
                            <div class="md:col-span-2">
                                <label class="mb-2 block text-xs font-black text-gray-600">بحث</label>
                                <input type="search" name="q" value="{{ $filters->search }}" class="w-full rounded-xl border-gray-200 text-right text-sm" placeholder="رقم طلب، عميل، طفل، هاتف، عنصر أو SKU">
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">تجميع الرسم</label>
                                <select name="group_by" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="auto" @selected($filters->groupBy === 'auto')>تلقائي</option>
                                    <option value="day" @selected($filters->groupBy === 'day')>يومي</option>
                                    <option value="week" @selected($filters->groupBy === 'week')>أسبوعي</option>
                                    <option value="month" @selected($filters->groupBy === 'month')>شهري</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">الترتيب</label>
                                <select name="sort" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    <option value="newest" @selected($filters->sort === 'newest')>الأحدث</option>
                                    <option value="oldest" @selected($filters->sort === 'oldest')>الأقدم</option>
                                    <option value="highest" @selected($filters->sort === 'highest')>الأعلى قيمة</option>
                                    <option value="lowest" @selected($filters->sort === 'lowest')>الأقل قيمة</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-xs font-black text-gray-600">عدد الصفوف</label>
                                <select name="per_page" class="w-full rounded-xl border-gray-200 text-right text-sm">
                                    @foreach([25, 50, 100] as $size)
                                        <option value="{{ $size }}" @selected($filters->perPage === $size)>{{ $size }} صفاً</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </details>

                    <div class="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-sm font-bold text-gray-500">
                            {{ $filters->label() }}:
                            <span dir="ltr" class="text-gray-800">{{ $filters->startDate }} → {{ $filters->endDate }}</span>
                        </p>
                        <div class="flex gap-2">
                            <a href="{{ route('admin.sales-report.index') }}" class="rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm font-black text-gray-600 hover:bg-gray-50">إعادة ضبط</a>
                            <button class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">تطبيق الفلاتر</button>
                        </div>
                    </div>
                </form>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5 text-right">
                    <p class="text-sm font-black text-indigo-700">إجمالي قيمة الطلبات</p>
                    <p class="mt-3 text-3xl font-black text-indigo-950">{{ format_money($summary['total']) }}</p>
                    <div class="mt-3">{!! $comparisonBadge($comparison['total']) !!}</div>
                </div>
                <div class="rounded-3xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                    <p class="text-sm font-black text-gray-500">قيمة العناصر</p>
                    <p class="mt-3 text-3xl font-black text-gray-950">{{ format_money($summary['items_sales']) }}</p>
                    <p class="mt-3 text-xs font-bold text-gray-400">بدون رسوم التوصيل</p>
                </div>
                <div class="rounded-3xl border border-amber-100 bg-amber-50 p-5 text-right">
                    <p class="text-sm font-black text-amber-700">رسوم التوصيل</p>
                    <p class="mt-3 text-3xl font-black text-amber-950">{{ format_money($summary['delivery']) }}</p>
                    <p class="mt-3 text-xs font-bold text-amber-700/70">مرة واحدة لكل مجموعة شراء</p>
                </div>
                <div class="rounded-3xl border border-rose-100 bg-rose-50 p-5 text-right">
                    <p class="text-sm font-black text-rose-700">إجمالي الخصومات</p>
                    <p class="mt-3 text-3xl font-black text-rose-950">{{ format_money($summary['discounts']) }}</p>
                    <p class="mt-3 text-xs font-bold text-rose-700/70">مخصومة من إجمالي قيمة الطلبات</p>
                </div>
                <div class="rounded-3xl border border-emerald-100 bg-emerald-50 p-5 text-right">
                    <p class="text-sm font-black text-emerald-700">مجموعات الشراء</p>
                    <p class="mt-3 text-3xl font-black text-emerald-950">{{ number_format($summary['checkouts']) }}</p>
                    <div class="mt-3">{!! $comparisonBadge($comparison['checkouts']) !!}</div>
                </div>
                <div class="rounded-3xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                    <p class="text-sm font-black text-gray-500">متوسط مجموعة الشراء</p>
                    <p class="mt-3 text-3xl font-black text-gray-950">{{ format_money($summary['average_checkout']) }}</p>
                    <div class="mt-3">{!! $comparisonBadge($comparison['average_checkout']) !!}</div>
                </div>
                <div class="rounded-3xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                    <p class="text-sm font-black text-gray-500">عدد القطع</p>
                    <p class="mt-3 text-3xl font-black text-gray-950">{{ number_format($summary['items_quantity']) }}</p>
                    <div class="mt-3">{!! $comparisonBadge($comparison['items_quantity']) !!}</div>
                </div>
                <div class="rounded-3xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                    <p class="text-sm font-black text-gray-500">سجلات الطلبات</p>
                    <p class="mt-3 text-3xl font-black text-gray-950">{{ number_format($summary['order_records']) }}</p>
                    <p class="mt-3 text-xs font-bold text-gray-400">قد تضم مجموعة الشراء أكثر من طلب</p>
                </div>
                <div class="rounded-3xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                    <p class="text-sm font-black text-gray-500">عملاء فريدون</p>
                    <p class="mt-3 text-3xl font-black text-gray-950">{{ number_format($summary['unique_customers']) }}</p>
                    <p class="mt-3 text-xs font-bold text-gray-400">حسب الحساب أو رقم الهاتف</p>
                </div>
            </div>

            <div class="rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
                <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs font-bold text-gray-400">الأعمدة تعرض إجمالي قيمة الطلبات في كل فترة</p>
                    <h3 class="text-lg font-black text-gray-900">اتجاه المبيعات</h3>
                </div>
                @if($trend->isEmpty())
                    <p class="rounded-2xl bg-gray-50 p-10 text-center text-sm font-black text-gray-400">لا توجد بيانات في هذه الفترة.</p>
                @else
                    <div class="flex h-72 items-end gap-3 overflow-x-auto border-b border-gray-100 px-2 pb-4">
                        @foreach($trend as $point)
                            @php $height = $point['total'] > 0 ? max(8, ($point['total'] / $trendMax) * 210) : 3; @endphp
                            <div class="flex min-w-16 flex-col items-center gap-2">
                                <span class="text-[10px] font-black text-gray-500">{{ format_money($point['total'], false) }}</span>
                                <div class="w-8 rounded-t-xl bg-gradient-to-t from-indigo-700 to-violet-400" style="height: {{ $height }}px" title="{{ format_money($point['total']) }}"></div>
                                <span class="whitespace-nowrap text-[11px] font-bold text-gray-400" dir="ltr">{{ $point['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="grid gap-6 xl:grid-cols-2">
                @include('admin.analytics.partials.simple-table', [
                    'title' => 'الأكثر مبيعاً',
                    'headers' => ['العنصر', 'النوع', 'الكمية', 'مجموعات الشراء', 'القيمة'],
                    'rows' => collect($report['top_items'])->map(fn ($item) => [
                        $item['title'],
                        $typeLabels[$item['type']] ?? $item['type'],
                        number_format($item['quantity']),
                        number_format($item['checkouts']),
                        format_money($item['sales']),
                    ])->all(),
                ])

                @include('admin.analytics.partials.simple-table', [
                    'title' => 'المبيعات حسب النوع',
                    'headers' => ['النوع', 'الكمية', 'القيمة'],
                    'rows' => collect($report['type_breakdown'])->map(fn ($item) => [
                        $item['label'], number_format($item['quantity']), format_money($item['sales']),
                    ])->all(),
                ])

                @include('admin.analytics.partials.simple-table', [
                    'title' => 'الطلبات حسب الحالة',
                    'headers' => ['الحالة', 'السجلات', 'قيمة العناصر'],
                    'rows' => collect($report['status_breakdown'])->map(fn ($item) => [
                        $item['label'], number_format($item['orders']), format_money($item['items_sales']),
                    ])->all(),
                ])

                @include('admin.analytics.partials.simple-table', [
                    'title' => 'المصادر التسويقية',
                    'headers' => ['المصدر', 'مجموعات الشراء', 'القيمة'],
                    'rows' => collect($report['source_breakdown'])->map(fn ($item) => [
                        $item['label'], number_format($item['checkouts']), format_money($item['sales']),
                    ])->all(),
                ])

                @include('admin.analytics.partials.simple-table', [
                    'title' => 'المناطق الأعلى مبيعاً',
                    'headers' => ['المنطقة', 'مجموعات الشراء', 'العملاء', 'القيمة'],
                    'rows' => collect($report['geography_breakdown'])->map(fn ($item) => [
                        $item['label'], number_format($item['checkouts']), number_format($item['customers']), format_money($item['sales']),
                    ])->all(),
                ])

                @include('admin.analytics.partials.simple-table', [
                    'title' => 'نوع العملاء',
                    'headers' => ['النوع', 'مجموعات الشراء', 'العملاء', 'القيمة'],
                    'rows' => collect($report['customer_breakdown'])->map(fn ($item) => [
                        $item['label'], number_format($item['checkouts']), number_format($item['customers']), format_money($item['sales']),
                    ])->all(),
                ])
            </div>

            <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm">
                <div class="flex flex-col gap-2 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm font-bold text-gray-500">{{ number_format($rows->total()) }} مجموعة شراء مطابقة</p>
                    <h3 class="text-lg font-black text-gray-900">تفاصيل المبيعات</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100 text-right text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">التاريخ / الطلبات</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">العميل</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">العناصر</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">الموقع / المصدر</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">الحالة</th>
                                <th class="px-4 py-3 text-xs font-black text-gray-500">القيمة</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse($rows as $row)
                                <tr class="align-top hover:bg-slate-50">
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <p class="font-black text-gray-900" dir="ltr">{{ $row['date'] }}</p>
                                        <div class="mt-2 flex max-w-56 flex-wrap gap-1">
                                            @foreach($row['order_numbers'] as $index => $number)
                                                <a href="{{ route('admin.orders.show', $row['order_ids'][$index]) }}" class="rounded-md bg-indigo-50 px-2 py-1 text-xs font-black text-indigo-700 hover:bg-indigo-100">#{{ $number }}</a>
                                            @endforeach
                                        </div>
                                        <p class="mt-2 text-[10px] text-gray-400" dir="ltr">{{ $row['key'] }}</p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <p class="font-black text-gray-900">{{ $row['customer_name'] }}</p>
                                        <p class="mt-1 text-xs text-gray-500" dir="ltr">{{ $row['phone'] ?: '—' }}</p>
                                        <span class="mt-2 inline-flex rounded-full bg-gray-100 px-2 py-1 text-[10px] font-black text-gray-600">{{ $row['customer_type'] === 'registered' ? 'مسجل' : 'زائر' }}</span>
                                    </td>
                                    <td class="max-w-sm px-4 py-4">
                                        <p class="font-bold leading-6 text-gray-800">{{ $row['items_summary'] }}</p>
                                        <p class="mt-1 text-xs text-gray-400">{{ $row['items_quantity'] }} قطعة</p>
                                    </td>
                                    <td class="px-4 py-4">
                                        <p class="font-bold text-gray-800">{{ $row['country'] }} / {{ $row['governorate'] }}</p>
                                        @if($row['city'])<p class="mt-1 text-xs text-gray-500">{{ $row['city'] }}</p>@endif
                                        <p class="mt-2 text-xs font-black text-indigo-600">{{ $row['source'] }}</p>
                                        @if($row['campaign'])<p class="mt-1 text-[10px] text-gray-400">{{ $row['campaign'] }}</p>@endif
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-black text-slate-700">{{ $row['status_label'] }}</span>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <p class="text-lg font-black text-gray-950">{{ format_money($row['total_cents'] / 100) }}</p>
                                        <p class="mt-1 text-xs text-gray-500">عناصر: {{ format_money($row['items_total_cents'] / 100) }}</p>
                                        <p class="mt-1 text-xs text-gray-400">توصيل: {{ format_money($row['delivery_cents'] / 100) }}</p>
                                        @if($row['discount_cents'] > 0)<p class="mt-1 text-xs font-bold text-rose-600">خصم: - {{ format_money($row['discount_cents'] / 100) }}</p>@endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-6 py-14 text-center">
                                        <p class="text-4xl">📊</p>
                                        <p class="mt-3 text-sm font-black text-gray-500">لا توجد مبيعات مطابقة للفلاتر المحددة.</p>
                                        <a href="{{ route('admin.sales-report.index') }}" class="mt-3 inline-flex text-sm font-black text-indigo-600 hover:underline">إعادة ضبط الفلاتر</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($rows->hasPages())
                    <div class="border-t border-gray-100 px-5 py-4">{{ $rows->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
