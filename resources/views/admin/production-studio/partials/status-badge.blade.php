@php
    $tone = $tone ?? 'gray';
    $classes = [
        'emerald' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'red' => 'bg-red-50 text-red-700 border-red-200',
        'amber' => 'bg-amber-50 text-amber-700 border-amber-200',
        'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
        'gray' => 'bg-gray-100 text-gray-700 border-gray-200',
        'blue' => 'bg-blue-50 text-blue-700 border-blue-200',
    ][$tone] ?? 'bg-gray-100 text-gray-700 border-gray-200';
@endphp

<span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-black {{ $classes }}">
    {{ $label }}
</span>
