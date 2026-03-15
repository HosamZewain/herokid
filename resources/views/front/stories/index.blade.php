<x-front-layout>

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
        
        <!-- Header Section -->
        <div class="bg-indigo-900 pt-16 pb-24 flex items-center justify-center relative overflow-hidden shrink-0">
            <div class="max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 text-white text-right relative z-10">
                <span class="text-indigo-300 font-black text-xs uppercase tracking-[0.2em] mb-4 block">اختر قصتك</span>
                <h1 class="text-4xl md:text-5xl font-black mb-6 leading-tight">مكتبة قصص HeroKid</h1>
                <p class="text-indigo-200/80 font-medium text-lg max-w-2xl mr-0 ml-auto leading-relaxed">قصص مخصصة تجعل طفلك بطل القصة الحقيقي.</p>
            </div>
            <!-- Decorative elements -->
            <div class="absolute -bottom-20 -right-20 w-80 h-80 bg-indigo-500/20 rounded-full blur-3xl"></div>
            <div class="absolute -top-20 -left-20 w-80 h-80 bg-indigo-400/10 rounded-full blur-3xl"></div>
        </div>

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
                                    <div class="aspect-[1/1] overflow-hidden relative bg-slate-50">
                                        @if($story->cover_image)
                                            <img src="{{ $story->cover_url }}" alt="{{ $story->title }}" class="w-full h-full object-cover group-hover:scale-110 transition duration-1000">
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
