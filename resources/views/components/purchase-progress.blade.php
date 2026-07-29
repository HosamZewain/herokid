@props([
    'current' => 1,
    'compact' => false,
])

@php
    $steps = [
        1 => 'اختر القصة',
        2 => 'أضف بيانات الطفل والصور',
        3 => 'أدخل التوصيل',
        4 => 'نراجع الطلب ونتواصل معك',
    ];
@endphp

<nav aria-label="خطوات طلب قصة مخصصة" {{ $attributes->class([
    'rounded-3xl border border-indigo-100 bg-white shadow-sm',
    'p-3 sm:p-4' => $compact,
    'p-4 sm:p-5' => ! $compact,
]) }}>
    <p class="mb-3 text-right text-sm font-black text-slate-900">خطوات الطلب كاملة</p>
    <ol class="grid grid-cols-2 gap-2 lg:grid-cols-4">
        @foreach($steps as $number => $label)
            @php
                $isCurrent = $number === (int) $current;
                $isComplete = $number < (int) $current;
            @endphp
            <li
                @if($isCurrent) aria-current="step" @endif
                class="flex min-h-14 items-center gap-2 rounded-2xl border px-3 py-2 text-right {{ $isCurrent ? 'border-indigo-500 bg-indigo-50 text-indigo-950' : ($isComplete ? 'border-emerald-100 bg-emerald-50 text-emerald-900' : 'border-slate-100 bg-slate-50 text-slate-600') }}"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-black {{ $isCurrent ? 'bg-indigo-600 text-white' : ($isComplete ? 'bg-emerald-600 text-white' : 'bg-white text-slate-500') }}">
                    {{ $isComplete ? '✓' : arabic_number($number) }}
                </span>
                <span class="text-xs font-black leading-5">{{ $label }}</span>
            </li>
        @endforeach
    </ol>
</nav>
