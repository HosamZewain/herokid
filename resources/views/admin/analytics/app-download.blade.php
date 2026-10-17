<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold">إحصائيات صفحة التطبيق</h2></x-slot>
<div class="mx-auto max-w-7xl space-y-6" dir="rtl">
    <p class="text-slate-600">زوار صفحة <a href="{{ route('app.download') }}" class="text-indigo-700 underline" target="_blank" rel="noopener">/app</a> والتفاعل مع زري تنزيل التطبيق. الأرقام محلية ولا تحتاج إعداد Google Analytics. الأيام بتوقيت القاهرة.</p>
    @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $errors->first() }}</div>@endif
    <form class="grid gap-4 rounded-2xl bg-white p-5 shadow-sm md:grid-cols-4" method="GET" data-app-analytics-filter>
        <label>الفترة<select name="range" class="mt-2 w-full rounded-xl border-slate-300">@foreach(['today' => 'اليوم', 'yesterday' => 'أمس', 'last_7_days' => 'آخر 7 أيام', 'last_30_days' => 'آخر 30 يومًا', 'custom' => 'فترة مخصصة'] as $value => $label)<option value="{{ $value }}" @selected($range === $value)>{{ $label }}</option>@endforeach</select></label>
        <label>من<input type="date" name="start_date" value="{{ $start_date }}" class="mt-2 w-full rounded-xl border-slate-300" data-custom-range></label>
        <label>إلى<input type="date" name="end_date" value="{{ $end_date }}" class="mt-2 w-full rounded-xl border-slate-300" data-custom-range></label>
        <button class="self-end rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">عرض الإحصائيات</button>
    </form>
    <p class="text-sm text-slate-600">الفترة: {{ $start_date }} ← {{ $end_date }}</p>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach(['visitors' => 'زوار فريدون', 'views' => 'مرات مشاهدة الصفحة', 'ios_visitors' => 'زوار ضغطوا تنزيل iOS', 'android_visitors' => 'زوار ضغطوا تنزيل Android'] as $key => $title)
        <article class="rounded-2xl border border-indigo-100 bg-white p-6"><h3 class="font-bold text-slate-600">{{ $title }}</h3><p class="mt-3 text-3xl font-black text-indigo-900" data-app-metric="{{ $key }}">{{ number_format($summary[$key]) }}</p></article>
        @endforeach
    </div>
    <div class="rounded-2xl bg-indigo-50 p-5 leading-8 text-indigo-950">
        إجمالي الضغطات: iOS <strong>{{ number_format($summary['ios_clicks']) }}</strong> · Android <strong>{{ number_format($summary['android_clicks']) }}</strong>.
        نسبة الزوار الذين ضغطوا أي متجر: <strong>{{ number_format($summary['visitors'] ? 100 * $summary['clicked_visitors'] / $summary['visitors'] : 0, 1) }}%</strong>.
        قد يظهر نفس الزائر في عدادي iOS وAndroid لو ضغط الزرين، لكنه يُحسب مرة واحدة في هذه النسبة.
    </div>
    <div class="overflow-x-auto rounded-2xl bg-white p-4 shadow-sm"><table class="w-full whitespace-nowrap text-right text-sm"><caption class="p-3 text-right text-lg font-bold">التفصيل اليومي بتوقيت القاهرة</caption><thead><tr class="bg-slate-50">@foreach(['اليوم', 'زوار فريدون', 'مشاهدات', 'زوار ضغطوا iOS', 'ضغطات iOS', 'زوار ضغطوا Android', 'ضغطات Android'] as $label)<th class="p-3">{{ $label }}</th>@endforeach</tr></thead><tbody>
    @foreach($daily as $row)<tr class="border-t"><th class="p-3 font-semibold">{{ $row['date'] }}</th>@foreach(['visitors', 'views', 'ios_visitors', 'ios_clicks', 'android_visitors', 'android_clicks'] as $key)<td class="p-3">{{ number_format($row[$key]) }}</td>@endforeach</tr>@endforeach
    </tbody></table></div>
    <div class="space-y-2 rounded-2xl bg-amber-50 p-5 text-sm leading-7 text-amber-950">
        <p><strong>هذه ضغطات انتقال إلى المتجر، وليست تنزيلات أو تثبيتات مؤكدة.</strong></p>
        <p>الزائر الفريد هو متصفح بمعرّف عشوائي، وليس بالضرورة شخصًا منفصلًا. تغيير الجهاز أو حذف الكوكيز قد يحسب زائرًا جديدًا. يُحسب الزائر مرة واحدة خلال الفترة، لذلك لا تجمع أعداد الزوار اليومية للحصول على الإجمالي.</p>
        <p>كل تحميل جديد للصفحة يُحسب مشاهدة. الضغط على الزر العلوي والسفلي لنفس المتجر في نفس تحميل الصفحة لا يضاعف العدّاد. زيارة أو ضغطة محجوبة أو بلا مُحيل قد لا تُسجّل؛ تُستبعد حسابات الأدمن والبوتات المعروفة وطلبات التحميل المسبق وإشارات عدم التتبع.</p>
        <p>القياس يبدأ بعد نشر التحديث، ولا يوجد استرجاع بأثر رجعي. أول نشاط مسجل: {{ $first_recorded_date ?: 'لا يوجد نشاط مسجل بعد' }}.</p>
    </div>
</div>
@push('scripts')
<script>
document.querySelectorAll('[data-app-analytics-filter] [data-custom-range]').forEach(input => {
    input.addEventListener('change', () => { input.form.elements.range.value = 'custom'; });
});
</script>
@endpush
</x-admin-layout>
