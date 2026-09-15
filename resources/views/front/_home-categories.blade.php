{{-- Product family strip: communicates the full catalogue range directly under the hero. --}}
@php
    $accents = [
        ['from' => '#f97316', 'to' => '#ec4899', 'tint' => 'bg-orange-50',  'ring' => 'border-orange-200',  'text' => 'text-orange-700'],
        ['from' => '#8b5cf6', 'to' => '#6366f1', 'tint' => 'bg-violet-50',  'ring' => 'border-violet-200',  'text' => 'text-violet-700'],
        ['from' => '#10b981', 'to' => '#06b6d4', 'tint' => 'bg-emerald-50', 'ring' => 'border-emerald-200', 'text' => 'text-emerald-700'],
        ['from' => '#f59e0b', 'to' => '#f97316', 'tint' => 'bg-amber-50',   'ring' => 'border-amber-200',   'text' => 'text-amber-700'],
        ['from' => '#3b82f6', 'to' => '#0ea5e9', 'tint' => 'bg-sky-50',     'ring' => 'border-sky-200',     'text' => 'text-sky-700'],
    ];

    $slugIcons = [
        'activities-learning' => '✏️',
        'ready-stories' => '📗',
        'personalized-gifts' => '🎁',
    ];

    $tiles = collect();

    if ($homeStoryCount > 0) {
        $tiles->push([
            'title' => 'قصص مخصصة',
            'subtitle' => arabic_number($homeStoryCount).' قصة بوجه طفلك واسمه',
            'icon' => '📚',
            'url' => route('shop.index', ['type' => 'stories']),
            'image' => null,
        ]);
    }

    foreach ($homeCategories as $category) {
        $tiles->push([
            'title' => $category->name_ar,
            'subtitle' => $category->short_description_ar
                ?: arabic_number($category->active_products_count).' منتج متاح',
            'icon' => $category->icon ?: ($slugIcons[$category->slug] ?? '🧸'),
            'url' => route('shop.category', $category),
            'image' => $category->cover_url,
        ]);
    }

    if ($packages->isNotEmpty()) {
        $tiles->push([
            'title' => 'الباقات',
            'subtitle' => 'اجمع أكثر من منتج بسعر أوفر',
            'icon' => '💎',
            'url' => route('packages'),
            'image' => null,
        ]);
    }

    $tiles = $tiles->take(5)->values();
    // Keep small tile counts centred instead of stranded against one edge of a wide grid.
    [$gridCols, $gridWidth] = match ($tiles->count()) {
        1 => ['grid-cols-1', 'max-w-xs mx-auto'],
        2 => ['grid-cols-2', 'max-w-xl mx-auto'],
        3 => ['grid-cols-2 sm:grid-cols-3', 'max-w-3xl mx-auto'],
        4 => ['grid-cols-2 lg:grid-cols-4', ''],
        default => ['grid-cols-2 sm:grid-cols-3 lg:grid-cols-5', ''],
    };
@endphp

@if($tiles->isNotEmpty())
<section data-home-section="categories" class="py-14 sm:py-16 bg-white relative overflow-hidden" dir="rtl">
    <div class="absolute inset-0 pointer-events-none opacity-[0.07]"
        style="background-image: radial-gradient(circle, #6366f1 1px, transparent 1px); background-size: 30px 30px;"></div>

    <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-2xl mx-auto mb-9">
            <span class="inline-flex items-center gap-2 bg-slate-100 text-slate-700 font-black text-xs px-4 py-2 rounded-full border border-slate-200 mb-4">🛍️ كل منتجات طفلك</span>
            <h2 class="text-3xl sm:text-4xl font-extrabold text-slate-900">اختر ما يناسب طفلك</h2>
            <p class="mt-3 text-slate-500 leading-8">هوية واحدة لطفلك، تستخدمها في كل ما تطلبه له.</p>
        </div>

        <div class="grid {{ $gridCols }} {{ $gridWidth }} gap-3 sm:gap-4">
            @foreach($tiles as $tile)
                @php $accent = $accents[$loop->index % count($accents)]; @endphp
                <a href="{{ $tile['url'] }}"
                    class="group relative flex flex-col items-center text-center rounded-[1.75rem] border {{ $accent['ring'] }} bg-white p-5 shadow-sm transition duration-300 hover:-translate-y-1.5 hover:shadow-xl">
                    <span class="absolute inset-x-5 top-0 h-1 rounded-full"
                        style="background:linear-gradient(90deg,{{ $accent['from'] }},{{ $accent['to'] }});"></span>

                    @if($tile['image'])
                        <span class="mb-3 mt-2 block h-16 w-16 overflow-hidden rounded-2xl shadow-sm">
                            <img src="{{ $tile['image'] }}" alt="{{ $tile['title'] }}"
                                class="h-full w-full object-cover transition duration-500 group-hover:scale-110" loading="lazy">
                        </span>
                    @else
                        <span class="mb-3 mt-2 flex h-16 w-16 items-center justify-center rounded-2xl text-3xl shadow-sm {{ $accent['tint'] }}">{{ $tile['icon'] }}</span>
                    @endif

                    <h3 class="text-sm sm:text-base font-black text-slate-900 leading-snug">{{ $tile['title'] }}</h3>
                    <p class="mt-1.5 text-[11px] sm:text-xs font-bold text-slate-400 leading-relaxed line-clamp-2">{{ $tile['subtitle'] }}</p>

                    <span class="mt-3 inline-flex items-center gap-1 text-[11px] font-black {{ $accent['text'] }} opacity-0 transition group-hover:opacity-100">
                        تصفح
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
