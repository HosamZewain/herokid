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

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach([
                    'إجمالي المشاركات' => $summary['shares'],
                    'نسبة المشاركة من المعتمد' => $summary['share_rate'].'%',
                    'زيارات الصفحات' => $summary['views'],
                    'CTR' => $summary['ctr'].'%',
                    'هويات جديدة' => $summary['identity_starts'],
                    'هويات مكتملة' => $summary['identity_completions'],
                    'طلبات محالة' => $summary['orders'],
                    'إيراد محال' => number_format($summary['revenue'], 2).' ج.م',
                    'Viral conversion' => $summary['viral_conversion_rate'].'%',
                    'متوسط هويات / مشاركة' => $summary['average_referred_identities'],
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
                    <h3 class="font-black text-slate-900">أفضل المشاركات</h3>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-right text-sm">
                            <thead class="text-xs text-slate-500"><tr><th class="p-2">المشاركة</th><th class="p-2">التاريخ</th><th class="p-2">مشاهدات</th><th class="p-2">CTA</th><th class="p-2">هويات</th><th class="p-2">طلبات</th></tr></thead>
                            <tbody>
                                @forelse($report['top_shares'] as $share)
                                    <tr class="border-t">
                                        <td class="p-2"><a class="font-black text-indigo-700" href="{{ route('admin.child-identities.show', $share->child_identity_request_id) }}">#{{ $share->id }}</a></td>
                                        <td class="p-2">{{ $share->created_at->format('d/m/Y') }}</td>
                                        <td class="p-2">{{ arabic_number($share->total_views) }}</td>
                                        <td class="p-2">{{ arabic_number($share->total_cta_clicks) }}</td>
                                        <td class="p-2">{{ arabic_number($share->referred_identities_count) }}</td>
                                        <td class="p-2">{{ arabic_number($share->referred_orders_count) }}</td>
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
