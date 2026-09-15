{{-- Unified featured catalogue: stories and products in one grid, filtered by tab. --}}
@php
    $tabDefinitions = [
        'all' => ['label' => 'الكل', 'icon' => '✨'],
        'stories' => ['label' => 'قصص مخصصة', 'icon' => '📚'],
        'activities' => ['label' => 'كتب أنشطة', 'icon' => '✏️'],
        'gifts' => ['label' => 'هدايا', 'icon' => '🎁'],
    ];

    $presentSections = $homeCatalogItems->pluck('section')->unique();
    $tabs = collect($tabDefinitions)
        ->filter(fn ($tab, $key) => $key === 'all' || $presentSections->contains($key))
        ->map(fn ($tab, $key) => $tab + [
            'key' => $key,
            'count' => $key === 'all'
                ? $homeCatalogItems->count()
                : $homeCatalogItems->where('section', $key)->count(),
        ]);

    // A lone "الكل" tab alongside one real tab is noise, not navigation.
    $showTabs = $tabs->count() > 2;
@endphp

@if($homeCatalogItems->isNotEmpty())
<section data-home-section="catalog" class="py-20 sm:py-24 relative overflow-hidden" dir="rtl"
    style="background: #ffffff;">
    <div class="absolute inset-0 pointer-events-none opacity-20"
        style="background-image: radial-gradient(circle, #8b5cf6 1px, transparent 1px); background-size: 32px 32px;"></div>
    <div class="absolute -top-10 -left-10 w-52 h-52 rounded-full border-[14px] border-amber-200/50 pointer-events-none"></div>
    <div class="absolute -bottom-8 -right-8 w-40 h-40 rounded-full border-[10px] border-orange-200/50 pointer-events-none"></div>

    <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="text-center max-w-2xl mx-auto mb-10">
            <span class="inline-flex items-center gap-2 bg-amber-100 text-amber-800 font-black text-xs px-4 py-2 rounded-full border border-amber-300 mb-4">🛍️ المتجر</span>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900">{{ setting('home_catalog_section_title', 'كل ما يخص طفلك في مكان واحد') }}</h2>
            <div class="w-24 h-1.5 mx-auto mt-3 mb-4 rounded-full" style="background: linear-gradient(90deg, #f97316, #fbbf24);"></div>
            <p class="text-slate-600 leading-8">{{ setting('home_catalog_section_subtitle', 'قصص مخصصة، كتب أنشطة، وهدايا — كلها تحمل اسم طفلك ووجهه.') }}</p>
        </div>

        @if($showTabs)
            <div class="mb-9 flex flex-wrap justify-center gap-2 sm:gap-3" role="tablist" data-catalog-tabs>
                @foreach($tabs as $tab)
                    <button type="button"
                        role="tab"
                        data-catalog-tab="{{ $tab['key'] }}"
                        aria-selected="{{ $loop->first ? 'true' : 'false' }}"
                        class="inline-flex items-center gap-2 rounded-2xl border-2 px-4 sm:px-6 py-3 text-sm font-black transition
                            {{ $loop->first
                                ? 'border-transparent text-white shadow-lg'
                                : 'border-amber-200 bg-white/80 text-slate-700 hover:border-orange-300 hover:bg-white' }}"
                        @if($loop->first) style="background:linear-gradient(135deg,#f97316,#ec4899);" @endif>
                        <span>{{ $tab['icon'] }}</span>
                        <span>{{ $tab['label'] }}</span>
                        <span class="rounded-full px-2 py-0.5 text-[10px] {{ $loop->first ? 'bg-white/25' : 'bg-slate-100 text-slate-500' }}">{{ arabic_number($tab['count']) }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 sm:gap-5" data-catalog-grid>
            @foreach($homeCatalogItems as $item)
                <div data-catalog-item data-catalog-section="{{ $item->section }}">
                    @include('front.shop._catalog-card', ['item' => $item])
                </div>
            @endforeach
        </div>

        <p class="mt-8 hidden text-center font-bold text-slate-500" data-catalog-empty>
            لا توجد عناصر في هذا القسم حاليًا.
        </p>

        <div class="text-center mt-10">
            <a href="{{ route('shop.index') }}"
                class="inline-flex items-center gap-3 text-white font-black py-4 px-10 rounded-2xl shadow-xl transition hover:-translate-y-1 hover:shadow-2xl"
                style="background: linear-gradient(135deg, #f97316, #ec4899); box-shadow: 0 8px 25px rgba(249,115,22,.35);">
                <span>استعرض المتجر الكامل</span>
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
        </div>
    </div>
</section>

@if($showTabs)
@push('scripts')
<script>
    (function () {
        var root = document.querySelector('[data-catalog-tabs]');
        if (!root) return;

        var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-catalog-tab]'));
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-catalog-item]'));
        var empty = document.querySelector('[data-catalog-empty]');
        var activeStyle = 'linear-gradient(135deg,#f97316,#ec4899)';

        function select(key) {
            tabs.forEach(function (tab) {
                var on = tab.getAttribute('data-catalog-tab') === key;
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
                tab.style.background = on ? activeStyle : '';
                tab.className = tab.className
                    .replace(/border-transparent text-white shadow-lg/, '')
                    .replace(/border-amber-200 bg-white\/80 text-slate-700 hover:border-orange-300 hover:bg-white/, '')
                    .trim() + ' ' + (on
                        ? 'border-transparent text-white shadow-lg'
                        : 'border-amber-200 bg-white/80 text-slate-700 hover:border-orange-300 hover:bg-white');

                var badge = tab.querySelector('span:last-child');
                if (badge) {
                    badge.className = 'rounded-full px-2 py-0.5 text-[10px] ' + (on ? 'bg-white/25' : 'bg-slate-100 text-slate-500');
                }
            });

            var shown = 0;
            items.forEach(function (item) {
                var on = key === 'all' || item.getAttribute('data-catalog-section') === key;
                item.hidden = !on;
                if (on) shown++;
            });

            if (empty) empty.classList.toggle('hidden', shown > 0);
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                select(tab.getAttribute('data-catalog-tab'));
            });
        });
    })();
</script>
@endpush
@endif
@endif
