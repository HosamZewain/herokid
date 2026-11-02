<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold">إعدادات تطبيق الموبايل — {{ request()->routeIs('*.overview') ? 'نظرة عامة' : 'تقارير الألعاب' }}</h2></x-slot>
<div class="space-y-6" dir="rtl">
@include('admin.mobile-operations.tabs')
@if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<form method="GET" class="flex flex-wrap items-end gap-4 rounded-2xl border bg-white p-5">
<label>من<input type="date" name="from" value="{{ $filters['from'] }}" class="mt-1 block rounded-lg border-gray-300"></label>
<label>إلى<input type="date" name="to" value="{{ $filters['to'] }}" class="mt-1 block rounded-lg border-gray-300"></label>
<label>المنصة<select name="platform" class="mt-1 block rounded-lg border-gray-300"><option value="">الكل</option>@foreach(['ios'=>'iOS','android'=>'Android'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['platform'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
<label>إصدار التطبيق<input name="app_version" maxlength="32" value="{{ $filters['app_version'] ?? '' }}" placeholder="الكل" dir="ltr" class="mt-1 block w-32 rounded-lg border-gray-300"></label>
<label>اللعبة<select name="game_id" class="mt-1 block rounded-lg border-gray-300"><option value="">الكل</option>@foreach($definitions as $id=>$definition)<option value="{{ $id }}" @selected(($filters['game_id'] ?? '') === $id)>{{ $definition[0] }}</option>@endforeach</select></label>
<label>المستوى<select name="level" class="mt-1 block rounded-lg border-gray-300"><option value="">الكل</option>@foreach([1,2,3,4,6,8,9,16] as $level)<option value="{{ $level }}" @selected((string)($filters['level'] ?? '') === (string)$level)>{{ $level }}</option>@endforeach</select></label>
<button class="rounded-xl bg-indigo-600 px-5 py-3 text-white">عرض</button>
</form>
<p class="rounded-xl bg-blue-50 p-4 text-sm">الفترة بأيام القاهرة، حسب وقت اللعب. نسبة الإكمال = جولات بدأت في الفترة واكتملت ÷ الجولات التي بدأت فيها؛ تُحدَّث عند وصول الإكمال المتأخر. عدد الجلسات والأحداث لا يمثل عدد الأطفال أو التحميلات. يبدأ القياس عند ربط نسخة الموبايل الجديدة.</p>
<section><h3 class="mb-3 font-bold">نشاط التطبيق — حسب الفترة والمنصة والإصدار، مستقل عن فلتر اللعبة والمستوى</h3><div class="grid gap-4 md:grid-cols-3">
@foreach(['app_opened'=>'فتح التطبيق','screen_viewed'=>'فتح الشاشات','sessions_started'=>'جلسات مؤقتة بدأت'] as $key=>$label)<div class="rounded-xl border bg-white p-5"><p>{{ $label }}</p><strong class="text-3xl">{{ number_format((int)$activity->$key) }}</strong></div>@endforeach
</div></section>
<section class="grid gap-4 md:grid-cols-4">
@foreach(['game_opened'=>'فتح الألعاب','game_started'=>'جولات بدأت','game_completed'=>'أحداث إكمال خلال الفترة','matched_completed'=>'اكتملت من جولات الفترة'] as $key=>$label)<div class="rounded-xl border bg-white p-5"><p>{{ $label }}</p><strong class="text-3xl">{{ number_format((int)$totals->$key) }}</strong></div>@endforeach
</section>
<p class="rounded-xl border bg-white p-5">نسبة الإكمال: <strong>{{ $totals->game_started ? number_format(100*$totals->matched_completed/$totals->game_started,1).'%' : '—' }}</strong> · متوسط زمن اللعب الفعلي: <strong>{{ $totals->duration_count ? number_format($totals->duration_sum/$totals->duration_count/1000,1).' ثانية' : 'غير متاح' }}</strong> · متوسط المحاولات المسجلة: <strong>{{ $totals->moves_count ? number_format($totals->moves_sum/$totals->moves_count,1) : 'غير متاح' }}</strong><br><span class="text-sm text-slate-500">عينات الزمن {{ (int)$totals->duration_count }}، عينات المحاولات {{ (int)$totals->moves_count }}. آخر استقبال للألعاب المطابقة: {{ $lastReceived ? app_datetime($lastReceived,'Y-m-d H:i:s') : 'لم تصل أحداث بعد' }}.</span></p>
<section class="rounded-2xl border bg-white p-5"><h3 class="font-bold">مقارنة الألعاب والمستويات — الأكثر بدءًا أولًا</h3><div class="overflow-x-auto"><table class="mt-4 w-full text-sm"><thead><tr>@foreach(['اللعبة','المستوى','فتح','بدء','أحداث إكمال','اكتملت من البدايات','نسبة الإكمال','متوسط ثانية','متوسط المحاولات'] as $heading)<th class="p-3 text-right">{{ $heading }}</th>@endforeach</tr></thead><tbody>
@forelse($comparison->sortByDesc('game_started') as $row)<tr class="border-t"><td class="p-3">{{ $definitions[$row->game_id][0] }}</td><td class="p-3">{{ $row->level ?: 'بدون جولة' }}</td><td class="p-3">{{ $row->game_opened }}</td><td class="p-3">{{ $row->game_started }}</td><td class="p-3">{{ $row->game_completed }}</td><td class="p-3">{{ $row->matched_completed }}</td><td class="p-3">{{ $row->game_started ? number_format(100*$row->matched_completed/$row->game_started,1).'%' : '—' }}</td><td class="p-3">{{ $row->duration_count ? number_format($row->duration_sum/$row->duration_count/1000,1) : '—' }}</td><td class="p-3">{{ $row->moves_count ? number_format($row->moves_sum/$row->moves_count,1) : '—' }}</td></tr>@empty<tr><td colspan="9" class="p-8 text-center text-slate-500">لا توجد أحداث مطابقة. ظهور الأرقام يحتاج نسخة موبايل مرتبطة بمسار القياس الجديد.</td></tr>@endforelse
</tbody></table></div></section>
<section class="rounded-2xl border bg-white p-5"><h3 class="font-bold">الجولات يوميًا — الأزرق بدأت، الأخضر اكتملت من بدايات نفس اليوم</h3>
@php($max = max(1, max(array_column($days,'started'))))
<div class="mt-4 space-y-3">@foreach($days as $day)<div class="flex items-center gap-4 text-sm"><span class="w-24 shrink-0" dir="ltr">{{ $day['day'] }}</span><div class="flex-1 space-y-1"><div class="h-3 rounded bg-indigo-500" style="width:{{ 100*$day['started']/$max }}%"></div><div class="h-3 rounded bg-emerald-500" style="width:{{ 100*$day['completed']/$max }}%"></div></div><span class="w-24 shrink-0">{{ $day['started'] }} / {{ $day['completed'] }}</span></div>@endforeach</div>
</section>
</div>
</x-admin-layout>
