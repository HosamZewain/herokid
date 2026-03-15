<x-front-layout>

    {{-- =============================================
    HERO SECTION — Enhanced
    =============================================== --}}
    <style>
        @keyframes hFloat {

            0%,
            100% {
                transform: translateY(0) rotate(-3deg)
            }

            50% {
                transform: translateY(-18px) rotate(-3deg)
            }
        }

        @keyframes hFloat2 {

            0%,
            100% {
                transform: translateY(0) rotate(4deg)
            }

            50% {
                transform: translateY(-14px) rotate(4deg)
            }
        }

        @keyframes hFloat3 {

            0%,
            100% {
                transform: translateY(0) rotate(-2deg)
            }

            50% {
                transform: translateY(-10px) rotate(-2deg)
            }
        }

        @keyframes hBadge {

            0%,
            100% {
                transform: translateY(0) scale(1)
            }

            50% {
                transform: translateY(-8px) scale(1.03)
            }
        }

        @keyframes hBadge2 {

            0%,
            100% {
                transform: translateY(0) scale(1)
            }

            50% {
                transform: translateY(-10px) scale(1.02)
            }
        }

        @keyframes hBadge3 {

            0%,
            100% {
                transform: translateY(0) scale(1)
            }

            50% {
                transform: translateY(-6px) scale(1.03)
            }
        }

        @keyframes sparkle {

            0%,
            100% {
                opacity: .2;
                transform: scale(.8) rotate(0deg)
            }

            50% {
                opacity: 1;
                transform: scale(1.2) rotate(20deg)
            }
        }

        @keyframes blobMove {

            0%,
            100% {
                transform: translate(0, 0) scale(1)
            }

            33% {
                transform: translate(20px, -15px) scale(1.05)
            }

            66% {
                transform: translate(-10px, 20px) scale(.95)
            }
        }

        @keyframes dotBlink {

            0%,
            100% {
                opacity: 1;
                transform: scale(1)
            }

            50% {
                opacity: .4;
                transform: scale(.6)
            }
        }

        @keyframes heroFadeUp {
            from {
                opacity: 0;
                transform: translateY(30px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        @keyframes counterUp {
            from {
                opacity: 0;
                transform: translateY(10px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        .h-float {
            animation: hFloat 5s ease-in-out infinite;
        }

        .h-float2 {
            animation: hFloat2 6s ease-in-out infinite;
        }

        .h-float3 {
            animation: hFloat3 7s ease-in-out infinite;
        }

        .h-badge {
            animation: hBadge 4s ease-in-out infinite;
        }

        .h-badge2 {
            animation: hBadge2 5.5s ease-in-out infinite;
        }

        .h-badge3 {
            animation: hBadge3 4.5s ease-in-out infinite;
        }

        .sparkle-star {
            animation: sparkle 3s ease-in-out infinite;
        }

        .blob1 {
            animation: blobMove 10s ease-in-out infinite;
        }

        .blob2 {
            animation: blobMove 13s ease-in-out infinite reverse;
        }

        .blob3 {
            animation: blobMove 9s ease-in-out infinite 2s;
        }

        .dot-blink {
            animation: dotBlink 1.5s ease-in-out infinite;
        }

        .hero-text-anim {
            opacity: 0;
            animation: heroFadeUp .9s ease forwards;
        }

        .hero-stat {
            opacity: 0;
            animation: counterUp .6s ease forwards;
        }

        /* Smoother float animation */
        @keyframes h-float {
            0%, 100% { transform: translate(-50%, 0) rotate(0deg); }
            50% { transform: translate(-50%, -15px) rotate(1deg); }
        }
        .h-float {
            animation: h-float 5s ease-in-out infinite;
        }
    </style>

    <div class="relative min-h-[92vh] flex items-center overflow-hidden"
        style="background:linear-gradient(155deg,#f5f3ff 0%,#fafafa 50%,#fff8f0 100%);">

        {{-- Dot-grid pattern --}}
        <div class="absolute inset-0 pointer-events-none"
            style="background-image:radial-gradient(circle,#c7d2fe 1px,transparent 1px);background-size:28px 28px;opacity:.35;">
        </div>

        {{-- Animated gradient blobs --}}
        <div class="blob1 absolute -top-24 -right-24 w-96 h-96 rounded-full pointer-events-none"
            style="background:radial-gradient(circle,#c7d2fe,transparent 70%);opacity:.6;"></div>
        <div class="blob2 absolute bottom-0 left-10 w-80 h-80 rounded-full pointer-events-none"
            style="background:radial-gradient(circle,#fbcfe8,transparent 70%);opacity:.5;"></div>
        <div class="blob3 absolute top-1/2 left-1/2 -translate-x-1/2 w-64 h-64 rounded-full pointer-events-none"
            style="background:radial-gradient(circle,#fde68a,transparent 70%);opacity:.35;"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 py-16">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-16 items-center">

                {{-- ── TEXT COLUMN (right in RTL) ── --}}
                <div class="text-right order-2 lg:order-1 hero-text-anim" style="animation-delay:.1s">

                    {{-- Animated badge --}}
                    <div
                        class="inline-flex items-center gap-2 px-4 py-2 mb-7 bg-white border border-indigo-100 rounded-full shadow-sm">
                        <span class="dot-blink inline-block w-2.5 h-2.5 bg-indigo-500 rounded-full"></span>
                        <span class="text-sm font-bold text-indigo-600">✨ أول قصة أطفال بوجه طفلك الحقيقي في مصر</span>
                    </div>

                    {{-- Headline --}}
                    <h1 class="text-4xl sm:text-5xl md:text-[3.8rem] font-extrabold text-slate-900 leading-[1.15] mb-6">
                        طفلك ليس قارئاً…<br>
                        <span class="relative inline-block mt-2">
                            <span class="relative z-10 text-transparent bg-clip-text" style="background-image:linear-gradient(135deg,#4f46e5,#ec4899)">
                                هو البطل الحقيقي!
                            </span>
                            <svg class="absolute -bottom-2 right-0 w-full h-[12px] opacity-80" viewBox="0 0 300 12" preserveAspectRatio="none">
                                <path d="M0,10 Q75,0 150,8 Q225,16 300,6" fill="none" stroke="url(#uGrad)" stroke-width="4" stroke-linecap="round" />
                            </svg>
                        </span>
                    </h1>

                    <p class="text-xl text-slate-600 mb-8 leading-relaxed max-w-xl">
                        نحوّل خيال طفلك إلى كتاب مطبوع يحمل اسمه ووجهه الحقيقي في كل صفحة. هدية تُخلَّد، وذكرى تدوم.
                    </p>

                    {{-- Feature pills --}}
                    <div class="flex flex-wrap gap-2 justify-center mb-9">
                        <span
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-indigo-50 text-indigo-700 text-sm font-bold rounded-full border border-indigo-100">🎨
                            وجه طفلك في كل رسمة</span>
                        <span
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-pink-50 text-pink-700 text-sm font-bold rounded-full border border-pink-100">🚀
                            توصيل لباب بيتك</span>
                        <span
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-amber-50 text-amber-700 text-sm font-bold rounded-full border border-amber-100">⏱
                            خلال {{ $settings["delivery_days"] ?? 5 }} أيام فقط</span>
                    </div>

                    {{-- CTA Buttons --}}
                    <div class="flex flex-wrap gap-4 justify-center">
                        <a href="{{ route('stories.index') }}"
                            class="group relative overflow-hidden text-white font-bold py-4 px-10 rounded-2xl shadow-lg flex items-center gap-3 transition hover:-translate-y-0.5 hover:shadow-indigo-300"
                            style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">
                            <span>تصفح مكتبتنا</span>
                            <svg class="w-5 h-5 transform group-hover:-translate-x-1 transition" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                                    d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                            </svg>
                            <div class="absolute inset-0 opacity-0 group-hover:opacity-100 transition"
                                style="background:linear-gradient(135deg,#4338ca,#6d28d9)"></div>
                        </a>
                        <a href="{{ route('how-it-works') }}"
                            class="bg-white text-slate-700 font-bold py-4 px-10 rounded-2xl border border-slate-200 hover:border-indigo-300 hover:shadow-md transition hover:-translate-y-0.5">
                            كيف يعمل؟
                        </a>
                    </div>

                    {{-- Social Proof & Trust Section --}}
                    <div class="flex flex-col sm:flex-row items-center gap-6 mt-12 justify-center lg:justify-end">
                        {{-- Avatars Group --}}
                        <div class="flex items-center gap-3">
                            <div class="flex -space-x-3 rtl:space-x-reverse">
                                @foreach(['47', '12', '32', '15', '22'] as $av)
                                    <img src="https://i.pravatar.cc/100?img={{ $av }}"
                                        class="w-11 h-11 rounded-full border-2 border-white shadow-md object-cover" alt="User">
                                @endforeach
                            </div>
                            <div class="text-right">
                                <div class="flex items-center gap-0.5 justify-end">
                                    @for($i = 0; $i < 5; $i++)
                                        <svg class="w-4 h-4 text-amber-400 fill-current" viewBox="0 0 20 20">
                                            <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                        </svg>
                                    @endfor
                                </div>
                                <p class="text-xs font-bold text-slate-700">ثقة +100 عائلة</p>
                            </div>
                        </div>

                        <div class="hidden sm:block w-px h-10 bg-slate-200"></div>

                        {{-- Stats --}}
                        <div class="flex items-center gap-8">
                            <div class="text-right hero-stat" style="animation-delay:.4s">
                                <p class="text-2xl font-black text-slate-900 leading-none">
                                    {{ $settings["rating"] ?? 4.9 }}<span class="text-indigo-500">/5</span>
                                </p>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">التقييم العام</p>
                            </div>
                            <div class="text-right hero-stat" style="animation-delay:.5s">
                                <p class="text-2xl font-black text-slate-900 leading-none">
                                    {{ $settings["delivery_days"] ?? 5 }} <span class="text-sm font-bold text-indigo-500">أيام</span>
                                </p>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-1">سرعة التنفيذ</p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── VISUAL COLUMN (left in RTL) ── --}}
                <div class="relative flex justify-center order-1 lg:order-2 h-[450px] md:h-[600px]">

                    {{-- Glow ring behind main card --}}
                    <div class="absolute"
                        style="top:60px;left:50%;transform:translateX(-50%);width:280px;height:380px;border-radius:2.5rem;background:radial-gradient(ellipse,rgba(99,102,241,.3),transparent 75%);filter:blur(40px);z-index:1;">
                    </div>

                    {{-- FLOATING BADGES (Social Proof) --}}
                    <div class="absolute -top-6 -right-12 z-20 hero-stat" style="animation-delay:.7s">
                        <div class="bg-white/90 backdrop-blur-md p-3.5 rounded-2xl shadow-xl border border-indigo-50 flex items-center gap-3">
                            <div class="w-10 h-10 bg-indigo-100 rounded-xl flex items-center justify-center text-indigo-600">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">جودة عالية</p>
                                <p class="text-sm font-bold text-slate-800">ورق مقوى فاخر</p>
                            </div>
                        </div>
                    </div>

                    <div class="absolute bottom-12 -left-16 z-20 hero-stat" style="animation-delay:.9s">
                        <div class="bg-white/90 backdrop-blur-md p-3.5 rounded-2xl shadow-xl border border-pink-50 flex items-center gap-3">
                            <div class="w-10 h-10 bg-pink-100 rounded-xl flex items-center justify-center text-pink-600">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <p class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">شحن سريع</p>
                                <p class="text-sm font-bold text-slate-800">خلال 3-5 أيام</p>
                            </div>
                        </div>
                    </div>

                    {{-- MAIN BOOK CARD --}}
                    <div class="h-float absolute overflow-hidden bg-white"
                        style="top:60px;left:50%;transform:translateX(-50%);width:260px;height:360px;border-radius:2.25rem;z-index:10;box-shadow:0 30px 80px rgba(0,0,0,.22);">
                        <img src="{{ $settings['img_hero_main'] ?? 'https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?w=500&auto=format&fit=crop&q=80' }}"
                            alt="HeroKid Book" class="w-full h-full object-cover">
                        
                        {{-- Floating label --}}
                        <div class="absolute top-5 right-5 z-20">
                            <span class="px-3.5 py-1.5 bg-indigo-600/90 backdrop-blur-sm text-white text-[10px] font-black rounded-full shadow-lg">
                                الأكثر مبيعاً 🔥
                            </span>
                        </div>
                        
                        {{-- Dark overlay with title --}}
                        <div class="absolute inset-0"
                            style="background:linear-gradient(to top,rgba(15,23,42,0.95) 0%,transparent 65%);"></div>
                        <div class="absolute bottom-0 left-0 right-0 p-7 text-right">
                            <p class="text-indigo-300 text-[10px] font-black uppercase tracking-[0.2em] mb-1.5">مغامرة جديدة</p>
                            <h3 class="text-white font-black text-xl leading-tight">رحلة طفلي إلى <br> كوكب العجائب</h3>
                        </div>
                    </div>

                    {{-- MINI STORY PREVIEW --}}
                    <div class="absolute top-32 -left-10 z-[5] hero-stat" style="animation-delay:1.1s">
                        <div class="w-24 h-32 rounded-2xl overflow-hidden shadow-2xl rotate-[-12deg] border-2 border-white/50">
                            <img src="https://images.unsplash.com/photo-1448375240586-882707db888b?w=300&fit=crop" class="w-full h-full object-cover" alt="Review">
                        </div>
                    </div>
                </div>
                    <svg class="sparkle-star absolute" style="top:110px;right:20px;z-index:25;animation-delay:1s;"
                        width="14" height="14" viewBox="0 0 24 24" fill="#a78bfa">
                        <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z" />
                    </svg>
                    <svg class="sparkle-star absolute" style="bottom:120px;right:25px;z-index:25;animation-delay:.7s;"
                        width="18" height="18" viewBox="0 0 24 24" fill="#f472b6">
                        <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z" />
                    </svg>
                    <svg class="sparkle-star absolute" style="top:170px;right:10px;z-index:25;animation-delay:1.5s;"
                        width="12" height="12" viewBox="0 0 24 24" fill="#34d399">
                        <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z" />
                    </svg>
                    <svg class="sparkle-star absolute" style="bottom:170px;left:20px;z-index:25;animation-delay:.3s;"
                        width="16" height="16" viewBox="0 0 24 24" fill="#fbbf24">
                        <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z" />
                    </svg>

                </div>{{-- end visual column --}}

            </div>
        </div>
    </div>

    {{-- FEATURED STORIES --}}
    <section class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-end mb-14">
                <div class="text-right">
                    <span class="text-indigo-600 font-bold text-sm uppercase tracking-wider">مكتبتنا</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-2">قصص يعشقها الأطفال</h2>
                    <p class="text-lg text-slate-500 mt-2">كل قصة تغرس قيمة وتصنع ذكرى.</p>
                </div>
                <a href="{{ route('stories.index') }}"
                    class="hidden sm:flex text-indigo-600 font-bold hover:text-indigo-700 items-center gap-2 group transition">
                    عرض الكل
                    <svg class="w-5 h-5 group-hover:-translate-x-1 transition" fill="none" stroke="currentColor"
                        viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                @forelse($featuredStories as $story)
                    <div
                        class="group bg-white rounded-[2rem] border border-slate-100 overflow-hidden hover:shadow-2xl transition-all duration-500 hover:-translate-y-2 flex flex-col">
                        <div class="aspect-[4/3] overflow-hidden bg-gradient-to-br from-indigo-50 to-slate-100 relative">
                            @if($story->cover_image)
                                <img src="{{ $story->cover_url }}" alt="{{ $story->title }}"
                                    class="w-full h-full object-cover transition duration-700 group-hover:scale-110">
                            @else
                                @php $fallbackImgs = ['photo-1446776811953-b23d57bd21aa', 'photo-1518709268805-4e9042af9f23', 'photo-1448375240586-882707db888b', 'photo-1490750967868-88df5691cc4a', 'photo-1575361204480-aadea25e6e68', 'photo-1581091226825-a6a2a5aee158']; @endphp
                                <img src="https://images.unsplash.com/{{ $fallbackImgs[$loop->index % count($fallbackImgs)] }}?w=600&auto=format&fit=crop&q=80"
                                    alt="{{ $story->title }}"
                                    class="w-full h-full object-cover transition duration-700 group-hover:scale-110">
                            @endif
                            <div class="absolute top-4 right-4">
                                <span
                                    class="bg-white/90 text-indigo-600 text-xs font-bold px-3 py-1.5 rounded-full shadow">{{ $story->age_range }}</span>
                            </div>
                        </div>
                        <div class="p-7 flex flex-col flex-grow">
                            <h3 class="text-xl font-bold text-slate-900 mb-2">{{ $story->title }}</h3>
                            <p class="text-slate-500 text-sm leading-relaxed mb-4 flex-grow line-clamp-2">
                                {{ $story->short_desc }}
                            </p>
                            @if($story->lesson_value)
                                <div class="mb-5">
                                    <span class="text-xs text-green-700 bg-green-50 px-2.5 py-1 rounded-full font-bold">💡
                                        {{ $story->lesson_value }}</span>
                                </div>
                            @endif
                            <div class="flex items-center justify-between pt-4 border-t border-slate-100 mt-auto">
                                <span class="text-xl font-extrabold text-indigo-600">{{ number_format($story->price, 0) }}
                                    ج.م</span>
                                <a href="{{ route('stories.show', $story->slug) }}"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold px-5 py-2.5 rounded-xl transition">اصنع
                                    قصتك</a>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-3 text-center py-16 text-slate-400">
                        <p class="text-5xl mb-4">📚</p>
                        <p>جاري إضافة قصص رائعة قريباً!</p>
                    </div>
                @endforelse
            </div>
        </div>
    </section>

    {{-- HOW IT WORKS --}}
    <section id="how-it-works" class="py-24 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16">
                <span class="text-indigo-600 font-bold text-sm uppercase tracking-wider">العملية</span>
                <h2 class="text-4xl font-extrabold text-slate-900 mt-2 mb-4">٣ خطوات وقصتك في الطريق!</h2>
                <p class="text-lg text-slate-500">بضع دقائق منك، وكتاب لا يُنسى لهم.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                {{-- Step 1 --}}
                <div class="relative group text-center">
                    <div
                        class="hidden md:block absolute top-14 left-[-14%] w-[28%] border-t-2 border-dashed border-slate-300 z-10">
                    </div>
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-6 shadow-lg">
                        <img src="{{ $settings['img_home_step1'] ?? 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=600&auto=format&fit=crop&q=80' }}"
                            alt="اختر القصة"
                            class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                        <div class="absolute inset-0 bg-gradient-to-t from-indigo-900/80 to-transparent"></div>
                        <div
                            class="absolute top-3 right-3 w-10 h-10 bg-indigo-600 rounded-2xl flex items-center justify-center text-white font-extrabold text-lg shadow-md">
                            ١</div>
                        <div class="absolute bottom-4 right-0 left-0 text-center">
                            <p class="text-white font-extrabold text-lg">اختر القصة</p>
                        </div>
                    </div>
                    <div
                        class="inline-block bg-indigo-50 text-indigo-600 font-bold text-xs px-3 py-1 rounded-full mb-2">
                        خطوة ١</div>
                    <p class="text-slate-500 leading-relaxed max-w-xs mx-auto text-sm">تصفح مكتبتنا واختر القصة التي
                        تناسب عمر طفلك واهتماماته.</p>
                </div>

                {{-- Step 2 --}}
                <div class="relative group text-center">
                    <div
                        class="hidden md:block absolute top-14 left-[-14%] w-[28%] border-t-2 border-dashed border-slate-300 z-10">
                    </div>
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-6 shadow-lg">
                        <img src="{{ $settings['img_home_step2'] ?? 'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=600&auto=format&fit=crop&q=80' }}"
                            alt="خصص وأرسل"
                            class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                        <div class="absolute inset-0 bg-gradient-to-t from-pink-900/80 to-transparent"></div>
                        <div
                            class="absolute top-3 right-3 w-10 h-10 bg-pink-500 rounded-2xl flex items-center justify-center text-white font-extrabold text-lg shadow-md">
                            ٢</div>
                        <div class="absolute bottom-4 right-0 left-0 text-center">
                            <p class="text-white font-extrabold text-lg">خصّص وأرسل</p>
                        </div>
                    </div>
                    <div class="inline-block bg-pink-50 text-pink-600 font-bold text-xs px-3 py-1 rounded-full mb-2">
                        خطوة ٢</div>
                    <p class="text-slate-500 leading-relaxed max-w-xs mx-auto text-sm">أضف اسم طفلك وارفع صورته. نحن
                        نحوله إلى بطل الأحداث.</p>
                </div>

                {{-- Step 3 --}}
                <div class="relative group text-center">
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-6 shadow-lg">
                        <img src="{{ $settings['img_home_step3'] ?? 'https://images.unsplash.com/photo-1513885535751-8b9238bd345a?w=600&auto=format&fit=crop&q=80' }}"
                            alt="استلم الكتاب"
                            class="w-full h-full object-cover transition duration-700 group-hover:scale-105">
                        <div class="absolute inset-0 bg-gradient-to-t from-amber-900/80 to-transparent"></div>
                        <div
                            class="absolute top-3 right-3 w-10 h-10 bg-amber-500 rounded-2xl flex items-center justify-center text-white font-extrabold text-lg shadow-md">
                            ٣</div>
                        <div class="absolute bottom-4 right-0 left-0 text-center">
                            <p class="text-white font-extrabold text-lg">استلم الكتاب</p>
                        </div>
                    </div>
                    <div class="inline-block bg-amber-50 text-amber-600 font-bold text-xs px-3 py-1 rounded-full mb-2">
                        خطوة ٣</div>
                    <p class="text-slate-500 leading-relaxed max-w-xs mx-auto text-sm"> نطبع ونشحن إلى بابك مباشرة.</p>
                </div>
            </div>
            <div class="text-center mt-14">
                <a href="{{ route('how-it-works') }}"
                    class="inline-block text-indigo-600 font-bold border border-indigo-200 py-3 px-8 rounded-xl hover:bg-indigo-50 transition">اعرف
                    أكثر عن العملية</a>
            </div>
        </div>
    </section>

    {{-- WHY PARENTS LOVE IT --}}
    <section class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                <div class="text-right">
                    <span class="text-indigo-600 font-bold text-sm uppercase tracking-wider">لماذا HeroKid؟</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-2 mb-8 leading-snug">لأن طفلك يستحق<br>أكثر من
                        مجرد كتاب.</h2>
                    <div class="space-y-6">
                        <div class="flex items-start gap-4">
                            <div
                                class="w-12 h-12 bg-indigo-50 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl">
                                🎨</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">وجه طفلك في الرسومات</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">رسومات مخصصة بالذكاء الاصطناعي تضع وجه
                                    طفلك في كل مشهد.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div
                                class="w-12 h-12 bg-pink-50 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl">
                                📖</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">قصص ذات معنى</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">كل قصة تحمل درساً تربوياً أصيلاً يترسخ
                                    في ذهن طفلك.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div
                                class="w-12 h-12 bg-green-50 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl">
                                🛡️</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">خصوصية محمية</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">صور طفلك تُستخدم فقط لقصته ثم تُحذف
                                    تلقائياً بعد اكتمال الطلب.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div
                                class="w-12 h-12 bg-amber-50 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl">
                                🎁</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">هدية مثالية للمناسبات</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">عيد ميلاد، نهاية سنة دراسية —
                                    كتاب شخصي لا يُنسى.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    {{-- Books stat --}}
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-md group">
                        <img src="{{ $settings['img_stat_books'] ?? 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=500&auto=format&fit=crop&q=80' }}"
                            alt="قصص" class="w-full h-full object-cover transition duration-500 group-hover:scale-105">
                        <div class="absolute inset-0"
                            style="background:linear-gradient(to top,rgba(79,70,229,.9) 0%,rgba(79,70,229,.3) 60%,transparent 100%);">
                        </div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">+25</p>
                            <p class="text-indigo-200 text-sm mt-0.5">قصص متاحة</p>
                        </div>
                        <div
                            class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">
                            📚</div>
                    </div>

                    {{-- Rating stat --}}
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-md group">
                        <img src="{{ $settings['img_stat_rating'] ?? 'https://images.unsplash.com/photo-1543269865-cbf427effbad?w=500&auto=format&fit=crop&q=80' }}"
                            alt="تقييم"
                            class="w-full h-full object-cover transition duration-500 group-hover:scale-105">
                        <div class="absolute inset-0"
                            style="background:linear-gradient(to top,rgba(236,72,153,.9) 0%,rgba(236,72,153,.3) 60%,transparent 100%);">
                        </div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">
                                {{ $settings["rating"] ?? 4.8 }} ⭐
                            </p>
                            <p class="text-pink-200 text-sm mt-0.5">متوسط التقييم</p>
                        </div>
                        <div
                            class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">
                            ⭐</div>
                    </div>

                    {{-- Happy families stat --}}
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-md group">
                        <img src="{{ $settings['img_stat_family'] ?? 'https://images.unsplash.com/photo-1511895426328-dc8714191011?w=500&auto=format&fit=crop&q=80' }}"
                            alt="عائلات سعيدة"
                            class="w-full h-full object-cover transition duration-500 group-hover:scale-105">
                        <div class="absolute inset-0"
                            style="background:linear-gradient(to top,rgba(217,119,6,.9) 0%,rgba(217,119,6,.3) 60%,transparent 100%);">
                        </div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">+{{ $settings["happy_families"] ?? 100 }}</p>
                            <p class="text-amber-200 text-sm mt-0.5">عائلة سعيدة</p>
                        </div>
                        <div
                            class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">
                            👨‍👩‍👧</div>
                    </div>

                    {{-- Delivery stat --}}
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-md group">
                        <img src="{{ $settings['img_stat_delivery'] ?? 'https://images.unsplash.com/photo-1619454016518-697bc231e7cb?w=500&auto=format&fit=crop&q=80' }}"
                            alt="توصيل"
                            class="w-full h-full object-cover transition duration-500 group-hover:scale-105">
                        <div class="absolute inset-0"
                            style="background:linear-gradient(to top,rgba(22,163,74,.9) 0%,rgba(22,163,74,.3) 60%,transparent 100%);">
                        </div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">
                                {{ $settings["delivery_days"] ?? 5 }} يوم
                            </p>
                            <p class="text-green-200 text-sm mt-0.5">متوسط التوصيل</p>
                        </div>
                        <div
                            class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">
                            🚀</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- TESTIMONIALS (dynamic from DB) --}}
    @if($testimonials->count())
        <section class="py-24 bg-slate-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-2xl mx-auto mb-16">
                    <span class="text-indigo-600 font-bold text-sm uppercase tracking-wider">آراء العملاء</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-2 mb-4">ماذا يقول الآباء؟</h2>
                    <p class="text-lg text-slate-500">حكايات حقيقية من عائلات أصبح أطفالها أبطالاً.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    @foreach($testimonials->take(3) as $testimonial)
                        <div
                            class="bg-white rounded-2xl p-8 shadow-sm border border-slate-100 hover:shadow-md transition flex flex-col">
                            <div class="flex items-center gap-1 mb-4 text-yellow-400">
                                @for($i = 0; $i < $testimonial->rating; $i++)★@endfor
                                @for($i = $testimonial->rating; $i < 5; $i++)☆@endfor
                            </div>
                            <p class="text-slate-600 leading-relaxed mb-6 text-sm flex-grow">"{{ $testimonial->review_text }}"
                            </p>
                            <div class="flex items-center gap-3 pt-4 border-t border-slate-50">
                                @if($testimonial->reviewer_avatar)
                                    <img src="{{ $testimonial->reviewer_avatar }}" alt="{{ $testimonial->reviewer_name }}"
                                        class="w-10 h-10 rounded-full object-cover border-2 border-slate-100 flex-shrink-0">
                                @else
                                    <div
                                        class="w-10 h-10 bg-indigo-50 rounded-full flex items-center justify-center text-indigo-700 font-extrabold text-sm flex-shrink-0">
                                        {{ mb_substr($testimonial->reviewer_name, 0, 1) }}
                                    </div>
                                @endif
                                <p class="font-bold text-slate-900 text-sm">
                                    {{ $testimonial->reviewer_name }}
                                    @if($testimonial->reviewer_location)
                                        <span class="text-slate-400 font-normal"> — {{ $testimonial->reviewer_location }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- PRICING TEASER --}}
    <section class="py-24 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-14">
                <span class="text-indigo-600 font-bold text-sm uppercase tracking-wider">الأسعار</span>
                <h2 class="text-4xl font-extrabold text-slate-900 mt-2 mb-4">سعر واحد، قيمة لا تُقدَّر</h2>
                <p class="text-lg text-slate-500">لا رسوم خفية. تدفع مرة واحدة وتحصل على ذكرى للأبد.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <div class="bg-slate-50 rounded-3xl p-10 border border-slate-200 text-right">
                    <h3 class="text-xl font-bold text-slate-900 mb-2">الباقة الأساسية</h3>
                    <p class="text-slate-500 text-sm mb-6">كتاب مخصص بغلاف ناعم</p>
                    <div class="flex items-end gap-2 mb-6 justify-end">
                        <span class="text-slate-500 text-xl">ج.م</span>
                        <span
                            class="text-5xl font-extrabold text-slate-900">{{ $settings["price_soft_cover"] ?? 199 }}</span>
                    </div>
                    <ul class="space-y-2 text-sm text-slate-600 mb-8">
                        <li class="flex items-center gap-2 justify-end"><span>اسم الطفل + وجهه في الرسومات</span><span
                                class="text-green-500">✓</span></li>
                        <li class="flex items-center gap-2 justify-end"><span>٢٤ صفحة مصورة</span><span
                                class="text-green-500">✓</span></li>
                        <li class="flex items-center gap-2 justify-end"><span>توصيل لجميع المحافظات</span><span
                                class="text-green-500">✓</span></li>
                    </ul>
                    <a href="{{ route('stories.index') }}"
                        class="block text-center bg-slate-200 hover:bg-indigo-600 hover:text-white text-slate-800 font-bold py-3 rounded-xl transition">ابدأ
                        الآن</a>
                </div>
                <div class="relative bg-indigo-600 rounded-3xl p-10 text-right shadow-2xl shadow-indigo-200">
                    <div class="absolute -top-3 left-1/2 -translate-x-1/2">
                        <span
                            class="bg-yellow-400 text-yellow-900 text-xs font-extrabold px-4 py-1.5 rounded-full shadow">⭐
                            الأكثر طلباً</span>
                    </div>
                    <h3 class="text-xl font-bold text-white mb-2">الباقة المميزة</h3>
                    <p class="text-indigo-200 text-sm mb-6">كتاب هدية فاخر بغلاف صلب</p>
                    <div class="flex items-end gap-2 mb-6 justify-end">
                        <span class="text-indigo-300 text-xl">ج.م</span>
                        <span
                            class="text-5xl font-extrabold text-white">{{ $settings["price_hard_cover"] ?? 299 }}</span>
                    </div>
                    <ul class="space-y-2 text-sm text-indigo-100 mb-8">
                        <li class="flex items-center gap-2 justify-end"><span>كل مزايا الأساسية</span><span
                                class="text-white">✓</span></li>
                        <li class="flex items-center gap-2 justify-end"><span>غلاف صلب + ٣٢ صفحة</span><span
                                class="text-white">✓</span></li>
                        <li class="flex items-center gap-2 justify-end"><span>تغليف هدايا + شحن مجاني</span><span
                                class="text-white">✓</span></li>
                    </ul>
                    <a href="{{ route('stories.index') }}"
                        class="block text-center bg-white text-indigo-600 font-bold py-3 rounded-xl hover:bg-indigo-50 transition">ابدأ
                        الآن</a>
                </div>
            </div>
            <div class="text-center mt-8">
                <a href="{{ route('pricing') }}" class="text-indigo-600 font-bold hover:underline text-sm">عرض تفاصيل
                    كاملة عن الأسعار ←</a>
            </div>
        </div>
    </section>

    {{-- FAQ SNIPPET --}}
    @if($faqs->count())
        <section class="py-24 bg-slate-50">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="text-indigo-600 font-bold text-sm uppercase tracking-wider">الأسئلة الشائعة</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-2 mb-4">أسئلة يطرحها الآباء دائماً</h2>
                </div>
                <div class="space-y-4" x-data="{}">
                    @foreach($faqs as $faq)
                        <div class="bg-white border border-slate-100 rounded-2xl overflow-hidden" x-data="{ open: false }">
                            <button @click="open = !open"
                                class="w-full text-right px-6 py-5 font-bold text-slate-900 flex justify-between items-center hover:bg-slate-50 transition">
                                <span>{{ $faq->question }}</span>
                                <svg class="w-5 h-5 text-indigo-400 flex-shrink-0 transform transition-transform duration-200"
                                    :class="{'rotate-180': open}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <div x-show="open" x-transition
                                class="px-6 pb-5 pt-2 text-slate-600 border-t border-slate-50 text-sm leading-relaxed"
                                style="display:none">{{ $faq->answer }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="text-center mt-10">
                    <a href="{{ route('faq') }}"
                        class="inline-block border border-indigo-200 text-indigo-600 font-bold py-3 px-8 rounded-xl hover:bg-indigo-50 transition">عرض
                        جميع الأسئلة</a>
                </div>
            </div>
        </section>
    @endif

    {{-- CONTACT US SECTION --}}
    <section id="contact-us" class="py-16 md:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">

                {{-- Left Side: Contact Info --}}
                <div class="text-right">
                    <span class="text-indigo-600 font-bold text-sm uppercase tracking-wider">تواصل معنا</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-2 mb-6">نحن هنا للإجابة على استفساراتك</h2>
                    <p class="text-lg text-slate-500 mb-10 leading-relaxed">
                        هل لديك سؤال حول قصة معينة؟ أو استفسار عن حالة طلبك؟ فريق HeroKid يسعده مساعدتك في أي وقت.
                    </p>

                    <div class="space-y-6">
                        {{-- WhatsApp Card --}}
                        <div
                            class="flex items-center gap-5 p-6 bg-green-50 rounded-3xl border border-green-100 transition hover:shadow-lg group">
                            <div
                                class="w-14 h-14 bg-green-600 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-green-200 group-hover:scale-110 transition">
                                💬</div>
                            <div class="text-right">
                                <h4 class="font-bold text-slate-900">واتساب</h4>
                                <p class="text-slate-500 text-sm">رد سريع خلال ساعات العمل</p>
                                <a href="https://wa.me/{{ $settings["whatsapp"] ?? "+201112333646" }}" target="_blank"
                                    class="text-green-600 font-bold mt-1 block hover:underline">
                                    {{ $settings["whatsapp"] ?? "+201112333646" }}
                                </a>
                            </div>
                        </div>

                        {{-- Email Card --}}
                        <div
                            class="flex items-center gap-5 p-6 bg-indigo-50 rounded-3xl border border-indigo-100 transition hover:shadow-lg group">
                            <div
                                class="w-14 h-14 bg-indigo-600 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-indigo-200 group-hover:scale-110 transition">
                                📧</div>
                            <div class="text-right">
                                <h4 class="font-bold text-slate-900">البريد الإلكتروني</h4>
                                <p class="text-slate-500 text-sm">للاستفسارات الرسمية والطلبات الخاصة</p>
                                <a href="mailto:{{ $settings[" email"] ?? "hello@herokid.eg" }}"
                                    class="text-indigo-600 font-bold mt-1 block hover:underline">
                                    {{ $settings["email"] ?? "hello@herokid.eg" }}
                                </a>
                            </div>
                        </div>

                        {{-- Location Card --}}
                        <div
                            class="flex items-center gap-5 p-6 bg-slate-50 rounded-3xl border border-slate-100 transition hover:shadow-lg group">
                            <div
                                class="w-14 h-14 bg-slate-800 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-slate-200 group-hover:scale-110 transition">
                                📍</div>
                            <div class="text-right">
                                <h4 class="font-bold text-slate-900">المقر الرئيسي</h4>
                                <p class="text-slate-500 text-sm">المنصورة، شارع سامية الجمل</p>
                                <span class="text-slate-400 text-xs font-bold mt-1 block">نشحن لجميع المحافظات</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Right Side: Contact Form --}}
                <div class="relative mt-12 lg:mt-0">
                    <div class="absolute -inset-4 bg-indigo-100/50 rounded-[3rem] blur-2xl -z-10"></div>
                    <div class="bg-white rounded-[2.5rem] p-10 shadow-2xl border border-slate-50">
                        <h3
                            class="text-2xl font-black text-slate-900 mb-8 text-right underline decoration-indigo-200 decoration-8 underline-offset-[-2px]">
                            أرسل لنا رسالة</h3>

                        <form action="#" method="POST" class="space-y-5">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-right">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">الاسم <span
                                            class="text-red-500">*</span></label>
                                    <input type="text" placeholder="اسمك الكريم"
                                        class="w-full bg-slate-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-indigo-500 transition text-right">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">الموبايل <span
                                            class="text-red-500">*</span></label>
                                    <input type="text" placeholder="+20 1XX XXXX XXX"
                                        class="w-full bg-slate-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-indigo-500 transition text-right"
                                        dir="ltr">
                                </div>
                            </div>
                            <div class="text-right">
                                <label class="block text-sm font-bold text-slate-700 mb-2">البريد الإلكتروني <span
                                        class="text-red-500">*</span></label>
                                <input type="email" placeholder="example@email.com"
                                    class="w-full bg-slate-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-indigo-500 transition text-right"
                                    dir="ltr">
                            </div>
                            <div class="text-right">
                                <label class="block text-sm font-bold text-slate-700 mb-2">الرسالة <span
                                        class="text-red-500">*</span></label>
                                <textarea rows="4" placeholder="كيف يمكننا مساعدتك اليوم؟"
                                    class="w-full bg-slate-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-indigo-500 transition text-right"></textarea>
                            </div>
                            <button type="submit"
                                class="w-full bg-indigo-600 hover:bg-slate-950 text-white font-black py-5 rounded-2xl shadow-xl shadow-indigo-100 transition-all duration-300 hover:-translate-y-1 active:scale-95 flex items-center justify-center gap-3">
                                أرسل الرسالة الآن
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2.5"
                                    viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </section>

    {{-- FINAL CTA --}}
    <div class="py-24 relative overflow-hidden bg-gradient-to-br from-indigo-600 to-indigo-800">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute -top-20 -right-20 w-96 h-96 bg-white rounded-full"></div>
            <div class="absolute -bottom-20 -left-20 w-80 h-80 bg-white rounded-full"></div>
        </div>
        <div class="max-w-3xl mx-auto px-4 relative z-10 text-center">
            <h2 class="text-4xl md:text-5xl font-extrabold text-white mb-6 leading-tight">ابدأ رحلة طفلك اليوم 🚀</h2>
            <p class="text-indigo-100 text-xl mb-10 leading-relaxed opacity-90">انضم لأكثر من
                {{ $settings["happy_families"] ?? 100 }} عائلة تصنع السحر مع
                HeroKid.<br>قصتك المخصصة جاهزة خلال أيام.
            </p>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="{{ route('stories.index') }}"
                    class="bg-white text-indigo-600 font-extrabold py-5 px-12 rounded-2xl text-xl shadow-xl hover:shadow-2xl transition hover:-translate-y-1">اختر
                    قصتك الآن</a>
                <a href="{{ route('how-it-works') }}"
                    class="border-2 border-white/40 text-white font-bold py-5 px-10 rounded-2xl text-xl hover:bg-white/10 transition">كيف
                    يعمل؟</a>
            </div>
        </div>
    </div>

</x-front-layout>