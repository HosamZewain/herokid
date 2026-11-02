<nav class="mb-6 flex flex-wrap gap-2" aria-label="إعدادات تطبيق الموبايل" dir="rtl">
@foreach(['overview' => 'نظرة عامة', 'games' => 'الألعاب', 'game-reports' => 'تقارير الألعاب', 'index' => 'إعدادات عامة'] as $tab => $label)
<a href="{{ route('admin.mobile-operations.'.$tab) }}" @if(request()->routeIs('admin.mobile-operations.'.$tab)) aria-current="page" @endif class="rounded-xl border px-4 py-3 font-bold {{ request()->routeIs('admin.mobile-operations.'.$tab) ? 'bg-indigo-600 text-white' : 'bg-white text-slate-700' }}">{{ $label }}</a>
@endforeach
</nav>
