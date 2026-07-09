@component('admin.layouts.admin')
    @slot('header')
        <div class="text-right">
            <h2 class="text-xl font-bold text-gray-800">تحليلات الموقع</h2>
            <p class="text-xs text-gray-500">Google Analytics 4 - بيانات الزيارات والتحويلات</p>
        </div>
    @endslot

    @php
        $status = $analytics['status'] ?? 'error';
        $summary = $analytics['summary'] ?? [];
        $formatValue = function ($value, bool $money = false, bool $percent = false): string {
            if ($value === null || $value === '') {
                return 'غير متاح';
            }
            if ($money) {
                return number_format((float) $value, 2).' ج.م';
            }
            if ($percent) {
                return number_format((float) $value, 2).'%';
            }
            return number_format((float) $value);
        };
        $changeBadge = function ($change): string {
            if ($change === null) {
                return '<span class="text-xs font-bold text-gray-400">لا توجد مقارنة</span>';
            }
            $class = $change >= 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700';
            $prefix = $change >= 0 ? '+' : '';
            return '<span class="rounded-full px-2 py-1 text-xs font-black '.$class.'">'.$prefix.number_format((float) $change, 1).'% عن أمس</span>';
        };
        $chartRows = collect($analytics['chart'] ?? []);
        $chartMax = max(1, (int) $chartRows->max(fn ($row) => max((int) ($row['users'] ?? 0), (int) ($row['sessions'] ?? 0))));
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        <div class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
            <form method="GET" action="{{ route('admin.analytics.index') }}" class="grid gap-3 lg:grid-cols-[1fr_180px_180px_160px] lg:items-end">
                <div class="text-right">
                    <label class="mb-2 block text-sm font-bold text-gray-700">الفترة</label>
                    <select name="range" class="w-full rounded-xl border-gray-200 text-right">
                        <option value="today" @selected($range->key === 'today')>اليوم</option>
                        <option value="yesterday" @selected($range->key === 'yesterday')>أمس</option>
                        <option value="last_7_days" @selected($range->key === 'last_7_days')>آخر 7 أيام</option>
                        <option value="last_30_days" @selected($range->key === 'last_30_days')>آخر 30 يوم</option>
                        <option value="custom" @selected($range->key === 'custom')>فترة مخصصة</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-bold text-gray-700">من</label>
                    <input type="date" name="start_date" value="{{ $range->startDate }}" class="w-full rounded-xl border-gray-200">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-bold text-gray-700">إلى</label>
                    <input type="date" name="end_date" value="{{ $range->endDate }}" class="w-full rounded-xl border-gray-200">
                </div>
                <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">تطبيق</button>
            </form>
            <div class="mt-4 flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-right text-sm text-gray-500">
                    الفترة الحالية: <span class="font-bold text-gray-800">{{ $range->label }}</span>
                    <span dir="ltr">({{ $range->startDate }} → {{ $range->endDate }})</span>
                </p>
                <form method="POST" action="{{ route('admin.analytics.refresh') }}">
                    @csrf
                    <button class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700 hover:bg-indigo-100">تحديث البيانات من Google</button>
                </form>
            </div>
        </div>

        @if($status === 'setup_required')
            <div class="rounded-3xl border border-amber-200 bg-amber-50 p-8 text-right">
                <h3 class="text-xl font-black text-amber-900">إعداد Google Analytics غير مكتمل</h3>
                <p class="mt-2 leading-8 text-amber-800">{{ $analytics['message'] ?? 'أضف متغيرات البيئة الخاصة بـ GA4 ثم امسح كاش الإعدادات.' }}</p>
                <div class="mt-4 rounded-2xl bg-white/70 p-4 font-mono text-sm text-amber-900 ltr:text-left" dir="ltr">
