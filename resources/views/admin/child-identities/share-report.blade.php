<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-slate-900">تقرير مشاركة هويات الأطفال</h2>
            <p class="mt-1 text-xs font-bold text-slate-500">مؤشرات مجمعة بدون أسماء أطفال أو بيانات أولياء الأمور.</p>
        </div>
    </x-slot>

    @php $summary = $report['summary']; @endphp
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6">
            <form method="GET" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-3">
                <label class="text-sm font-bold">من
                    <input type="date" name="date_from" value="{{ $report['period']['from'] }}" class="mt-1 w-full rounded-xl border-slate-300">
                </label>
                <label class="text-sm font-bold">إلى
                    <input type="date" name="date_to" value="{{ $report['period']['to'] }}" class="mt-1 w-full rounded-xl border-slate-300">
                </label>
                <button class="self-end rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">تطبيق</button>
            </form>

            <p class="text-sm leading-7 text-slate-600">الأحداث والقنوات والجدول حسب وقت الحدث في الفترة بتوقيت القاهرة، حتى للمشاركات الأقدم أو المحذوفة. المشاركات الجديدة حسب تاريخ إنشائها. نسبة المشاركة تخص طلبات الهوية المنشأة في الفترة والمعتمدة حاليًا والتي أُنشئت لها مشاركة حتى نهاية الفترة. قيمة الطلبات المحالة ورصيدها من الحساب المالي الحالي، وليسا تحصيل الفترة.</p>
            <p class="text-xs leading-6 text-slate-500">نسبة النقر = زوار شاهدوا وضغطوا لنفس المشاركة خلال الفترة ÷ زوارها المعروفين ({{ $summary['identified_viewers'] }} زوج زائر/مشاركة). تُستبعد الأحداث بلا معرّف زائر من هذه النسبة فقط. الطلبات لكل 100 مشاهدة مؤشر أحداث، وليس نسبة تحويل أشخاص.</p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach([
                    'مشاركات أُنشئت في الفترة' => $summary['shares'],
                    'نسبة المشاركة من طلبات الفترة المعتمدة' => $summary['share_rate'] === null ? '—' : $summary['share_rate'].'%',
                    'زيارات الصفحات' => $summary['views'],
                    'نسبة الزوار الذين ضغطوا' => $summary['ctr'] === null ? '—' : $summary['ctr'].'%',
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
                    <p class="mt-1 text-xs text-slate-500">أفضل قناة: {{ $summary['best_channel'] }}</p>
                    <div class="mt-4 space-y-2">
                        @forelse($report['channels'] as $row)
                            <div class="flex justify-between rounded-xl bg-slate-50 p-3 text-sm"><strong>{{ $row['channel'] }}</strong><span>{{ arabic_number($row['events']) }}</span></div>
                        @empty
                            <p class="text-sm text-slate-400">لا توجد نقرات قنوات.</p>
                        @endforelse
                    </div>
                </section>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-2">
                    <h3 class="font-black text-slate-900">أفضل المشاركات حسب نشاط الفترة فقط</h3>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="text-xs text-slate-500"><tr><th class="p-2">المشاركة</th><th class="p-2">تاريخ الإنشاء</th><th class="p-2">مشاهدات</th><th class="p-2">ضغطات الزر</th><th class="p-2">هويات</th><th class="p-2">طلبات</th></tr></thead>
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
