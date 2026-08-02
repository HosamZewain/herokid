<div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3" dir="rtl">
    @foreach($packages as $package)
        <article class="flex h-full flex-col rounded-3xl border {{ $package->is_featured ? 'border-fuchsia-300 bg-gradient-to-br from-violet-950 to-indigo-950 text-white shadow-xl shadow-indigo-200' : 'border-slate-200 bg-white text-slate-950 shadow-sm' }} p-5 text-right">
            <div class="flex items-start justify-between gap-3">
                <div>
                    @if($package->badge)<span class="inline-flex rounded-full bg-amber-300 px-3 py-1 text-[11px] font-black text-amber-950">{{ $package->badge }}</span>@endif
                    <h3 class="mt-2 text-xl font-black">{{ $package->name }}</h3>
                    @if($package->subtitle)<p class="mt-1 text-sm {{ $package->is_featured ? 'text-indigo-200' : 'text-slate-500' }}">{{ $package->subtitle }}</p>@endif
                </div>
                <span class="text-3xl" aria-hidden="true">🎁</span>
            </div>
            <p class="mt-4 text-sm font-bold leading-7 {{ $package->is_featured ? 'text-white' : 'text-indigo-700' }}">{{ $package->componentSummary() }}</p>
            @if($package->features)
                <ul class="mt-3 space-y-1 text-xs leading-6 {{ $package->is_featured ? 'text-indigo-100' : 'text-slate-500' }}">
                    @foreach(array_slice($package->features, 0, 3) as $feature)<li>✓ {{ $feature }}</li>@endforeach
                </ul>
            @endif
            <div class="mt-auto flex items-end justify-between gap-3 pt-5">
                <a href="{{ route('shop.package.show', $package) }}" class="rounded-xl bg-gradient-to-l from-violet-600 to-fuchsia-500 px-4 py-2.5 text-sm font-black text-white transition hover:brightness-110">اختر الباقة</a>
                <div class="text-left"><span class="text-2xl font-black">{{ format_money((float) $package->price) }}</span><p class="text-[10px] {{ $package->is_featured ? 'text-indigo-200' : 'text-slate-400' }}">السعر النهائي للباقة</p></div>
            </div>
        </article>
    @endforeach
</div>
