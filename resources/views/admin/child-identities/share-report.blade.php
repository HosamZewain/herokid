<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-slate-900">تقرير مشاركة هويات الأطفال</h2>
            <p class="mt-1 text-xs font-bold text-slate-500">مؤشرات مجمعة بدون أسماء أطفال أو بيانات أولياء الأمور.</p>
        </div>
    </x-slot>

    @php
        $summary = $report['summary'];
        $channelLabels = [
            'whatsapp' => 'واتساب', 'facebook' => 'فيسبوك', 'instagram' => 'إنستجرام',
            'native' => 'قائمة المشاركة بالجهاز', 'copy_link' => 'نسخ الرابط',
            'copy_caption' => 'نسخ النص', 'download_feed' => 'تنزيل صورة المنشور',
            'download_story' => 'تنزيل صورة الستوري', 'download' => 'تنزيل الصورة', 'unknown' => 'غير محدد',
        ];
    @endphp
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6">
            <form method="GET" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-3">
                <label for="share-filter-from" class="text-sm font-bold">من
                    <input id="share-filter-from" type="date" name="date_from" value="{{ $report['period']['from'] }}" class="mt-1 w-full rounded-xl border-slate-300">
                </label>
                <label for="share-filter-to" class="text-sm font-bold">إلى
                    <input id="share-filter-to" type="date" name="date_to" value="{{ $report['period']['to'] }}" class="mt-1 w-full rounded-xl border-slate-300">
                </label>
                <button class="self-end rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">تطبيق</button>
            </form>

            <div class="rounded-2xl bg-slate-50 p-4 text-sm leading-7 text-slate-700">
                <p>الفترة المعروضة: <bdi dir="ltr">{{ $report['period']['from'] }} → {{ $report['period']['to'] }}</bdi> · بتوقيت القاهرة.</p>
                <p>المشاهدات والضغطات حسب وقت حدوثها، حتى للمشاركات القديمة. المشاركات الجديدة حسب تاريخ إنشائها. قيمة الطلبات ورصيدها المدفوع ليسا تحصيل الفترة.</p>
                <details class="mt-2">
                    <summary class="cursor-pointer font-bold text-indigo-700">تفاصيل احتساب باقي المؤشرات</summary>
                    <p class="mt-2">نسبة المشاركة تخص طلبات الهوية المنشأة في الفترة والمعتمدة حاليًا والتي أُنشئت لها مشاركة حتى نهاية الفترة. يشمل نشاط الفترة المشاركات المحذوفة أيضًا. الطلبات لكل 100 مشاهدة مؤشر أحداث، وليس نسبة تحويل أشخاص.</p>
                </details>
            </div>

            <section aria-labelledby="share-ctr-heading" class="rounded-2xl border border-indigo-200 bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 id="share-ctr-heading" class="text-lg font-black text-indigo-900">نسبة النقر من زوار المشاركات</h3>
                        <p class="mt-1 text-sm leading-7 text-slate-700">{{ number_format($summary['clicked_viewers']) }} ضغطوا من أصل {{ number_format($summary['identified_viewers']) }} زائرًا معروفًا للمشاركات في الفترة.</p>
                    </div>
                    <div class="rounded-xl bg-indigo-50 px-5 py-3 text-center">
                        <p class="text-3xl font-black text-indigo-800">{{ $summary['ctr'] === null ? 'غير متاحة' : $summary['ctr'].'%' }}</p>
                        @if($summary['identified_viewers'] > 0)
                            <p class="mt-1 text-sm text-indigo-800" dir="ltr">{{ $summary['clicked_viewers'] }} ÷ {{ $summary['identified_viewers'] }} × 100</p>
                        @endif
                    </div>
                </div>
                <p class="mt-3 text-sm leading-7 text-slate-600">نحسب الزائر مرة واحدة لكل مشاركة، مهما كرر الضغط. لو شاهد مشاركتين يُحسب مرة في كل واحدة. يلزم معرّف زائر ومشاهدة وضغطة لنفس المشاركة داخل الفترة؛ هذا معرّف للمتصفح وليس اسم العميل.</p>
                @if($summary['identified_viewers'] === 0)
                    <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm font-bold text-amber-900">لا توجد مشاهدات بمعرّف زائر في هذه الفترة؛ لذلك النسبة غير متاحة وليست 0%.</p>
                @elseif($summary['cta_clicks'] > 0 && $summary['clicked_viewers'] === 0)
                    <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm font-bold text-amber-900">توجد ضغطات، لكن لا توجد ضغطة مرتبطة بمشاهدة مؤهلة في نفس الفترة. الأسباب بالأعداد أدناه.</p>
                @endif
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <caption class="pb-2 text-right font-bold text-slate-800">تفصيل ضغطات الزر — إجمالي {{ number_format($summary['cta_clicks']) }}</caption>
                        <thead class="bg-slate-50 text-slate-700"><tr><th scope="col" class="p-3">التصنيف</th><th scope="col" class="p-3">العدد</th><th scope="col" class="p-3">يدخل في النسبة؟</th></tr></thead>
                        <tbody>
                            @foreach([
                                ['زوار شاهدوا وضغطوا على نفس المشاركة', 'clicked_viewers', 'نعم، مرة لكل زائر ومشاركة'],
                                ['ضغطات إضافية من نفس الزوار على نفس المشاركات', 'repeated_eligible_click_events', 'لا، لمنع تكرار العدّ'],
                                ['ضغطات بدون معرّف زائر', 'anonymous_click_events', 'لا، لا يمكن مطابقتها بالمشاهدة'],
                                ['ضغطات بمعرّف زائر لكن دون مشاهدة مطابقة في الفترة', 'unmatched_click_events', 'لا، قد تكون المشاهدة خارج الفترة أو غير مسجلة'],
                            ] as [$label, $key, $explanation])
                                <tr class="border-t border-slate-100"><th scope="row" class="p-3 font-medium">{{ $label }}</th><td class="p-3 font-black" data-ctr-count="{{ $key }}">{{ number_format($summary[$key]) }}</td><td class="p-3 text-slate-600">{{ $explanation }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-sm text-slate-600">مجموع التصنيفات يساوي إجمالي الضغطات. جدول المشاركات بالأسفل يعرض كل الضغطات، بما فيها التكرار وغير المؤهلة للنسبة.</p>
            </section>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach([
                    'مشاركات أُنشئت في الفترة' => $summary['shares'],
                    'نسبة المشاركة من طلبات الفترة المعتمدة' => $summary['share_rate'] === null ? '—' : $summary['share_rate'].'%',
                    'زيارات الصفحات' => $summary['views'],
                    'هويات جديدة' => $summary['identity_starts'],
                    'هويات مكتملة' => $summary['identity_completions'],
                    'طلبات محالة' => $summary['orders'],
                    'قيمة الطلبات المحالة — ليست تحصيلًا' => format_report_money($summary['revenue']),
                    'الرصيد المدفوع على الطلبات المحالة' => format_report_money($summary['paid_balance']),
                    'طلبات محالة لكل 100 مشاهدة' => $summary['viral_conversion_rate'] ?? '—',
                    'متوسط هويات / مشاركة بها نشاط' => $summary['average_referred_identities'],
                ] as $label => $value)
                    <article class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
                        <p class="mt-2 text-2xl font-black text-indigo-700">{{ $value }}</p>
                    </article>
                @endforeach
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="rounded-2xl border border-slate-200 bg-white p-5">
                    <h3 class="font-black text-slate-900">أداء القنوات</h3>
                    <p class="mt-1 text-sm text-slate-600">أكثر قناة استخدامًا: {{ $channelLabels[$summary['best_channel']] ?? $summary['best_channel'] }}</p>
                    <div class="mt-4 space-y-2">
                        @forelse($report['channels'] as $row)
                            <div class="flex justify-between rounded-xl bg-slate-50 p-3 text-sm"><strong>{{ $channelLabels[$row['channel']] ?? $row['channel'] }}</strong><span>{{ arabic_number($row['events']) }}</span></div>
                        @empty
                            <p class="text-sm text-slate-400">لا توجد نقرات قنوات.</p>
                        @endforelse
                    </div>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-2">
                    <h3 class="font-black text-slate-900">أفضل المشاركات حسب نشاط الفترة فقط</h3>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="text-xs text-slate-600"><tr><th scope="col" class="p-2">المشاركة</th><th scope="col" class="p-2">تاريخ الإنشاء</th><th scope="col" class="p-2">مشاهدات</th><th scope="col" class="p-2">كل ضغطات الزر (بما فيها التكرار)</th><th scope="col" class="p-2">هويات</th><th scope="col" class="p-2">طلبات</th></tr></thead>
                            <tbody>
                                @forelse($report['top_shares'] as $share)
                                    <tr class="border-t">
                                        <td class="p-2"><a class="font-black text-indigo-700" href="{{ route('admin.child-identities.show', $share->child_identity_request_id) }}">#{{ $share->id }}</a></td>
                                        <td class="p-2">{{ app_datetime($share->created_at, 'd/m/Y') }}</td>
                                        <td class="p-2">{{ arabic_number($share->period_views) }}</td>
                                        <td class="p-2">{{ arabic_number($share->period_clicks) }}</td>
                                        <td class="p-2">{{ arabic_number($share->period_identities) }}</td>
                                        <td class="p-2">{{ arabic_number($share->period_orders) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="p-8 text-center text-slate-400">لا توجد مشاركات في الفترة.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
</x-admin-layout>