GA4_PROPERTY_ID=<br>
GOOGLE_ANALYTICS_CREDENTIALS_PATH=/home/u470070883/private/ga4-service-account.json<br>
# أو GOOGLE_ANALYTICS_CREDENTIALS_BASE64=
                </div>
            </div>
        @elseif($status === 'error')
            <div class="rounded-3xl border border-red-200 bg-red-50 p-8 text-right">
                <h3 class="text-xl font-black text-red-900">تعذر تحميل بيانات التحليلات</h3>
                <p class="mt-2 leading-8 text-red-800">{{ $analytics['message'] ?? 'حاول مرة أخرى بعد قليل.' }}</p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                @foreach([
                    'active_users_30m' => ['icon' => '●', 'money' => false, 'percent' => false],
                    'users_today' => ['icon' => '👥', 'money' => false, 'percent' => false],
                    'sessions_today' => ['icon' => '🧭', 'money' => false, 'percent' => false],
                    'views_today' => ['icon' => '👁', 'money' => false, 'percent' => false],
                    'new_users_today' => ['icon' => '✨', 'money' => false, 'percent' => false],
                    'purchases_today' => ['icon' => '🛒', 'money' => false, 'percent' => false],
                    'revenue_today' => ['icon' => 'ج.م', 'money' => true, 'percent' => false],
                    'conversion_rate_today' => ['icon' => '%', 'money' => false, 'percent' => true],
                ] as $key => $meta)
                    @php
                        $card = $summary[$key] ?? ['label' => $key, 'value' => null, 'change' => null];
                    @endphp
                    <div class="rounded-3xl border border-gray-100 bg-white p-5 text-right shadow-sm">
                        <div class="flex items-center justify-between">
                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-50 text-sm font-black text-indigo-700">{{ $meta['icon'] }}</span>
                            <p class="text-sm font-bold text-gray-500">{{ $card['label'] }}</p>
                        </div>
                        <p class="mt-4 text-3xl font-black text-gray-950">{{ $formatValue($card['value'] ?? null, $meta['money'], $meta['percent']) }}</p>
                        <div class="mt-3">{!! $changeBadge($card['change'] ?? null) !!}</div>
                    </div>
                @endforeach
            </div>

            <div class="rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
                <div class="mb-5 flex items-center justify-between">
                    <div class="flex items-center gap-4 text-xs font-bold">
                        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-full bg-indigo-600"></span> المستخدمون</span>
                        <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded-full bg-amber-400"></span> الجلسات</span>
                    </div>
                    <h3 class="text-right text-lg font-black text-gray-900">المستخدمون والجلسات اليومية</h3>
                </div>
                @if($chartRows->isEmpty())
                    <p class="rounded-2xl bg-gray-50 p-8 text-center text-sm font-bold text-gray-500">لا توجد بيانات متاحة لهذه الفترة.</p>
                @else
                    <div class="flex h-72 items-end gap-3 overflow-x-auto border-b border-gray-100 px-2 pb-4">
                        @foreach($chartRows as $row)
                            @php
                                $usersHeight = max(4, ((int) ($row['users'] ?? 0) / $chartMax) * 220);
                                $sessionsHeight = max(4, ((int) ($row['sessions'] ?? 0) / $chartMax) * 220);
                            @endphp
                            <div class="flex min-w-14 flex-col items-center gap-2">
                                <div class="flex h-56 items-end gap-1">
                                    <div title="Users: {{ $row['users'] ?? 0 }}" class="w-5 rounded-t-lg bg-indigo-600" style="height: {{ $usersHeight }}px"></div>
                                    <div title="Sessions: {{ $row['sessions'] ?? 0 }}" class="w-5 rounded-t-lg bg-amber-400" style="height: {{ $sessionsHeight }}px"></div>
                                </div>
                                <span class="text-[11px] font-bold text-gray-400" dir="ltr">{{ substr((string) $row['date'], 4, 2) }}/{{ substr((string) $row['date'], 6, 2) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                @include('admin.analytics.partials.simple-table', [
                    'title' => 'مصادر الزيارات',
                    'headers' => ['المصدر', 'المستخدمون', 'الجلسات', 'التحويلات'],
                    'rows' => collect($analytics['sources'] ?? [])->map(fn ($row) => [
                        $row['source'],
                        $formatValue($row['users']),
                        $formatValue($row['sessions']),
                        $row['conversions'] === null ? 'غير متاح' : $formatValue($row['conversions']),
                    ])->all(),
                ])

                @include('admin.analytics.partials.simple-table', [
                    'title' => 'أكثر الصفحات زيارة',
                    'headers' => ['العنوان', 'المسار', 'المشاهدات', 'المستخدمون', 'متوسط التفاعل'],
                    'rows' => collect($analytics['popular_pages'] ?? [])->map(fn ($row) => [
                        $row['title'],
                        $row['path'],
                        $formatValue($row['views']),
                        $formatValue($row['users']),
                        \App\Services\Analytics\AnalyticsMetricNormalizer::secondsLabel($row['average_engagement']),
                    ])->all(),
                    'ltrColumn' => 1,
                ])
            </div>

            <div class="rounded-3xl border border-gray-100 bg-white p-6 shadow-sm">
                <h3 class="text-right text-lg font-black text-gray-900">قمع التجارة الإلكترونية</h3>
                <p class="mt-1 text-right text-sm text-gray-500">يعتمد على أحداث GA4 القياسية: view_item, add_to_cart, begin_checkout, purchase.</p>
                <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-4">
                    @foreach($analytics['funnel'] ?? [] as $step)
                        <div class="rounded-2xl border border-indigo-50 bg-indigo-50/60 p-4 text-right">
                            <p class="text-sm font-black text-indigo-900">{{ $step['label'] }}</p>
                            <p class="mt-3 text-3xl font-black text-gray-950">{{ $step['available'] ? $formatValue($step['value']) : 'غير متاح' }}</p>
                            <p class="mt-2 text-xs font-bold text-gray-500">Drop-off: {{ $step['drop_off'] === null ? 'غير متاح' : number_format($step['drop_off'], 1).'%' }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                @foreach([
                    'source_details' => ['title' => 'تفاصيل source / medium', 'first' => 'المصدر / الوسيط'],
                    'devices' => ['title' => 'الأجهزة', 'first' => 'نوع الجهاز'],
                    'locations' => ['title' => 'الدول والمدن', 'first' => 'الموقع'],
                    'landing_pages' => ['title' => 'صفحات الهبوط', 'first' => 'الصفحة'],
                    'campaigns' => ['title' => 'الحملات و UTM', 'first' => 'الحملة / المصدر'],
                ] as $key => $meta)
                    @include('admin.analytics.partials.simple-table', [
                        'title' => $meta['title'],
                        'headers' => [$meta['first'], 'المستخدمون', 'الجلسات'],
                        'rows' => collect($analytics[$key] ?? [])->map(fn ($row) => [
                            $row['label'],
                            $formatValue($row['users']),
                            $formatValue($row['sessions']),
                        ])->all(),
                    ])
                @endforeach
            </div>
        @endif
    </div>
@endcomponent
