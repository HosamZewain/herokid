<x-front-layout>
    @php
        $storeTitle = setting('unified_store_title', 'متجر القصص والمنتجات');
        $storeSubtitle = setting('unified_store_subtitle', 'كل قصص HeroKid المخصصة وكتب الأنشطة والهدايا في مكان واحد.');
        $activeType = request('type', 'all');
        $hasFilters = request()->hasAny(['type', 'age', 'category', 'gender', 'personalization', 'sort', 'q']);
        $hasAdvancedFilters = request()->hasAny(['age', 'category', 'gender', 'personalization', 'sort', 'q']);
        $canonicalPath = $isStoriesAlias
            ? '/shop?type=stories'
            : ($currentCategory ? '/shop/' . $currentCategory->slug : '/shop');
        $typeUrl = fn (string $type) => route('shop.index', array_merge(
            request()->except(['page', 'category', 'type']),
            ['type' => $type],
        ));
    @endphp

    <x-slot name="pageTitle">متجر القصص والمنتجات</x-slot>
    <x-slot name="pageDescription">Browse personalized children’s stories, activity books, coloring books, mazes, posters, and gifts from HeroKid.</x-slot>
    <x-slot name="canonical">{{ $canonicalPath }}</x-slot>

    @push('schema')
        @php
            $storeSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'name' => $storeTitle,
                'description' => $storeSubtitle,
                'url' => \App\Support\Seo::url($canonicalPath),
                'inLanguage' => 'ar',
                'breadcrumb' => [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => 'الرئيسية', 'item' => \App\Support\Seo::url('/')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => $storeTitle, 'item' => \App\Support\Seo::url('/shop')],
                    ],
                ],
                'mainEntity' => [
                    '@type' => 'ItemList',
                    'numberOfItems' => $items->total(),
                    'itemListElement' => collect($items->items())->values()->map(fn ($item, $index) => [
                        '@type' => 'ListItem',
                        'position' => ($items->firstItem() ?? 1) + $index,
                        'url' => \App\Support\Seo::url($item->detailUrl),
                        'name' => $item->title,
                    ])->all(),
                ],
            ];
        @endphp
        <script type="application/ld+json">
        @json($storeSchema, \App\Support\Seo::jsonFlags())
        </script>
    @endpush

    <div class="min-h-screen bg-slate-50" dir="rtl">
        @unless($isStoriesAlias)
        <section class="relative overflow-hidden bg-gradient-to-bl from-indigo-950 via-violet-950 to-slate-950 text-white">
            <div class="absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(circle, #fff 1px, transparent 1px); background-size: 26px 26px;"></div>
            <div class="absolute -right-32 -top-40 h-96 w-96 rounded-full bg-fuchsia-500/20 blur-3xl"></div>
            <div class="absolute -bottom-40 left-0 h-96 w-96 rounded-full bg-cyan-400/15 blur-3xl"></div>

            <div class="relative mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8 lg:py-20">
                <div class="max-w-4xl text-right">
                    <p class="mb-4 inline-flex rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs font-black text-indigo-100 backdrop-blur">قصص مخصصة • كتب وأنشطة • هدايا</p>
                    <h1 class="text-4xl font-black leading-tight sm:text-5xl lg:text-6xl">{{ $storeTitle }}</h1>
                    <p class="mt-5 max-w-3xl text-base font-medium leading-8 text-slate-300 sm:text-lg">{{ $storeSubtitle }}</p>
                </div>

                <div class="mt-10 hidden gap-3 sm:grid sm:grid-cols-3">
                    <a href="{{ $typeUrl('stories') }}" class="group rounded-3xl border border-pink-300/20 bg-white/10 p-5 text-right backdrop-blur transition hover:-translate-y-1 hover:bg-white/15">
                        <span class="text-3xl" aria-hidden="true">📖</span>
                        <h2 class="mt-3 text-lg font-black">قصص مخصصة</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-300">باسم وصورة الطفل</p>
                    </a>
                    <a href="{{ $typeUrl('activities') }}" class="group rounded-3xl border border-cyan-300/20 bg-white/10 p-5 text-right backdrop-blur transition hover:-translate-y-1 hover:bg-white/15">
                        <span class="text-3xl" aria-hidden="true">🎨</span>
                        <h2 class="mt-3 text-lg font-black">كتب أنشطة وتلوين</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-300">جاهزة أو مخصصة حسب المنتج</p>
                    </a>
                    <a href="{{ $typeUrl('gifts') }}" class="group rounded-3xl border border-amber-300/20 bg-white/10 p-5 text-right backdrop-blur transition hover:-translate-y-1 hover:bg-white/15">
                        <span class="text-3xl" aria-hidden="true">🎁</span>
                        <h2 class="mt-3 text-lg font-black">هدايا ومنتجات</h2>
                        <p class="mt-1 text-sm leading-6 text-slate-300">بوسترات ومنتجات مباشرة</p>
                    </a>
                </div>
            </div>
        </section>
        @endunless

        <div id="catalog-results" class="mx-auto max-w-7xl scroll-mt-24 px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            @if($isStoriesAlias)
                <div class="mb-5 hidden flex-wrap items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-5 py-4 text-sm text-indigo-900 sm:flex"
                    data-stories-mobile-hidden-intro>
                    <p><strong>اختر قصة طفلك مباشرة.</strong> كل قصة مخصصة تحتاج بيانات الطفل وصورتين أو ٣ صور واضحة.</p>
                    <a href="{{ route('shop.index') }}" class="font-black text-indigo-700 hover:text-indigo-900">عرض القصص والمنتجات</a>
                </div>
            @endif

            <div class="mb-6 {{ $isStoriesAlias ? 'hidden sm:grid' : 'grid' }} gap-3 rounded-3xl border border-slate-100 bg-white p-4 text-right shadow-sm sm:grid-cols-2"
                data-store-path-explainer>
                <div class="rounded-2xl bg-pink-50 px-4 py-3">
                    <p class="font-black text-pink-900">قصة مخصصة</p>
                    <p class="mt-1 text-xs leading-5 text-pink-700">تحتاج بيانات الطفل وصورتين أو ٣ صور، ثم نراجع الطلب ونتواصل معك.</p>
                </div>
                <div class="rounded-2xl bg-indigo-50 px-4 py-3">
                    <p class="font-black text-indigo-900">منتج جاهز</p>
                    <p class="mt-1 text-xs leading-5 text-indigo-700">يُشترى مباشرة بدون بيانات الطفل. سعره مستقل عن سعر القصة.</p>
                </div>
            </div>

            <nav aria-label="نوع المنتجات" class="mb-6 flex gap-2 overflow-x-auto pb-2">
                @foreach([
                    'all' => 'الكل',
                    'stories' => 'قصص مخصصة',
                    'products' => 'كل المنتجات',
                    'activities' => 'كتب وأنشطة',
                    'gifts' => 'هدايا ومنتجات',
                ] as $value => $label)
                    <a href="{{ $typeUrl($value) }}"
                       class="shrink-0 rounded-full border px-5 py-2.5 text-sm font-black transition {{ $activeType === $value ? 'border-indigo-600 bg-indigo-600 text-white shadow-lg shadow-indigo-200' : 'border-slate-200 bg-white text-slate-600 hover:border-indigo-200 hover:text-indigo-700' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </nav>

            <div class="mb-8 hidden rounded-3xl border border-slate-100 bg-white shadow-sm lg:block">
                @include('front.shop._filters', [
                    'filterId' => 'desktop-store',
                    'formClasses' => $isStoriesAlias ? 'grid grid-cols-6 gap-3 p-4' : 'grid grid-cols-7 gap-3 p-4',
                ])
            </div>

            <details class="group mb-8 rounded-3xl border border-slate-100 bg-white shadow-sm lg:hidden" data-mobile-store-filters data-expanded="{{ $hasAdvancedFilters ? 'true' : 'false' }}" @if($hasAdvancedFilters) open @endif>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 font-black text-slate-800">
                    <span>فلترة وترتيب النتائج</span>
                    <span class="text-indigo-600 transition group-open:rotate-180">⌄</span>
                </summary>
                @include('front.shop._filters', [
                    'filterId' => 'mobile-store',
                    'formClasses' => 'grid grid-cols-1 gap-3 border-t border-slate-100 p-4 sm:grid-cols-2',
                ])
            </details>

            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-black text-slate-950">اختيارات لطفلك</h2>
                    <p class="mt-1 text-sm font-bold text-slate-500">{{ $items->total() }} نتيجة من القصص والمنتجات المتاحة</p>
                </div>
                <label class="sr-only" for="store-per-page">عدد النتائج في الصفحة</label>
                <select id="store-per-page" onchange="window.location = this.value" class="rounded-xl border-slate-200 bg-white text-sm font-bold text-slate-600">
                    @foreach([12, 20, 24, 30] as $perPage)
                        <option value="{{ route('shop.index', array_merge(request()->query(), ['per_page' => $perPage, 'page' => null])) }}" @selected($items->perPage() === $perPage)>{{ $perPage }} في الصفحة</option>
                    @endforeach
                </select>
            </div>

            @if($items->count())
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach($items as $item)
                        @include('front.shop._catalog-card', ['item' => $item])
                    @endforeach
                </div>
                @if($items->hasPages())
                    <div class="mt-10">{{ $items->links() }}</div>
                @endif

                @unless($isStoriesAlias)
                    <details class="mt-8 rounded-3xl border border-slate-100 bg-white p-5 shadow-sm sm:hidden">
                        <summary class="cursor-pointer list-none font-black text-slate-900">اعرف أنواع المتجر</summary>
                        <div class="mt-4 grid gap-3 border-t border-slate-100 pt-4">
                            <a href="{{ $typeUrl('stories') }}" class="rounded-2xl bg-pink-50 p-4 font-black text-pink-800">📖 قصص مخصصة</a>
                            <a href="{{ $typeUrl('activities') }}" class="rounded-2xl bg-cyan-50 p-4 font-black text-cyan-800">🎨 كتب أنشطة وتلوين</a>
                            <a href="{{ $typeUrl('gifts') }}" class="rounded-2xl bg-amber-50 p-4 font-black text-amber-800">🎁 هدايا ومنتجات</a>
                        </div>
                    </details>
                @endunless
            @else
                <div class="rounded-3xl border border-dashed border-slate-200 bg-white px-6 py-16 text-center shadow-sm">
                    <div class="text-5xl" aria-hidden="true">🧩</div>
                    <h2 class="mt-5 text-xl font-black text-slate-950">لم نجد اختيارات تطابق الفلاتر</h2>
                    <p class="mt-2 text-slate-500">جرّب فئة عمرية أو نوعاً مختلفاً، أو ارجع لعرض المتجر بالكامل.</p>
                    <a href="{{ route('shop.index') }}" class="mt-6 inline-flex rounded-2xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">عرض كل القصص والمنتجات</a>
                </div>
            @endif
        </div>
    </div>
</x-front-layout>
