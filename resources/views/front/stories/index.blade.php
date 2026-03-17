<x-front-layout>

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

        <!-- Section for Filters and Grid -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16 w-full flex-grow relative z-20">
            
            <!-- Main Layout Grid (Naturally Sidebar Right in RTL) -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-12 items-start" dir="rtl">
                
                {{-- ===== SIDEBAR FILTERS (Right side in RTL) ===== --}}
                <aside class="lg:col-span-1 space-y-6 h-fit lg:order-1 lg:sticky lg:top-24">
                    
                    @if($hasFilter)
                    <a href="{{ route('stories.index') }}"
                       class="flex items-center justify-center gap-2 w-full bg-white border border-red-100 text-red-500 font-black text-[11px] py-5 px-4 rounded-3xl hover:bg-red-50 transition active:scale-95 shadow-sm">
                        ✕ مسح جميع الفلاتر
                    </a>
                    @endif

                    <!-- Categories -->
                    <div class="bg-white rounded-[2.5rem] p-8 shadow-sm border border-slate-100/60">
                        <h4 class="font-black text-slate-900 mb-6 flex items-center gap-3 justify-start">
                            <span class="bg-indigo-50 w-9 h-9 flex items-center justify-center rounded-xl text-lg">📚</span>
                            التصنيفات
                        </h4>
                        <div class="space-y-2">
                            <a href="{{ route('stories.index', request()->except('category')) }}" 
                               class="flex items-center justify-between px-4 py-3.5 rounded-2xl text-sm font-bold transition {{ !request('category') ? 'bg-indigo-600 text-white shadow-xl shadow-indigo-100' : 'text-slate-500 hover:bg-indigo-50 hover:text-indigo-700' }}">
                                <span>الكل</span>
                                <span class="text-[10px] {{ !request('category') ? 'text-white/60' : 'text-slate-300' }}">({{ \App\Models\Story::where('active', true)->count() }})</span>
                            </a>
                            @foreach($categories as $cat)
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['category' => $cat->slug])) }}" 
                                   class="flex items-center justify-between px-4 py-3.5 rounded-2xl text-sm font-bold transition {{ request('category') == $cat->slug ? 'bg-indigo-600 text-white shadow-xl shadow-indigo-100' : 'text-slate-500 hover:bg-indigo-50 hover:text-indigo-700' }}">
                                    <span>{{ $cat->name }}</span>
                                    <span class="text-[10px] {{ request('category') == $cat->slug ? 'text-white/60' : 'text-slate-300' }}">({{ $cat->stories_count }})</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <!-- Ages -->
                    <div class="bg-white rounded-[2.5rem] p-8 shadow-sm border border-slate-100/60">
                        <h4 class="font-black text-slate-900 mb-6 flex items-center gap-3 justify-start">
                            <span class="bg-amber-50 w-9 h-9 flex items-center justify-center rounded-xl text-lg">🎂</span>
                            العمر
                        </h4>
                        <div class="grid grid-cols-2 gap-3">
                            @foreach($ageRanges as $range)
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['age' => $range])) }}" 
                                   class="text-center py-4 rounded-2xl text-[11px] font-black border-2 transition {{ request('age') == $range ? 'bg-indigo-600 border-indigo-600 text-white shadow-lg shadow-indigo-100' : 'bg-white border-slate-50 text-slate-400 hover:border-indigo-100 hover:text-indigo-600' }}">
                                    {{ $range }} سنة
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <!-- Gender & Language -->
                    <div class="bg-white rounded-[2.5rem] p-8 shadow-sm border border-slate-100/60 space-y-8">
                        <div>
                            <h4 class="font-black text-slate-900 mb-6 flex items-center gap-3 justify-start">
                                <span class="bg-emerald-50 w-9 h-9 flex items-center justify-center rounded-xl text-lg">👦</span>
                                البطل
                            </h4>
                            <div class="flex gap-4">
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['gender' => 'boy'])) }}" class="flex-1 flex flex-col items-center gap-2 py-5 rounded-3xl border-2 transition {{ request('gender') == 'boy' ? 'bg-blue-50 border-blue-200 text-blue-700' : 'bg-slate-50 border-transparent text-slate-400 opacity-60 hover:opacity-100' }}">
                                    <span class="text-2xl">👦</span>
                                    <span class="text-[10px] font-black tracking-widest uppercase">ولد</span>
                                </a>
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['gender' => 'girl'])) }}" class="flex-1 flex flex-col items-center gap-2 py-5 rounded-3xl border-2 transition {{ request('gender') == 'girl' ? 'bg-pink-50 border-pink-200 text-pink-700' : 'bg-slate-50 border-transparent text-slate-400 opacity-60 hover:opacity-100' }}">
                                    <span class="text-2xl">👧</span>
                                    <span class="text-[10px] font-black tracking-widest uppercase">بنت</span>
                                </a>
                            </div>
                        </div>

                        <div class="pt-8 border-t border-slate-50">
                            <h4 class="font-black text-slate-900 mb-6 flex items-center gap-3 justify-start">
                                <span class="bg-slate-50 w-9 h-9 flex items-center justify-center rounded-xl text-lg">🌐</span>
                                اللغة
                            </h4>
                            <div class="flex gap-3">
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['lang' => 'ar'])) }}" class="flex-1 text-center py-4 rounded-2xl text-[10px] font-black border-2 transition {{ request('lang') == 'ar' ? 'bg-slate-900 border-slate-900 text-white shadow-xl' : 'bg-white border-slate-50 text-slate-400 hover:border-slate-200' }}">العربية</a>
                                <a href="{{ route('stories.index', array_merge(request()->query(), ['lang' => 'en'])) }}" class="flex-1 text-center py-4 rounded-2xl text-[10px] font-black border-2 transition {{ request('lang') == 'en' ? 'bg-slate-900 border-slate-900 text-white shadow-xl' : 'bg-white border-slate-50 text-slate-400 hover:border-slate-200' }}">English</a>
                            </div>
                        </div>
                    </div>
                </aside>

                {{-- ===== CONTENT SECTION (Left side in RTL) ===== --}}
                <div class="lg:col-span-3 min-w-0 lg:order-2">
                    
                    {{-- Active Filter Tags --}}
                    @if($hasFilter)
                    <div class="flex flex-wrap gap-2.5 mb-10 justify-end">
                        @if(request('category'))
                            <div class="bg-indigo-50 border border-indigo-100 text-indigo-700 px-5 py-3 rounded-2xl text-[11px] font-black flex items-center gap-3 shadow-sm">
                                <span class="opacity-40">التصنيف:</span> {{ $categories->firstWhere('slug', request('category'))?->name ?? request('category') }}
                            </div>
                        @endif
                        @if(request('age'))
                            <div class="bg-amber-50 border border-amber-100 text-amber-700 px-5 py-3 rounded-2xl text-[11px] font-black flex items-center gap-3 shadow-sm">
                                <span class="opacity-40">العمر:</span> {{ request('age') }} سنة
                            </div>
                        @endif
                    </div>
                    @endif

                    {{-- Counter Header --}}
                    <div class="flex items-center gap-6 mb-12 text-right">
                        <div class="flex flex-col gap-1.5">
                            <h2 class="text-3xl font-black text-slate-900 tracking-tight">المجموعة المتاحة</h2>
                            <p class="text-slate-400 text-xs font-black uppercase tracking-widest flex items-center gap-2">
                                <span class="w-2 h-0.5 bg-indigo-200"></span> تصفح {{ $stories->total() }} قصة مخصصة
                            </p>
                        </div>
                        <div class="h-px flex-1 bg-slate-200/60 rounded-full hidden sm:block"></div>
                    </div>

                    @if($stories->isEmpty())
                        <div class="bg-white rounded-[3.5rem] py-40 text-center border-2 border-dashed border-slate-200 shadow-sm px-6">
                            <div class="text-7xl mb-10 transform hover:scale-110 transition duration-500 inline-block cursor-default">🕵️‍♀️</div>
                            <h3 class="text-3xl font-black text-slate-900 mb-4">لم نجد أي قصص تطابق بحثك!</h3>
                            <p class="text-slate-400 mt-2 font-bold text-lg max-w-sm mx-auto leading-relaxed">جرب باستخدام فلاتر مختلفة أو تصفح كل المجموعة لنتفقد مغامرات جديدة.</p>
                            <a href="{{ route('stories.index') }}" class="mt-10 inline-block bg-indigo-600 text-white font-black px-12 py-5 rounded-[2.5rem] shadow-2xl shadow-indigo-200 hover:bg-indigo-700 transition active:scale-95">عرض جميع القصص</a>
                        </div>
                    @else
                        
                        {{-- UNIFORM 3-COLUMN GRID --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8 pb-12">
                            @foreach($stories as $idx => $story)
                                <div class="bg-white rounded-[2.5rem] border border-slate-100 overflow-hidden shadow-sm hover:shadow-2xl hover:-translate-y-2 transition-all duration-500 group flex flex-col h-full relative">
                                    <div class=" overflow-hidden relative bg-slate-50">
                                        @if($story->cover_image)
                                            <img src="{{ $story->cover_url }}" alt="{{ $story->title }}" class="w-full h-full object-contain group-hover:scale-105 transition duration-1000">
                                        @else
                                            <img src="https://images.unsplash.com/{{ $fallbacks[$idx % count($fallbacks)] }}?w=700&auto=format&fit=crop&q=85" alt="{{ $story->title }}" class="w-full h-full object-cover group-hover:scale-110 transition duration-1000">
                                        @endif
                                        
                                        <!-- Age Badge -->
                                        <div class="absolute top-5 right-5 z-10 scale-90 origin-top-right group-hover:scale-100 transition duration-500">
                                            <div class="bg-white/95 backdrop-blur-md px-4 py-2 rounded-2xl text-[10px] font-black shadow-2xl border border-white/50 text-slate-800 flex items-center gap-2">
                                                <span class="w-1.5 h-1.5 bg-amber-400 rounded-full"></span>
                                                {{ $story->age_range }} سنة
                                            </div>
                                        </div>

                                        <!-- Simple Overlay on Hover -->
                                        <div class="absolute inset-0 bg-indigo-900/10 opacity-0 group-hover:opacity-100 transition-opacity duration-700"></div>
                                    </div>
                                    
                                    <div class="p-8 flex flex-col flex-grow text-right">
                                        <div class="flex flex-wrap gap-2 justify-end mb-4 opacity-50">
                                            @foreach($story->categories->take(1) as $cat)
                                            <span class="text-indigo-600 text-[10px] font-black uppercase tracking-widest">{{ $cat->name }}</span>
                                            @endforeach
                                        </div>

                                        <h3 class="text-2xl font-black text-slate-900 mb-4 group-hover:text-indigo-600 transition-colors leading-tight">{{ $story->title }}</h3>
                                        <p class="text-slate-400 text-[13px] font-medium leading-relaxed mb-8 line-clamp-2 h-10">{{ $story->short_desc }}</p>
                                        
                                        <div class="flex items-center justify-between pt-8 border-t border-slate-50 mt-auto">
                                            <div class="flex flex-col items-end">
                                                <span class="text-xs font-bold text-slate-300 mb-0.5">السعر</span>
                                                <div class="flex items-baseline gap-1">
                                                    <span class="text-2xl font-black text-indigo-600 tracking-tighter">{{ number_format($story->price, 0) }}</span>
                                                    <small class="text-[10px] font-black text-slate-400">ج.م</small>
                                                </div>
                                            </div>
                                            <a href="{{ route('stories.show', $story->slug) }}" class="bg-slate-900 text-white text-[11px] font-black px-7 py-4 rounded-2xl shadow-xl shadow-slate-100 hover:bg-indigo-600 transition-all active:scale-95 group-hover:translate-x-1">تخصيص القصة</a>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Pagination --}}
                        @if($stories->hasPages())
                        <div class="pt-20 flex justify-center border-t border-slate-100">
                            {{ $stories->links() }}
                        </div>
                        @endif

                    @endif

                </div>

            </div>

        </div>
    </div>

</x-front-layout>
