<x-front-layout>

@push('styles')
<style>
    .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    .scrollbar-hide::-webkit-scrollbar { display: none; }
</style>
@endpush

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">مكتبة قصص الأطفال المخصصة — اجعل طفلك بطل القصة بوجهه الحقيقي</x-slot>
<x-slot name="pageDescription">استعرض مكتبة HeroKid من قصص الأطفال المخصصة المطبوعة. قصص بوجه طفلك واسمه للأعمار ٢–١٠ سنوات بالعربية والإنجليزية. اختر القصة وخصّصها الآن.</x-slot>

@push('schema')
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "CollectionPage",
  "name": "مكتبة قصص الأطفال المخصصة — HeroKid",
  "description": "مجموعة قصص أطفال مخصصة مطبوعة بوجه طفلك واسمه من HeroKid",
  "url": "{{ route('stories.index') }}",
  "inLanguage": ["ar", "en"],
  "publisher": {
    "@@type": "Organization",
    "name": "HeroKid",
    "url": "{{ config('app.url') }}"
  },
  "breadcrumb": {
    "@@type": "BreadcrumbList",
    "itemListElement": [
      { "@@type": "ListItem", "position": 1, "name": "الرئيسية", "item": "{{ route('home') }}" },
      { "@@type": "ListItem", "position": 2, "name": "مكتبة القصص", "item": "{{ route('stories.index') }}" }
    ]
  }
}
</script>
@endpush

    @php
        $hasFilter = request()->hasAny(['gender', 'lang', 'age', 'category']);
        $fallbacks = [
            'photo-1446776811953-b23d57bd21aa',
            'photo-1448375240586-882707db888b',
            'photo-1490750967868-88df5691cc4a',
            'photo-1575361204480-aadea25e6e68',
            'photo-1518709268805-4e9042af9f23',
            'photo-1581091226825-a6a2a5aee158',
            'photo-1556909114-f6e7ad7d3136',
            'photo-1548199973-03cce0bbc87b',
        ];
    @endphp

    <div class="bg-slate-50 min-h-screen flex flex-col">

        {{-- ===== HERO SECTION ===== --}}
        <div class="relative bg-gradient-to-bl from-slate-900 via-indigo-950 to-purple-950 overflow-hidden" dir="rtl">

            {{-- Background layers --}}
            <div class="absolute inset-0 pointer-events-none overflow-hidden">
                {{-- Blobs --}}
                <div class="absolute -top-40 -right-40 w-[700px] h-[700px] bg-indigo-600/10 rounded-full blur-3xl"></div>
                <div class="absolute -bottom-40 -left-40 w-[500px] h-[500px] bg-purple-700/15 rounded-full blur-3xl"></div>
                {{-- Dot grid --}}
                <div class="absolute inset-0 opacity-[0.04]" style="background-image: radial-gradient(circle, #818cf8 1px, transparent 1px); background-size: 28px 28px;"></div>
                {{-- Diagonal light streak --}}
                <div class="absolute top-0 left-1/3 w-px h-full bg-gradient-to-b from-transparent via-indigo-400/10 to-transparent transform -skew-x-12"></div>
            </div>

            <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-14 pb-0">

                {{-- Two-column grid --}}
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">

                    {{-- === TEXT SIDE === --}}
                    <div class="text-right py-8 lg:py-16">

                        {{-- Eyebrow badge --}}
                        <div class="inline-flex items-center gap-2.5 bg-indigo-500/15 border border-indigo-400/25 text-indigo-300 text-[11px] font-black px-4 py-2 rounded-full mb-6 backdrop-blur-sm tracking-wider">
                            <span class="w-1.5 h-1.5 bg-indigo-400 rounded-full animate-pulse"></span>
                            مكتبة HeroKid المخصصة
                        </div>

                        {{-- Headline --}}
                        <h1 class="text-4xl md:text-5xl xl:text-6xl font-black text-white leading-[1.15] mb-5">
                            اختر القصة<br>
                            <span class="relative inline-block">
                                <span class="text-transparent bg-clip-text bg-gradient-to-l from-purple-300 via-indigo-300 to-blue-300">التي تجعل طفلك</span>
                            </span><br>
                            البطل الحقيقي ✨
                        </h1>

                        {{-- Sub-text built from real data --}}
                        <p class="text-slate-400 text-base md:text-lg font-medium leading-relaxed mb-8 max-w-xl">
                            @if($stories->total() > 0)
                                <span class="text-white font-black">{{ $stories->total() }} قصة</span> مخصصة مطبوعة احترافياً —
                            @endif
                            @if($categories->isNotEmpty())
                                في <span class="text-indigo-300 font-bold">{{ $categories->count() }} تصنيف</span>،
                            @endif
                            @if($ageRanges->isNotEmpty())
                                للأعمار
                                @foreach($ageRanges->take(2) as $r)
                                    <span class="text-amber-300 font-bold">{{ $r }}</span>{{ !$loop->last ? ' و' : '' }}
                                @endforeach
                                سنة وما فوق،
                            @endif
                            للأولاد والبنات بالعربية والإنجليزية.
                        </p>

                        {{-- Stats row --}}
                        <div class="flex flex-wrap gap-3 justify-end mb-8">
                            <div class="flex flex-col items-center bg-white/6 border border-white/10 rounded-2xl px-5 py-4 backdrop-blur-sm min-w-[80px] hover:bg-white/10 transition">
                                <span class="text-2xl font-black text-white">{{ $stories->total() ?: '∞' }}</span>
                                <span class="text-slate-500 text-[9px] font-black tracking-[0.15em] uppercase mt-0.5">قصة</span>
                            </div>
                            @if($categories->isNotEmpty())
                            <div class="flex flex-col items-center bg-white/6 border border-white/10 rounded-2xl px-5 py-4 backdrop-blur-sm min-w-[80px] hover:bg-white/10 transition">
                                <span class="text-2xl font-black text-white">{{ $categories->count() }}</span>
                                <span class="text-slate-500 text-[9px] font-black tracking-[0.15em] uppercase mt-0.5">تصنيف</span>
                            </div>
                            @endif
                            @if($ageRanges->isNotEmpty())
                            <div class="flex flex-col items-center bg-white/6 border border-white/10 rounded-2xl px-5 py-4 backdrop-blur-sm min-w-[80px] hover:bg-white/10 transition">
                                <span class="text-2xl font-black text-white">{{ $ageRanges->count() }}</span>
                                <span class="text-slate-500 text-[9px] font-black tracking-[0.15em] uppercase mt-0.5">فئة عمرية</span>
                            </div>
                            @endif
                            <div class="flex flex-col items-center bg-white/6 border border-white/10 rounded-2xl px-5 py-4 backdrop-blur-sm min-w-[80px] hover:bg-white/10 transition">
                                <span class="text-2xl font-black text-white">2</span>
                                <span class="text-slate-500 text-[9px] font-black tracking-[0.15em] uppercase mt-0.5">لغة</span>
                            </div>
                        </div>

                    </div>

                    {{-- === VISUAL SIDE === --}}
                    <div class="hidden lg:flex items-end justify-center relative h-80 xl:h-96 pb-0">

                        {{-- Main big card mock --}}
                        <div class="absolute bottom-0 right-16 w-52 bg-white rounded-[1.5rem] shadow-2xl overflow-hidden transform rotate-3 hover:rotate-1 transition-transform duration-700 z-20">
                            <div class="aspect-square bg-gradient-to-br from-indigo-100 to-purple-100 flex items-center justify-center overflow-hidden">
                                @php $heroStory = $stories->first(); @endphp
                                @if($heroStory && $heroStory->cover_image)
                                    <img src="{{ $heroStory->cover_url }}" alt="{{ $heroStory->title }}" class="w-full h-full object-cover">
                                @else
                                    <img src="https://images.unsplash.com/{{ $fallbacks[0] }}?w=400&auto=format&fit=crop&q=85" alt="قصة" class="w-full h-full object-cover">
                                @endif
                            </div>
                            <div class="p-4 text-right">
                                @if($heroStory)
                                    <p class="text-[10px] font-black text-indigo-500 mb-1">{{ $heroStory->categories->first()->name ?? 'قصة مخصصة' }}</p>
                                    <h4 class="text-sm font-black text-slate-900 leading-snug line-clamp-1">{{ $heroStory->title }}</h4>
                                    <div class="flex items-center justify-between mt-3 pt-2 border-t border-slate-50">
                                        <span class="text-xs font-black text-indigo-600">{{ number_format($heroStory->price, 0) }} <small class="text-slate-400">ج.م</small></span>
                                        <span class="text-[9px] bg-indigo-50 text-indigo-600 font-black px-2 py-1 rounded-lg">{{ $heroStory->age_range }} سنة</span>
                                    </div>
                                @else
                                    <p class="text-[10px] font-black text-indigo-500 mb-1">مغامرات</p>
                                    <h4 class="text-sm font-black text-slate-900 leading-snug">قصة مخصصة بوجه طفلك</h4>
                                @endif
                            </div>
                        </div>

                        {{-- Secondary card mock (blurred, behind) --}}
                        <div class="absolute bottom-8 right-52 w-44 bg-white/80 backdrop-blur rounded-[1.5rem] shadow-xl overflow-hidden transform -rotate-6 z-10 opacity-70">
                            <div class="aspect-square bg-gradient-to-br from-amber-100 to-rose-100 flex items-center justify-center overflow-hidden">
                                <img src="https://images.unsplash.com/{{ $fallbacks[2] }}?w=300&auto=format&fit=crop&q=80" alt="قصة" class="w-full h-full object-cover">
                            </div>
                            <div class="p-3 text-right">
                                <p class="text-[9px] font-black text-amber-500 mb-0.5">حيوانات</p>
                                <h4 class="text-xs font-black text-slate-800 leading-snug line-clamp-1">قصة بطل صغير</h4>
                            </div>
                        </div>

                        {{-- Third card (behind left) --}}
                        <div class="absolute bottom-4 left-8 w-40 bg-white/60 backdrop-blur rounded-[1.5rem] shadow-lg overflow-hidden transform rotate-12 z-10 opacity-50">
                            <div class="aspect-square bg-gradient-to-br from-emerald-100 to-teal-100 flex items-center justify-center overflow-hidden">
                                <img src="https://images.unsplash.com/{{ $fallbacks[4] }}?w=300&auto=format&fit=crop&q=80" alt="قصة" class="w-full h-full object-cover">
                            </div>
                        </div>

                        {{-- Floating badges --}}
                        <div class="absolute top-6 right-4 bg-white rounded-2xl px-4 py-2.5 shadow-2xl flex items-center gap-2 text-xs font-black text-slate-800 transform -rotate-3 z-30 hover:rotate-0 transition-transform duration-500">
                            <span class="text-base">⭐</span> قصة مخصصة
                        </div>
                        <div class="absolute top-16 left-12 bg-indigo-600 text-white rounded-2xl px-3.5 py-2 shadow-xl flex items-center gap-2 text-xs font-black transform rotate-6 z-30 hover:rotate-0 transition-transform duration-500">
                            <span class="text-base">🚀</span> طفلك البطل
                        </div>
                        @if($categories->isNotEmpty())
                        <div class="absolute top-40 left-4 bg-amber-400 text-slate-900 rounded-2xl px-3.5 py-2 shadow-xl text-[10px] font-black transform -rotate-3 z-30">
                            📚 {{ $categories->first()->name }}
                        </div>
                        @endif

                        {{-- Decorative dots --}}
                        <div class="absolute top-8 left-1/2 w-2.5 h-2.5 bg-indigo-400/50 rounded-full animate-bounce" style="animation-delay:0.1s"></div>
                        <div class="absolute top-1/3 right-2 w-2 h-2 bg-purple-400/50 rounded-full animate-bounce" style="animation-delay:0.4s"></div>
                        <div class="absolute bottom-1/3 left-1/3 w-3 h-3 bg-amber-400/40 rounded-full animate-bounce" style="animation-delay:0.7s"></div>
                    </div>

                </div>

                {{-- === CATEGORY QUICK-FILTER STRIP === --}}
                @if($categories->isNotEmpty())
                <div class="border-t border-white/8 pt-5 pb-6 mt-2">
                    <div class="flex items-center gap-3 overflow-x-auto pb-1 justify-end" style="scrollbar-width:none">
                        <span class="text-slate-600 text-[10px] font-black tracking-[0.15em] uppercase shrink-0">تصفح حسب:</span>
                        <a href="{{ route('stories.index') }}"
                           class="shrink-0 px-5 py-2.5 rounded-full text-[11px] font-black transition-all duration-200 {{ !request('category') && !$hasFilter ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-900/40 scale-105' : 'bg-white/8 text-slate-400 border border-white/10 hover:bg-white/15 hover:text-white' }}">
                            الكل <span class="opacity-60">({{ \App\Models\Story::where('active', true)->count() }})</span>
                        </a>
                        @foreach($categories as $cat)
                        <a href="{{ route('stories.index', ['category' => $cat->slug]) }}"
                           class="shrink-0 px-5 py-2.5 rounded-full text-[11px] font-black transition-all duration-200 {{ request('category') == $cat->slug ? 'bg-indigo-500 text-white shadow-lg shadow-indigo-900/40 scale-105' : 'bg-white/8 text-slate-400 border border-white/10 hover:bg-white/15 hover:text-white' }}">
                            {{ $cat->name }} <span class="opacity-50">({{ $cat->stories_count }})</span>
                        </a>
                        @endforeach
                    </div>
                </div>
                @endif

            </div>

            {{-- Wave divider --}}
            <div class="relative overflow-hidden leading-none" style="height:52px">
                <svg viewBox="0 0 1440 52" fill="none" xmlns="http://www.w3.org/2000/svg" class="absolute bottom-0 w-full h-full" preserveAspectRatio="none">
                    <path d="M0 52L80 46C160 40 320 28 480 22C640 16 800 16 960 20C1120 24 1280 36 1360 42L1440 48V52H0Z" fill="#f8fafc"/>
                </svg>
            </div>

        </div>
        {{-- END HERO --}}

        {{-- ===== MAIN LAYOUT ===== --}}
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 w-full flex-grow" dir="rtl">
            <div class="flex gap-8 items-start">

                {{-- ===== SIDEBAR ===== --}}
                <aside class="hidden lg:flex flex-col gap-4 w-64 shrink-0 sticky top-6">

                    {{-- Clear filters --}}
                    @if($hasFilter)
                    <a href="{{ route('stories.index') }}"
                       class="flex items-center justify-center gap-2 w-full bg-red-50 border border-red-100 text-red-500 font-black text-xs py-3 px-4 rounded-2xl hover:bg-red-100 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                        مسح الفلاتر
                    </a>
                    @endif

                    {{-- Filter card --}}
                    <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden">

                        {{-- Gender --}}
                        <div class="p-5 border-b border-slate-50">
                            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3">👦 البطل</p>
                            <div class="grid grid-cols-3 gap-2">
                                <a href="{{ route('stories.index', request()->except('gender')) }}"
                                   class="flex flex-col items-center gap-1 py-3 rounded-xl border text-xs font-black transition
                                          {{ !request('gender') ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-400 hover:border-slate-300' }}">
                                    <span class="text-base">👫</span>
                                    <span>الكل</span>
                                </a>
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['gender' => 'boy'])) }}"
                                   class="flex flex-col items-center gap-1 py-3 rounded-xl border text-xs font-black transition
                                          {{ request('gender') == 'boy' ? 'bg-blue-50 border-blue-300 text-blue-700' : 'border-slate-200 text-slate-400 hover:border-blue-200 hover:text-blue-500' }}">
                                    <span class="text-base">👦</span>
                                    <span>ولد</span>
                                </a>
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['gender' => 'girl'])) }}"
                                   class="flex flex-col items-center gap-1 py-3 rounded-xl border text-xs font-black transition
                                          {{ request('gender') == 'girl' ? 'bg-pink-50 border-pink-300 text-pink-700' : 'border-slate-200 text-slate-400 hover:border-pink-200 hover:text-pink-500' }}">
                                    <span class="text-base">👧</span>
                                    <span>بنت</span>
                                </a>
                            </div>
                        </div>

                        {{-- Age --}}
                        <div class="p-5 border-b border-slate-50">
                            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3">🎂 العمر</p>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('stories.index', request()->except('age')) }}"
                                   class="px-3 py-1.5 rounded-xl text-xs font-black border transition
                                          {{ !request('age') ? 'bg-amber-500 border-amber-500 text-white' : 'border-slate-200 text-slate-500 hover:border-amber-300 hover:text-amber-600' }}">
                                    الكل
                                </a>
                                @foreach($ageRanges as $range)
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['age' => $range])) }}"
                                   class="px-3 py-1.5 rounded-xl text-xs font-black border transition
                                          {{ request('age') == $range ? 'bg-amber-500 border-amber-500 text-white' : 'border-slate-200 text-slate-500 hover:border-amber-300 hover:text-amber-600' }}">
                                    {{ $range }}
                                </a>
                                @endforeach
                            </div>
                        </div>

                        {{-- Categories --}}
                        <div class="p-5 border-b border-slate-50">
                            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3">📚 التصنيف</p>
                            <div class="space-y-1">
                                <a href="{{ route('stories.index', request()->except('category')) }}"
                                   class="flex items-center justify-between px-3 py-2 rounded-xl text-sm font-bold transition
                                          {{ !request('category') ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                                    <span>الكل</span>
                                    <span class="text-[11px] {{ !request('category') ? 'text-white/60' : 'text-slate-300' }}">{{ \App\Models\Story::where('active', true)->count() }}</span>
                                </a>
                                @foreach($categories as $cat)
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['category' => $cat->slug])) }}"
                                   class="flex items-center justify-between px-3 py-2 rounded-xl text-sm font-bold transition
                                          {{ request('category') == $cat->slug ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                                    <span>{{ $cat->name }}</span>
                                    <span class="text-[11px] {{ request('category') == $cat->slug ? 'text-white/60' : 'text-slate-300' }}">{{ $cat->stories_count }}</span>
                                </a>
                                @endforeach
                            </div>
                        </div>

                        {{-- Language --}}
                        <div class="p-5">
                            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-3">🌐 اللغة</p>
                            <div class="grid grid-cols-3 gap-2">
                                <a href="{{ route('stories.index', request()->except('lang')) }}"
                                   class="text-center py-2.5 rounded-xl border text-xs font-black transition
                                          {{ !request('lang') ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-400 hover:border-slate-300' }}">
                                    الكل
                                </a>
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['lang' => 'ar'])) }}"
                                   class="text-center py-2.5 rounded-xl border text-xs font-black transition
                                          {{ request('lang') == 'ar' ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-400 hover:border-slate-300' }}">
                                    🇸🇦 عربي
                                </a>
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['lang' => 'en'])) }}"
                                   class="text-center py-2.5 rounded-xl border text-xs font-black transition
                                          {{ request('lang') == 'en' ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-400 hover:border-slate-300' }}">
                                    🇬🇧 EN
                                </a>
                            </div>
                        </div>

                    </div>
                </aside>

                {{-- ===== CONTENT ===== --}}
                <div class="flex-1 min-w-0">

                    {{-- Header row --}}
                    <div class="flex items-center justify-between mb-6 gap-4" dir="rtl">
                        <div>
                            <h2 class="text-xl font-black text-slate-900">المجموعة المتاحة</h2>
                            <p class="text-slate-400 text-xs font-bold mt-0.5">{{ $stories->total() }} قصة مخصصة</p>
                        </div>
                        <div class="flex items-center gap-3 shrink-0">
                            {{-- Mobile filter button --}}
                            <button onclick="document.getElementById('mobile-filters').classList.toggle('hidden')"
                                    class="lg:hidden flex items-center gap-2 bg-white border border-slate-200 text-slate-600 font-black text-xs px-4 py-2.5 rounded-xl shadow-sm hover:border-indigo-300 transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M7 12h10M11 20h2"/></svg>
                                فلترة {{ $hasFilter ? '•' : '' }}
                            </button>
                            {{-- Per-page --}}
                            <select onchange="window.location = this.value"
                                    class="bg-white border border-slate-200 rounded-xl px-3 py-2.5 text-xs font-black text-slate-600 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-300 transition shadow-sm">
                                @foreach([12, 20, 30] as $n)
                                    <option value="{{ route('stories.index', array_merge(request()->query(), ['per_page' => $n])) }}"
                                            {{ request('per_page', 12) == $n ? 'selected' : '' }}>{{ $n }} قصة</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Mobile filters panel --}}
                    <div id="mobile-filters" class="hidden lg:hidden bg-white rounded-2xl border border-slate-100 shadow-sm p-4 mb-6 space-y-4" dir="rtl">
                        {{-- Category --}}
                        <div>
                            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">📚 التصنيف</p>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('stories.index', request()->except('category')) }}"
                                   class="px-3 py-1.5 rounded-xl text-xs font-black border transition {{ !request('category') ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-slate-200 text-slate-500' }}">الكل</a>
                                @foreach($categories as $cat)
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['category' => $cat->slug])) }}"
                                   class="px-3 py-1.5 rounded-xl text-xs font-black border transition {{ request('category') == $cat->slug ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-slate-200 text-slate-500' }}">{{ $cat->name }}</a>
                                @endforeach
                            </div>
                        </div>
                        {{-- Age --}}
                        <div>
                            <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">🎂 العمر</p>
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('stories.index', request()->except('age')) }}"
                                   class="px-3 py-1.5 rounded-xl text-xs font-black border transition {{ !request('age') ? 'bg-amber-500 border-amber-500 text-white' : 'border-slate-200 text-slate-500' }}">الكل</a>
                                @foreach($ageRanges as $range)
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['age' => $range])) }}"
                                   class="px-3 py-1.5 rounded-xl text-xs font-black border transition {{ request('age') == $range ? 'bg-amber-500 border-amber-500 text-white' : 'border-slate-200 text-slate-500' }}">{{ $range }}</a>
                                @endforeach
                            </div>
                        </div>
                        {{-- Gender & Language --}}
                        <div class="flex gap-4">
                            <div class="flex-1">
                                <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">👦 البطل</p>
                                <div class="flex gap-2">
                                    <a href="{{ route('stories.index', request()->except('gender')) }}" class="flex-1 text-center py-2 rounded-xl text-xs font-black border transition {{ !request('gender') ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-500' }}">الكل</a>
                                    <a href="{{ route('stories.index', array_merge(request()->query(), ['gender' => 'boy'])) }}" class="flex-1 text-center py-2 rounded-xl text-xs font-black border transition {{ request('gender') == 'boy' ? 'bg-blue-50 border-blue-300 text-blue-700' : 'border-slate-200 text-slate-500' }}">👦</a>
                                    <a href="{{ route('stories.index', array_merge(request()->query(), ['gender' => 'girl'])) }}" class="flex-1 text-center py-2 rounded-xl text-xs font-black border transition {{ request('gender') == 'girl' ? 'bg-pink-50 border-pink-300 text-pink-700' : 'border-slate-200 text-slate-500' }}">👧</a>
                                </div>
                            </div>
                            <div class="flex-1">
                                <p class="text-[11px] font-black text-slate-400 uppercase tracking-widest mb-2">🌐 اللغة</p>
                                <div class="flex gap-2">
                                    <a href="{{ route('stories.index', request()->except('lang')) }}" class="flex-1 text-center py-2 rounded-xl text-xs font-black border transition {{ !request('lang') ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-500' }}">الكل</a>
                                    <a href="{{ route('stories.index', array_merge(request()->query(), ['lang' => 'ar'])) }}" class="flex-1 text-center py-2 rounded-xl text-xs font-black border transition {{ request('lang') == 'ar' ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-500' }}">🇸🇦</a>
                                    <a href="{{ route('stories.index', array_merge(request()->query(), ['lang' => 'en'])) }}" class="flex-1 text-center py-2 rounded-xl text-xs font-black border transition {{ request('lang') == 'en' ? 'bg-slate-800 border-slate-800 text-white' : 'border-slate-200 text-slate-500' }}">🇬🇧</a>
                                </div>
                            </div>
                        </div>
                        @if($hasFilter)
                        <a href="{{ route('stories.index') }}" class="block text-center py-2.5 rounded-xl text-xs font-black bg-red-50 text-red-500 border border-red-100">✕ مسح الفلاتر</a>
                        @endif
                    </div>

                    {{-- Active filter chips --}}
                    @if($hasFilter)
                    <div class="flex flex-wrap gap-2 mb-5" dir="rtl">
                        @if(request('category'))
                            <span class="bg-indigo-50 border border-indigo-100 text-indigo-700 px-3 py-1 rounded-full text-xs font-black">📚 {{ $categories->firstWhere('slug', request('category'))?->name }}</span>
                        @endif
                        @if(request('age'))
                            <span class="bg-amber-50 border border-amber-100 text-amber-700 px-3 py-1 rounded-full text-xs font-black">🎂 {{ request('age') }}</span>
                        @endif
                        @if(request('gender'))
                            <span class="bg-blue-50 border border-blue-100 text-blue-700 px-3 py-1 rounded-full text-xs font-black">{{ request('gender') == 'boy' ? '👦 ولد' : '👧 بنت' }}</span>
                        @endif
                        @if(request('lang'))
                            <span class="bg-slate-100 border border-slate-200 text-slate-600 px-3 py-1 rounded-full text-xs font-black">🌐 {{ request('lang') == 'ar' ? 'عربي' : 'English' }}</span>
                        @endif
                    </div>
                    @endif

                    {{-- Empty state --}}
                    @if($stories->isEmpty())
                    <div class="bg-white rounded-2xl py-24 text-center border border-dashed border-slate-200 shadow-sm px-6">
                        <div class="text-6xl mb-6">🕵️‍♀️</div>
                        <h3 class="text-xl font-black text-slate-900 mb-2">لم نجد قصص تطابق بحثك</h3>
                        <p class="text-slate-400 text-sm mb-6">جرب فلاتر مختلفة أو تصفح كل المجموعة</p>
                        <a href="{{ route('stories.index') }}" class="inline-block bg-indigo-600 text-white font-black px-8 py-3 rounded-xl shadow-lg shadow-indigo-200 hover:bg-indigo-700 transition">عرض جميع القصص</a>
                    </div>

                    @else

                    {{-- STORY CARDS --}}
                    @php
                        $cardAccents = [
                            ['bar' => 'from-orange-400 to-yellow-400',   'badge' => 'bg-orange-50 text-orange-700 border-orange-200',   'price' => 'text-orange-600',  'shadow' => 'hover:shadow-orange-200/60'],
                            ['bar' => 'from-pink-400 to-rose-500',       'badge' => 'bg-pink-50 text-pink-700 border-pink-200',         'price' => 'text-pink-600',    'shadow' => 'hover:shadow-pink-200/60'],
                            ['bar' => 'from-violet-500 to-indigo-500',   'badge' => 'bg-violet-50 text-violet-700 border-violet-200',   'price' => 'text-violet-600',  'shadow' => 'hover:shadow-violet-200/60'],
                            ['bar' => 'from-emerald-400 to-teal-500',    'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200','price' => 'text-emerald-600', 'shadow' => 'hover:shadow-emerald-200/60'],
                            ['bar' => 'from-sky-400 to-blue-500',        'badge' => 'bg-sky-50 text-sky-700 border-sky-200',            'price' => 'text-sky-600',     'shadow' => 'hover:shadow-sky-200/60'],
                            ['bar' => 'from-fuchsia-400 to-pink-500',    'badge' => 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200','price' => 'text-fuchsia-600', 'shadow' => 'hover:shadow-fuchsia-200/60'],
                            ['bar' => 'from-amber-400 to-orange-500',    'badge' => 'bg-amber-50 text-amber-700 border-amber-200',      'price' => 'text-amber-600',   'shadow' => 'hover:shadow-amber-200/60'],
                            ['bar' => 'from-cyan-400 to-sky-500',        'badge' => 'bg-cyan-50 text-cyan-700 border-sky-200',          'price' => 'text-sky-600',     'shadow' => 'hover:shadow-cyan-200/60'],
                        ];
                    @endphp
                    <div class="grid grid-cols-2 xl:grid-cols-3 gap-3 sm:gap-5 pb-12">
                        @foreach($stories as $idx => $story)
                            @php $accent = $cardAccents[$loop->index % 8]; @endphp
                            <div class="group bg-white rounded-[1.75rem] overflow-hidden hover:shadow-2xl {{ $accent['shadow'] }} transition-all duration-500 hover:-translate-y-2 flex flex-col border border-orange-100/80">
                                <div class="h-1.5 w-full bg-gradient-to-r {{ $accent['bar'] }}"></div>
                                <a href="{{ route('stories.show', $story->slug) }}" class="aspect-[4/3] overflow-hidden relative bg-amber-50 block">
                                    @if($story->cover_image)
                                        <img src="{{ $story->cover_url }}" alt="{{ $story->title }}" class="w-full h-full object-cover transition duration-700 group-hover:scale-110" loading="lazy">
                                    @else
                                        <img src="https://images.unsplash.com/{{ $fallbacks[$idx % count($fallbacks)] }}?w=500&auto=format&fit=crop&q=80" alt="{{ $story->title }}" class="w-full h-full object-cover transition duration-700 group-hover:scale-110" loading="lazy">
                                    @endif
                                    <div class="absolute top-3 right-3">
                                        <span class="bg-white/92 backdrop-blur-sm text-[10px] font-black px-2.5 py-1 rounded-full shadow border {{ $accent['badge'] }}">{{ $story->age_range }}</span>
                                    </div>
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition duration-300 flex items-end pb-4 justify-center">
                                        <span class="text-white text-xs font-black bg-white/20 backdrop-blur-sm border border-white/30 px-4 py-1.5 rounded-full">🔍 معاينة سريعة</span>
                                    </div>
                                </a>
                                <div class="p-3 sm:p-4 flex flex-col flex-grow text-right">
                                    @if($story->categories->count())
                                    <div class="hidden sm:flex flex-wrap gap-1 mb-2">
                                        @foreach($story->categories->take(2) as $cat)
                                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">{{ $cat->name }}</span>
                                        @endforeach
                                    </div>
                                    @endif
                                    <h3 class="text-[13px] sm:text-[15px] font-extrabold text-slate-900 mb-1 line-clamp-2 leading-snug group-hover:text-indigo-600 transition-colors">
                                        <a href="{{ route('stories.show', $story->slug) }}" class="hover:text-indigo-600 transition-colors">{{ $story->title }}</a>
                                    </h3>
                                    <p class="hidden sm:block text-slate-400 text-xs leading-relaxed mb-3 flex-grow line-clamp-2">{{ $story->short_desc }}</p>
                                    @if($story->lesson_value)
                                    <div class="hidden sm:block mb-3">
                                        <span class="text-[10px] font-bold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">💡 {{ $story->lesson_value }}</span>
                                    </div>
                                    @endif
                                    <div class="flex items-center justify-between pt-2 sm:pt-3 border-t border-slate-100 mt-auto gap-1">
                                        <div class="text-right">
                                            <span class="text-sm sm:text-base font-extrabold {{ $accent['price'] }}">{{ number_format($story->price, 0) }}</span>
                                            <span class="text-[10px] text-slate-400 font-bold"> ج.م</span>
                                        </div>
                                        <a href="{{ route('stories.show', $story->slug) }}"
                                            class="text-white text-[10px] sm:text-[11px] font-black px-2.5 sm:px-3.5 py-1.5 sm:py-2 rounded-xl transition hover:scale-105 whitespace-nowrap flex-shrink-0"
                                            style="background: linear-gradient(135deg, #f97316, #ec4899);">اصنعها ✨</a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Pagination --}}
                    @if($stories->hasPages())
                    <div class="pt-12 flex justify-center border-t border-slate-100">
                        {{ $stories->links() }}
                    </div>
                    @endif

                    @endif

                </div>
                {{-- end content --}}

            </div>
        </div>
    </div>

</x-front-layout>
