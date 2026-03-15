@props(['active' => false])

<a {{ $attributes->merge(['class' => 'px-6 py-2 rounded-2xl text-xs font-black transition-all ' . ($active ? 'bg-indigo-600 text-white shadow-xl shadow-indigo-100' : 'text-slate-500 hover:bg-slate-50 hover:text-indigo-600')]) }}>
    {{ $slot }}
</a>
