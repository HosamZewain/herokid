<x-front-layout>

{{-- ══ Home page SEO ══ --}}
<x-slot name="pageTitle">قصص أطفال مخصصة تجعل طفلك بطل القصة بوجهه الحقيقي</x-slot>
<x-slot name="pageDescription">HeroKid — أول منصة في مصر لتحويل طفلك إلى بطل قصة مطبوعة بوجهه واسمه. اختر القصة، أرسل صورة طفلك، واستلم كتاباً فاخراً خلال {{ $settings['delivery_days'] ?? 5 }} أيام فقط.</x-slot>

@if($faqs->count())
@push('schema')
<script type="application/ld+json">
{
  "@@context": "https://schema.org",
  "@@type": "FAQPage",
  "mainEntity": [
    @foreach($faqs as $faq)
    {
      "@@type": "Question",
      "name": "{{ addslashes($faq->question) }}",
      "acceptedAnswer": {
        "@@type": "Answer",
        "text": "{{ addslashes($faq->answer) }}"
      }
    }{{ !$loop->last ? ',' : '' }}
    @endforeach
  ]
}
</script>
@endpush
@endif

    <style>
        /* Entry animations */
@verbatim
        @keyframes heroFadeUp {
            from { opacity: 0; transform: translateY(28px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes counterUp {
            from { opacity: 0; transform: scale(0.88); }
            to   { opacity: 1; transform: scale(1); }
        }
        .hero-text-anim { opacity:0; animation: heroFadeUp .9s ease forwards; }
        .hero-stat      { opacity:0; animation: counterUp .65s ease forwards; }

        /* Floating card */
        @keyframes h-float {
            0%,100% { transform: translate(-50%, 0)    rotate(0deg); }
            50%      { transform: translate(-50%, -14px) rotate(.8deg); }
        }
        .h-float { animation: h-float 5s ease-in-out infinite; }

        /* Confetti dots floating */
        @keyframes confetti-float {
            0%,100% { transform: translateY(0)    rotate(0deg);  }
            33%      { transform: translateY(-14px) rotate(6deg);  }
            66%      { transform: translateY(-7px)  rotate(-4deg); }
        }

        /* Pulsing dot badge */
        @keyframes dotBlink {
            0%,100% { opacity:1; transform:scale(1);   }
            50%      { opacity:.5; transform:scale(1.2); }
        }
        .dot-blink { animation: dotBlink 1.6s ease-in-out infinite; }

        /* Gentle spin for deco shapes */
        @keyframes slow-spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }

        /* Stat card hover lift */
        .stat-card { transition: transform .25s ease, box-shadow .25s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,0,0,.1); }
@endverbatim
    </style>

    {{-- ═══════════════════════════════════════════
         HERO — Happy & Colorful
    ═══════════════════════════════════════════ --}}
    <div class="relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(145deg, #fffbeb 0%, #fdf4ff 28%, #eff6ff 58%, #f0fdf4 100%);">

        {{-- ── BACKGROUND LAYER ── --}}
        <div class="absolute inset-0 pointer-events-none overflow-hidden">

            {{-- Soft ambient circles --}}
            <div class="absolute -top-40 -right-40 w-[600px] h-[600px] rounded-full opacity-40"
                style="background: radial-gradient(circle, #fde68a, transparent 68%);"></div>
            <div class="absolute -bottom-32 -left-32 w-[500px] h-[500px] rounded-full opacity-30"
                style="background: radial-gradient(circle, #fbcfe8, transparent 68%);"></div>
            <div class="absolute top-1/3 left-1/3 w-[350px] h-[350px] rounded-full opacity-20"
                style="background: radial-gradient(circle, #bfdbfe, transparent 68%);"></div>

            {{-- Dot grid --}}
            <div class="absolute inset-0 opacity-20"
                style="background-image: radial-gradient(circle, #a78bfa 1px, transparent 1px); background-size: 32px 32px;"></div>

            {{-- Large decorative spinning ring (top-left) --}}
            <div class="absolute -top-16 -left-16 w-64 h-64 rounded-full border-[16px] border-yellow-200/40 opacity-60"
                style="animation: slow-spin 25s linear infinite;"></div>
            <div class="absolute bottom-20 right-10 w-40 h-40 rounded-full border-[10px] border-pink-200/40 opacity-50"
                style="animation: slow-spin 18s linear infinite reverse;"></div>

            {{-- Confetti: circles --}}
            <div class="absolute w-4 h-4 rounded-full bg-yellow-400/70" style="top:11%;right:14%;animation:confetti-float 4s ease-in-out infinite;"></div>
            <div class="absolute w-2.5 h-2.5 rounded-full bg-pink-400/70" style="top:22%;right:6%;animation:confetti-float 5.2s ease-in-out infinite .5s;"></div>
            <div class="absolute w-3.5 h-3.5 rounded-full bg-sky-400/60" style="top:7%;left:18%;animation:confetti-float 6s ease-in-out infinite 1s;"></div>
            <div class="absolute w-2 h-2 rounded-full bg-emerald-400/70" style="top:38%;right:4%;animation:confetti-float 4.5s ease-in-out infinite 1.5s;"></div>
            <div class="absolute w-3 h-3 rounded-full bg-violet-400/60" style="bottom:28%;left:8%;animation:confetti-float 5.5s ease-in-out infinite .8s;"></div>
            <div class="absolute w-2 h-2 rounded-full bg-rose-400/60" style="bottom:18%;right:18%;animation:confetti-float 4s ease-in-out infinite 2s;"></div>
            <div class="absolute w-5 h-5 rounded-full bg-amber-300/40" style="top:58%;left:22%;animation:confetti-float 7s ease-in-out infinite .3s;"></div>
            <div class="absolute w-2 h-2 rounded-full bg-cyan-400/60" style="top:14%;left:38%;animation:confetti-float 5s ease-in-out infinite 1.2s;"></div>

            {{-- Confetti: diamonds --}}
            <div class="absolute w-3.5 h-3.5 bg-amber-300/50 rotate-45" style="top:43%;right:26%;animation:confetti-float 6s ease-in-out infinite 1.8s;"></div>
            <div class="absolute w-3 h-3 bg-violet-300/50 rotate-45" style="bottom:38%;left:28%;animation:confetti-float 5s ease-in-out infinite .4s;"></div>
            <div class="absolute w-2.5 h-2.5 bg-rose-300/50 rotate-12" style="top:68%;right:20%;animation:confetti-float 4.5s ease-in-out infinite 1.1s;"></div>
            <div class="absolute w-4 h-4 bg-sky-200/50 rotate-45" style="top:30%;left:12%;animation:confetti-float 6.5s ease-in-out infinite 2.3s;"></div>

            {{-- Confetti: stars --}}
            <svg class="absolute fill-current text-yellow-400/60" style="top:17%;right:23%;animation:confetti-float 5s ease-in-out infinite .7s;" width="18" height="18" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
            <svg class="absolute fill-current text-pink-400/50" style="bottom:33%;left:16%;animation:confetti-float 6s ease-in-out infinite 1.4s;" width="14" height="14" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
            <svg class="absolute fill-current text-violet-400/40" style="top:48%;right:10%;animation:confetti-float 4s ease-in-out infinite .9s;" width="16" height="16" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
            <svg class="absolute fill-current text-emerald-400/50" style="top:28%;left:6%;animation:confetti-float 5s ease-in-out infinite 2.1s;" width="12" height="12" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
        </div>

        {{-- SVG gradient defs --}}
        <svg width="0" height="0" class="absolute">
            <defs>
                <linearGradient id="heroGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                    <stop offset="0%"   style="stop-color:#f97316;stop-opacity:1"/>
                    <stop offset="45%"  style="stop-color:#ec4899;stop-opacity:1"/>
                    <stop offset="100%" style="stop-color:#8b5cf6;stop-opacity:1"/>
                </linearGradient>
            </defs>
        </svg>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-14 pb-0">

            {{-- ════ TOP: Centered headline block ════ --}}
            <div class="text-center mb-10 hero-text-anim" style="animation-delay:.05s">

                {{-- Eyebrow badge --}}
                <div class="inline-flex items-center gap-2.5 px-5 py-2.5 mb-8 bg-white/80 backdrop-blur-sm border border-amber-200 rounded-full shadow-lg shadow-amber-100/40">
                    <span class="text-lg leading-none">✨</span>
                    <span class="text-sm font-black text-amber-800">أول قصة أطفال بوجه طفلك الحقيقي في مصر</span>
                    <span class="text-lg leading-none">✨</span>
                </div>

                {{-- Mega headline --}}
                <h1 class="text-5xl sm:text-6xl lg:text-7xl font-extrabold leading-[1.1] mb-6 text-slate-900">
                    طفلك ليس قارئاً…<br>
                    <span class="relative inline-block mt-1">
                        <span class="text-transparent bg-clip-text" style="background-image:linear-gradient(135deg,#f97316,#ec4899,#8b5cf6);">
                            هو البطل الحقيقي!
                        </span>
                        <svg class="absolute -bottom-3 right-0 w-full" height="10" viewBox="0 0 500 10" preserveAspectRatio="none">
                            <path d="M0,8 Q125,1 250,6 Q375,11 500,4" fill="none" stroke="url(#heroGrad)" stroke-width="4" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <span class="inline-block ml-2 animate-bounce" style="animation-duration:2s">🌟</span>
                </h1>

                {{-- Subtitle --}}
                <p class="text-lg md:text-xl text-slate-600 max-w-2xl mx-auto leading-relaxed mb-8">
                    نحوّل خيال طفلك إلى كتاب مطبوع يحمل <strong class="text-slate-800">اسمه ووجهه</strong> في كل صفحة —
                    هدية تُخلَّد وذكرى تدوم، تُشحن لبابك خلال
                    <strong class="text-orange-600">{{ $settings["delivery_days"] ?? 5 }} أيام</strong>.
                </p>

                {{-- Colorful feature pills --}}
                <div class="flex flex-wrap gap-3 justify-center mb-10">
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-orange-50 text-orange-700 text-sm font-bold rounded-full border border-orange-200 shadow-sm">🎨 وجه طفلك في كل رسمة</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-pink-50 text-pink-700 text-sm font-bold rounded-full border border-pink-200 shadow-sm">📖 قصص بقيم تربوية</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-violet-50 text-violet-700 text-sm font-bold rounded-full border border-violet-200 shadow-sm">🚀 توصيل لبابك</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 text-emerald-700 text-sm font-bold rounded-full border border-emerald-200 shadow-sm">⏱ {{ $settings["delivery_days"] ?? 5 }} أيام فقط</span>
                    <span class="inline-flex items-center gap-2 px-4 py-2 bg-sky-50 text-sky-700 text-sm font-bold rounded-full border border-sky-200 shadow-sm">🌐 عربي وإنجليزي</span>
                </div>

                {{-- CTA buttons --}}
                <div class="flex flex-wrap gap-4 justify-center">
                    <a href="{{ route('stories.index') }}"
                        class="group inline-flex items-center gap-3 text-white font-black py-5 px-12 rounded-2xl shadow-2xl text-lg transition hover:-translate-y-1 active:scale-95"
                        style="background:linear-gradient(135deg,#f97316,#ec4899); box-shadow: 0 8px 30px rgba(249,115,22,.4);">
                        <span class="text-xl">📚</span>
                        <span>تصفح مكتبتنا الآن</span>
                        <svg class="w-5 h-5 transform group-hover:-translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                    <a href="{{ route('how-it-works') }}"
                        class="inline-flex items-center gap-2 bg-white/90 backdrop-blur-sm text-slate-700 font-bold py-5 px-10 rounded-2xl border-2 border-slate-200 hover:border-pink-300 hover:shadow-xl transition hover:-translate-y-1 text-lg">
                        كيف يعمل؟ 🎬
                    </a>
                </div>
            </div>

            {{-- ════ BOTTOM: Visual + Stats ════ --}}
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 items-end">

                {{-- ── Stats & Trust (2 cols) ── --}}
                <div class="lg:col-span-2 space-y-4 pb-8 order-2 lg:order-1 hero-text-anim" style="animation-delay:.2s">

                    {{-- 3 stat cards --}}
                    <div class="grid grid-cols-3 gap-3">
                        <div class="stat-card bg-white/85 backdrop-blur-sm rounded-2xl p-4 text-center shadow-md border border-white">
                            <div class="text-3xl mb-1">📚</div>
                            <p class="text-2xl font-black text-slate-900 leading-none">{{ \App\Models\Story::where('active',true)->count() ?: '+10' }}</p>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">قصة</p>
                        </div>
                        <div class="stat-card bg-white/85 backdrop-blur-sm rounded-2xl p-4 text-center shadow-md border border-white">
                            <div class="text-3xl mb-1">⭐</div>
                            <p class="text-2xl font-black text-slate-900 leading-none">{{ $settings["rating"] ?? 4.9 }}</p>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">تقييم</p>
                        </div>
                        <div class="stat-card bg-white/85 backdrop-blur-sm rounded-2xl p-4 text-center shadow-md border border-white">
                            <div class="text-3xl mb-1">👨‍👩‍👧</div>
                            <p class="text-2xl font-black text-slate-900 leading-none">+{{ $settings["happy_families"] ?? 100 }}</p>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mt-1">عائلة</p>
                        </div>
                    </div>

                    {{-- Trust / mini testimonial --}}
                    <div class="bg-white/85 backdrop-blur-sm rounded-2xl p-5 shadow-md border border-white text-right">
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex gap-0.5">
                                @for($i=0;$i<5;$i++)
                                    <svg class="w-4 h-4 text-amber-400 fill-current" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                @endfor
                            </div>
                            <div class="flex -space-x-2 rtl:space-x-reverse">
                                @foreach(['47','12','32','15','22'] as $av)
                                    <img src="https://i.pravatar.cc/100?img={{ $av }}" class="w-8 h-8 rounded-full border-2 border-white shadow object-cover" alt="">
                                @endforeach
                            </div>
                        </div>
                        <p class="text-slate-600 font-semibold text-sm leading-relaxed">"طفلي بقى يقرأها كل يوم! هدية لا تُنسى 🥹"</p>
                        <p class="text-slate-400 text-xs font-bold mt-1.5">— ثقة أكثر من +{{ $settings["happy_families"] ?? 100 }} عائلة سعيدة</p>
                    </div>

                    {{-- Price badge strip --}}
                    <div class="flex gap-3">
                        <div class="flex-1 rounded-2xl p-4 text-white text-center font-black shadow-lg" style="background:linear-gradient(135deg,#f97316,#ec4899);">
                            <p class="text-[10px] opacity-80 uppercase tracking-wider mb-0.5">ابتداءً من</p>
                            <p class="text-xl">{{ $settings["price_soft_cover"] ?? 99 }} ج.م</p>
                        </div>
                        <div class="flex-1 rounded-2xl p-4 text-white text-center font-black shadow-lg" style="background:linear-gradient(135deg,#8b5cf6,#3b82f6);">
                            <p class="text-[10px] opacity-80 uppercase tracking-wider mb-0.5">شحن خلال</p>
                            <p class="text-xl">{{ $settings["delivery_days"] ?? 5 }} أيام</p>
                        </div>
                    </div>

                </div>

                {{-- ── Story Cards Visual (3 cols) ── --}}
                <div class="lg:col-span-3 relative h-[300px] sm:h-[380px] md:h-[500px] order-1 lg:order-2">

                    {{-- Glow behind main card --}}
                    <div class="absolute pointer-events-none" style="top:40px;left:50%;transform:translateX(-50%);width:280px;height:360px;border-radius:2.5rem;background:radial-gradient(ellipse,rgba(249,115,22,.18),transparent 70%);filter:blur(40px);z-index:1;"></div>

                    {{-- MAIN CARD --}}
                    @php
                        $heroCard = $featuredStories->first();
                        $card2    = $featuredStories->skip(1)->first();
                        $card3    = $featuredStories->skip(2)->first();
                    @endphp
                    <div class="h-float absolute z-20" style="top:20px;left:50%;transform:translateX(-50%);">
                        <div class="w-[220px] rounded-[2rem] overflow-hidden bg-white" style="box-shadow:0 24px 64px rgba(249,115,22,.25),0 8px 24px rgba(0,0,0,.12);">
                            <div class="relative h-[280px] overflow-hidden">
                                @if($heroCard && $heroCard->cover_image)
                                    <img src="{{ $heroCard->cover_url }}" alt="{{ $heroCard->title }}" class="w-full h-full object-cover">
                                @else
                                    <img src="{{ $settings['img_hero_main'] ?? 'https://images.unsplash.com/photo-1446776811953-b23d57bd21aa?w=500&auto=format&fit=crop&q=80' }}" alt="HeroKid" class="w-full h-full object-cover">
                                @endif
                                <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(15,23,42,.85) 0%,transparent 52%);"></div>
                                <div class="absolute top-3 right-3">
                                    <span class="px-3 py-1 text-[10px] font-black rounded-full text-white shadow-lg" style="background:linear-gradient(135deg,#f97316,#ec4899);">الأكثر مبيعاً 🔥</span>
                                </div>
                                <div class="absolute bottom-0 right-0 left-0 p-4 text-right">
                                    @if($heroCard)
                                        <p class="text-orange-300 text-[9px] font-black uppercase tracking-widest mb-0.5">{{ $heroCard->categories->first()->name ?? 'مغامرة' }}</p>
                                        <h3 class="text-white font-black text-base leading-snug">{{ $heroCard->title }}</h3>
                                    @else
                                        <h3 class="text-white font-black text-base">قصة طفلك المخصصة</h3>
                                    @endif
                                </div>
                            </div>
                            @if($heroCard)
                            <div class="px-4 py-3 flex items-center justify-between bg-white">
                                <span class="font-black text-lg" style="color:#f97316;">{{ number_format($heroCard->price,0) }} <small class="text-slate-400 text-xs">ج.م</small></span>
                                <span class="text-[9px] font-black px-2.5 py-1.5 rounded-xl" style="background:#fff7ed;color:#ea580c;">{{ $heroCard->age_range }} سنة</span>
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- CARD 2 --}}
                    <div class="absolute top-10 right-4 lg:right-8 z-10 hero-stat" style="animation-delay:.6s">
                        <div class="w-[125px] rounded-[1.5rem] overflow-hidden bg-white transform rotate-[-8deg] hover:rotate-[-3deg] transition-transform duration-700 opacity-90" style="box-shadow:0 10px 35px rgba(236,72,153,.2);">
                            <div class="h-[80px] overflow-hidden bg-gradient-to-br from-pink-100 to-rose-100">
                                @if($card2 && $card2->cover_image)
                                    <img src="{{ $card2->cover_url }}" alt="{{ $card2->title }}" class="w-full h-full object-cover">
                                @else
                                    <img src="https://images.unsplash.com/photo-1448375240586-882707db888b?w=300&fit=crop" class="w-full h-full object-cover">
                                @endif
                            </div>
                            <div class="p-2.5 text-right">
                                <p class="text-[9px] font-black text-pink-500 truncate">{{ $card2 ? $card2->title : 'قصة مغامرات' }}</p>
                                @if($card2)<p class="text-[8px] text-slate-400 font-bold">{{ $card2->age_range }} سنة</p>@endif
                            </div>
                        </div>
                    </div>

                    {{-- CARD 3 --}}
                    <div class="absolute top-20 left-4 lg:left-8 z-10 hero-stat" style="animation-delay:.9s">
                        <div class="w-[115px] rounded-[1.5rem] overflow-hidden bg-white transform rotate-[7deg] hover:rotate-[2deg] transition-transform duration-700 opacity-80" style="box-shadow:0 10px 35px rgba(139,92,246,.2);">
                            <div class="h-[75px] overflow-hidden bg-gradient-to-br from-violet-100 to-indigo-100">
                                @if($card3 && $card3->cover_image)
                                    <img src="{{ $card3->cover_url }}" alt="{{ $card3->title }}" class="w-full h-full object-cover">
                                @else
                                    <img src="https://images.unsplash.com/photo-1490750967868-88df5691cc4a?w=300&fit=crop" class="w-full h-full object-cover">
                                @endif
                            </div>
                            <div class="p-2 text-right">
                                <p class="text-[8px] font-black text-violet-500 truncate">{{ $card3 ? $card3->title : 'قصة للجميع' }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- FLOATING: Quality badge --}}
                    <div class="absolute top-2 right-2 z-30 hero-stat" style="animation-delay:.55s">
                        <div class="bg-white/95 backdrop-blur-md p-2.5 rounded-2xl shadow-xl border border-orange-50 flex items-center gap-2">
                            <div class="w-8 h-8 rounded-xl flex items-center justify-center text-base shrink-0 bg-orange-50">✅</div>
                            <div>
                                <p class="text-[8px] text-slate-400 font-bold uppercase tracking-wider leading-none">جودة فاخرة</p>
                                <p class="text-[11px] font-black text-slate-800 leading-tight">ورق مقوى</p>
                            </div>
                        </div>
                    </div>

                    {{-- FLOATING: Shipping badge --}}
                    <div class="absolute bottom-14 left-2 z-30 hero-stat" style="animation-delay:.8s">
                        <div class="p-2.5 rounded-2xl shadow-xl flex items-center gap-2 text-white" style="background:linear-gradient(135deg,#10b981,#059669);">
                            <span class="text-lg leading-none">🚀</span>
                            <div>
                                <p class="text-[8px] text-white/70 font-bold uppercase tracking-wider leading-none">شحن سريع</p>
                                <p class="text-[11px] font-black leading-tight">{{ $settings["delivery_days"] ?? 5 }} أيام</p>
                            </div>
                        </div>
                    </div>

                    {{-- FLOATING: Gift/price badge --}}
                    <div class="absolute top-1/2 left-0 z-30 hero-stat" style="animation-delay:1s">
                        <div class="p-2.5 rounded-2xl shadow-xl flex items-center gap-2 text-white" style="background:linear-gradient(135deg,#8b5cf6,#ec4899);">
                            <span class="text-lg leading-none">🎁</span>
                            <div>
                                <p class="text-[8px] text-white/70 font-bold uppercase tracking-wider leading-none">ابتداءً من</p>
                                <p class="text-[11px] font-black leading-tight">{{ $settings["price_soft_cover"] ?? 99 }} ج.م</p>
                            </div>
                        </div>
                    </div>

                </div>

            </div>
        </div>

        {{-- Colorful wave divider --}}
        <div class="relative overflow-hidden leading-none mt-10" style="height:64px;">
            <svg viewBox="0 0 1440 64" fill="none" xmlns="http://www.w3.org/2000/svg"
                class="absolute bottom-0 w-full h-full" preserveAspectRatio="none">
                <path d="M0 64L60 56C120 48 240 32 360 26C480 20 600 22 720 28C840 34 960 44 1080 48C1200 52 1320 50 1380 48L1440 46V64H0Z" fill="white"/>
            </svg>
        </div>

    </div>

    {{-- FEATURED STORIES --}}
    <section class="py-20 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(160deg, #fffbeb 0%, #fef3c7 50%, #fde68a 100%);">
        <div class="absolute inset-0 pointer-events-none opacity-20"
            style="background-image: radial-gradient(circle, #f59e0b 1px, transparent 1px); background-size: 32px 32px;"></div>
        <div class="absolute -top-10 -left-10 w-52 h-52 rounded-full border-[14px] border-amber-200/50 pointer-events-none"></div>
        <div class="absolute -bottom-8 -right-8 w-40 h-40 rounded-full border-[10px] border-orange-200/50 pointer-events-none"></div>
        {{-- Small deco stars --}}
        <svg class="absolute top-12 right-20 text-amber-300/50 fill-current pointer-events-none" width="20" height="20" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>
        <svg class="absolute bottom-16 left-24 text-orange-300/40 fill-current pointer-events-none" width="16" height="16" viewBox="0 0 24 24"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17l-6.2 4.3 2.4-7.4L2 9.4h7.6z"/></svg>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            {{-- Section header --}}
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-end gap-6 mb-12">
                <div class="text-right">
                    <span class="inline-flex items-center gap-2 bg-amber-100 text-amber-800 font-black text-xs px-4 py-2 rounded-full border border-amber-300 mb-3">📚 مكتبة القصص</span>
                    <h2 class="text-4xl font-extrabold text-slate-900">قصص يعشقها الأطفال</h2>
                    <div class="w-24 h-1.5 mt-2 mb-3 rounded-full" style="background: linear-gradient(90deg, #f97316, #fbbf24);"></div>
                    <p class="text-slate-600">كل قصة تغرس قيمة وتصنع ذكرى. طفلك هو البطل الحقيقي في كل صفحة.</p>
                </div>
                <div class="flex flex-col items-end gap-3 flex-shrink-0">
                    {{-- Quick stats --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="flex items-center gap-1.5 bg-white/80 backdrop-blur-sm border border-amber-200 rounded-2xl px-3 py-2 shadow-sm">
                            <span class="text-base">📖</span>
                            <span class="text-xs font-black text-slate-700">{{ \App\Models\Story::where('active',true)->count() }}+ قصة</span>
                        </div>
                        <div class="flex items-center gap-1.5 bg-white/80 backdrop-blur-sm border border-orange-200 rounded-2xl px-3 py-2 shadow-sm">
                            <span class="text-base">🌐</span>
                            <span class="text-xs font-black text-slate-700">٢ لغة</span>
                        </div>
                        <div class="flex items-center gap-1.5 bg-white/80 backdrop-blur-sm border border-yellow-200 rounded-2xl px-3 py-2 shadow-sm">
                            <span class="text-base">🎯</span>
                            <span class="text-xs font-black text-slate-700">٣ فئات عمرية</span>
                        </div>
                    </div>
                    <a href="{{ route('stories.index') }}"
                        class="flex items-center gap-2 bg-white text-orange-600 font-bold px-6 py-3 rounded-xl border-2 border-orange-200 hover:bg-orange-600 hover:text-white hover:border-orange-600 transition-all duration-200 group shadow-md">
                        عرض الكل
                        <svg class="w-4 h-4 group-hover:-translate-x-1 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                </div>
            </div>

            {{-- 4-col × 2-row story grid --}}
            @php
                $cardAccents = [
                    ['bar' => 'from-orange-400 to-yellow-400',   'badge' => 'bg-orange-50 text-orange-700 border-orange-200',   'price' => 'text-orange-600',  'shadow' => 'hover:shadow-orange-200/60'],
                    ['bar' => 'from-pink-400 to-rose-500',       'badge' => 'bg-pink-50 text-pink-700 border-pink-200',         'price' => 'text-pink-600',    'shadow' => 'hover:shadow-pink-200/60'],
                    ['bar' => 'from-violet-500 to-indigo-500',   'badge' => 'bg-violet-50 text-violet-700 border-violet-200',   'price' => 'text-violet-600',  'shadow' => 'hover:shadow-violet-200/60'],
                    ['bar' => 'from-emerald-400 to-teal-500',    'badge' => 'bg-emerald-50 text-emerald-700 border-emerald-200','price' => 'text-emerald-600', 'shadow' => 'hover:shadow-emerald-200/60'],
                    ['bar' => 'from-sky-400 to-blue-500',        'badge' => 'bg-sky-50 text-sky-700 border-sky-200',            'price' => 'text-sky-600',     'shadow' => 'hover:shadow-sky-200/60'],
                    ['bar' => 'from-fuchsia-400 to-pink-500',    'badge' => 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200','price' => 'text-fuchsia-600', 'shadow' => 'hover:shadow-fuchsia-200/60'],
                    ['bar' => 'from-amber-400 to-orange-500',    'badge' => 'bg-amber-50 text-amber-700 border-amber-200',      'price' => 'text-amber-600',   'shadow' => 'hover:shadow-amber-200/60'],
                    ['bar' => 'from-cyan-400 to-sky-500',        'badge' => 'bg-cyan-50 text-cyan-700 border-cyan-200',         'price' => 'text-cyan-600',    'shadow' => 'hover:shadow-cyan-200/60'],
                ];
                $fallbackImgs = ['photo-1446776811953-b23d57bd21aa','photo-1518709268805-4e9042af9f23','photo-1448375240586-882707db888b','photo-1490750967868-88df5691cc4a','photo-1575361204480-aadea25e6e68','photo-1581091226825-a6a2a5aee158','photo-1543269865-cbf427effbad','photo-1524995997946-a1c2e315a42f'];
            @endphp

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5">
                @forelse($featuredStories as $story)
                    @php $accent = $cardAccents[$loop->index % 8]; @endphp
                    <div class="group bg-white rounded-[1.75rem] overflow-hidden hover:shadow-2xl {{ $accent['shadow'] }} transition-all duration-500 hover:-translate-y-2 flex flex-col border border-orange-100/80">
                        {{-- Colorful top stripe --}}
                        <div class="h-1.5 w-full bg-gradient-to-r {{ $accent['bar'] }}"></div>

                        {{-- Image --}}
                        <a href="{{ route('stories.show', $story->slug) }}" class="aspect-[4/3] overflow-hidden relative bg-amber-50 block">
                            @if($story->cover_image)
                                <img src="{{ $story->cover_url }}" alt="{{ $story->title }}"
                                    class="w-full h-full object-cover transition duration-700 group-hover:scale-110" loading="lazy">
                            @else
                                <img src="https://images.unsplash.com/{{ $fallbackImgs[$loop->index % count($fallbackImgs)] }}?w=500&auto=format&fit=crop&q=80"
                                    alt="{{ $story->title }}" class="w-full h-full object-cover transition duration-700 group-hover:scale-110" loading="lazy">
                            @endif

                            {{-- Age badge top-right --}}
                            <div class="absolute top-3 right-3">
                                <span class="bg-white/92 backdrop-blur-sm text-[10px] font-black px-2.5 py-1 rounded-full shadow border {{ $accent['badge'] }}">{{ $story->age_range }}</span>
                            </div>

                            {{-- NEW badge for first 2 stories --}}
                            @if($loop->index < 2)
                                <div class="absolute top-3 left-3">
                                    <span class="text-[10px] font-black px-2.5 py-1 rounded-full text-white shadow"
                                        style="background: linear-gradient(135deg, #f97316, #ec4899);">✨ جديد</span>
                                </div>
                            @endif

                            {{-- Overlay on hover --}}
                            <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition duration-300 flex items-end pb-4 justify-center">
                                <span class="text-white text-xs font-black bg-white/20 backdrop-blur-sm border border-white/30 px-4 py-1.5 rounded-full">
                                    🔍 معاينة سريعة
                                </span>
                            </div>
                        </a>

                        {{-- Card body --}}
                        <div class="p-3 sm:p-4 flex flex-col flex-grow">
                            {{-- Category tags (hidden on mobile to save space) --}}
                            @if($story->categories->count())
                                <div class="hidden sm:flex flex-wrap gap-1 mb-2">
                                    @foreach($story->categories->take(2) as $cat)
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500">{{ $cat->name }}</span>
                                    @endforeach
                                </div>
                            @endif

                            <h3 class="text-[13px] sm:text-[15px] font-extrabold text-slate-900 mb-1 line-clamp-2 leading-snug">
                                <a href="{{ route('stories.show', $story->slug) }}" class="hover:text-indigo-600 transition-colors">{{ $story->title }}</a>
                            </h3>
                            <p class="hidden sm:block text-slate-400 text-xs leading-relaxed mb-3 flex-grow line-clamp-2">{{ $story->short_desc }}</p>

                            @if($story->lesson_value)
                                <div class="hidden sm:block mb-3">
                                    <span class="text-[10px] font-bold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200">💡 {{ $story->lesson_value }}</span>
                                </div>
                            @endif

                            {{-- Price + CTA --}}
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
                @empty
                    <div class="col-span-4 text-center py-20">
                        <div class="w-24 h-24 bg-amber-100 rounded-3xl flex items-center justify-center text-5xl mx-auto mb-5">📚</div>
                        <p class="text-slate-500 font-bold text-lg">جاري إضافة قصص رائعة قريباً!</p>
                        <p class="text-slate-400 text-sm mt-1">ترقّبوا مكتبتنا المتنوعة من القصص الشخصية</p>
                    </div>
                @endforelse
            </div>

            {{-- Bottom CTA --}}
            @if($featuredStories->count() >= 8)
                <div class="text-center mt-10">
                    <a href="{{ route('stories.index') }}"
                        class="inline-flex items-center gap-3 text-white font-black py-4 px-10 rounded-2xl shadow-xl transition hover:-translate-y-1 hover:shadow-2xl"
                        style="background: linear-gradient(135deg, #f97316, #ec4899); box-shadow: 0 8px 25px rgba(249,115,22,.35);">
                        <span>استعرض المكتبة الكاملة</span>
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                </div>
            @endif
        </div>
    </section>

    {{-- HOW IT WORKS --}}
    <section id="how-it-works" class="py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(145deg, #0f0a1e 0%, #1e1b4b 50%, #2e1065 100%);">
        {{-- Star dot grid --}}
        <div class="absolute inset-0 pointer-events-none"
            style="background-image: radial-gradient(circle, rgba(167,139,250,.15) 1px, transparent 1px); background-size: 24px 24px;"></div>
        {{-- Ambient glow --}}
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[600px] h-[280px] rounded-full opacity-20 blur-3xl pointer-events-none"
            style="background: radial-gradient(circle, #7c3aed, transparent 70%);"></div>
        <div class="absolute bottom-0 right-0 w-80 h-80 rounded-full opacity-10 blur-3xl pointer-events-none"
            style="background: radial-gradient(circle, #ec4899, transparent 70%);"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16">
                <span class="inline-flex items-center gap-2 bg-violet-500/20 text-violet-300 font-black text-xs px-4 py-2 rounded-full border border-violet-500/30 mb-4">⚡ العملية</span>
                <h2 class="text-4xl font-extrabold text-white mt-1 mb-2">٣ خطوات وقصتك في الطريق!</h2>
                <div class="w-20 h-1 mx-auto rounded-full mb-4" style="background: linear-gradient(90deg, #a78bfa, #ec4899);"></div>
                <p class="text-lg text-violet-300">بضع دقائق منك، وكتاب لا يُنسى لهم.</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative">
                {{-- Connecting gradient line --}}
                <div class="hidden md:block absolute top-[6.5rem] right-[16.5%] left-[16.5%] h-0.5 opacity-30"
                    style="background: linear-gradient(90deg, #7c3aed, #ec4899, #7c3aed);"></div>

                {{-- Step 1 --}}
                <div class="group relative text-center">
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-5 shadow-2xl shadow-violet-900/60 border border-violet-500/20">
                        <img src="{{ $settings['img_home_step1'] ?? 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=600&auto=format&fit=crop&q=80' }}"
                            alt="اختر القصة" class="w-full h-full object-cover transition duration-700 group-hover:scale-105 opacity-55" loading="lazy">
                        <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(109,40,217,.92) 0%, rgba(109,40,217,.4) 60%, transparent 100%);"></div>
                        <div class="absolute top-3 right-3 w-11 h-11 rounded-2xl flex items-center justify-center text-white font-extrabold text-xl shadow-lg"
                            style="background: linear-gradient(135deg, #f97316, #fbbf24); box-shadow: 0 4px 15px rgba(249,115,22,.5);">١</div>
                        <div class="absolute bottom-4 inset-x-0 text-center">
                            <p class="text-white font-extrabold text-lg drop-shadow-lg">اختر القصة</p>
                        </div>
                    </div>
                    <div class="bg-violet-500/10 backdrop-blur-sm border border-violet-500/20 rounded-2xl p-5 text-right">
                        <span class="inline-block bg-violet-500/20 text-violet-300 font-black text-xs px-3 py-1 rounded-full mb-2">خطوة ١</span>
                        <p class="text-violet-200 leading-relaxed text-sm">تصفح مكتبتنا واختر القصة التي تناسب عمر طفلك واهتماماته.</p>
                    </div>
                </div>

                {{-- Step 2 --}}
                <div class="group relative text-center">
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-5 shadow-2xl shadow-pink-900/60 border border-pink-500/20">
                        <img src="{{ $settings['img_home_step2'] ?? 'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=600&auto=format&fit=crop&q=80' }}"
                            alt="خصص وأرسل" class="w-full h-full object-cover transition duration-700 group-hover:scale-105 opacity-55" loading="lazy">
                        <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(190,24,93,.92) 0%, rgba(190,24,93,.4) 60%, transparent 100%);"></div>
                        <div class="absolute top-3 right-3 w-11 h-11 rounded-2xl flex items-center justify-center text-white font-extrabold text-xl shadow-lg"
                            style="background: linear-gradient(135deg, #ec4899, #f43f5e); box-shadow: 0 4px 15px rgba(236,72,153,.5);">٢</div>
                        <div class="absolute bottom-4 inset-x-0 text-center">
                            <p class="text-white font-extrabold text-lg drop-shadow-lg">خصّص وأرسل</p>
                        </div>
                    </div>
                    <div class="bg-pink-500/10 backdrop-blur-sm border border-pink-500/20 rounded-2xl p-5 text-right">
                        <span class="inline-block bg-pink-500/20 text-pink-300 font-black text-xs px-3 py-1 rounded-full mb-2">خطوة ٢</span>
                        <p class="text-pink-200 leading-relaxed text-sm">أضف اسم طفلك وارفع صورته. نحن نحوله إلى بطل الأحداث.</p>
                    </div>
                </div>

                {{-- Step 3 --}}
                <div class="group relative text-center">
                    <div class="relative rounded-3xl overflow-hidden h-52 mb-5 shadow-2xl shadow-amber-900/60 border border-amber-500/20">
                        <img src="{{ $settings['img_home_step3'] ?? 'https://images.unsplash.com/photo-1513885535751-8b9238bd345a?w=600&auto=format&fit=crop&q=80' }}"
                            alt="استلم الكتاب" class="w-full h-full object-cover transition duration-700 group-hover:scale-105 opacity-55" loading="lazy">
                        <div class="absolute inset-0" style="background: linear-gradient(to top, rgba(161,90,0,.92) 0%, rgba(161,90,0,.4) 60%, transparent 100%);"></div>
                        <div class="absolute top-3 right-3 w-11 h-11 rounded-2xl flex items-center justify-center text-white font-extrabold text-xl shadow-lg"
                            style="background: linear-gradient(135deg, #f59e0b, #10b981); box-shadow: 0 4px 15px rgba(245,158,11,.5);">٣</div>
                        <div class="absolute bottom-4 inset-x-0 text-center">
                            <p class="text-white font-extrabold text-lg drop-shadow-lg">استلم الكتاب</p>
                        </div>
                    </div>
                    <div class="bg-amber-500/10 backdrop-blur-sm border border-amber-500/20 rounded-2xl p-5 text-right">
                        <span class="inline-block bg-amber-500/20 text-amber-300 font-black text-xs px-3 py-1 rounded-full mb-2">خطوة ٣</span>
                        <p class="text-amber-200 leading-relaxed text-sm">نطبع ونشحن إلى بابك مباشرة خلال {{ $settings["delivery_days"] ?? 5 }} أيام.</p>
                    </div>
                </div>
            </div>

            <div class="text-center mt-14">
                <a href="{{ route('how-it-works') }}"
                    class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm text-white font-bold border border-white/20 py-3 px-8 rounded-xl hover:bg-white/20 transition">
                    اعرف أكثر عن العملية
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                </a>
            </div>
        </div>
    </section>

    {{-- WHY PARENTS LOVE IT --}}
    <section class="py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(155deg, #ecfdf5 0%, #d1fae5 35%, #cffafe 70%, #e0f2fe 100%);">
        <div class="absolute inset-0 pointer-events-none opacity-20"
            style="background-image: radial-gradient(circle, #10b981 1px, transparent 1px); background-size: 36px 36px;"></div>
        <div class="absolute -top-16 -left-16 w-56 h-56 rounded-full border-[14px] border-emerald-200/50 pointer-events-none"></div>
        <div class="absolute -bottom-12 -right-12 w-44 h-44 rounded-full border-[10px] border-teal-200/50 pointer-events-none"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
                <div class="text-right order-2 lg:order-1">
                    <span class="inline-flex items-center gap-2 bg-emerald-100 text-emerald-800 font-black text-xs px-4 py-2 rounded-full border border-emerald-300 mb-4">💚 لماذا HeroKid؟</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2 leading-snug">لأن طفلك يستحق<br>أكثر من مجرد كتاب.</h2>
                    <div class="w-24 h-1.5 mb-8 rounded-full" style="background: linear-gradient(90deg, #10b981, #06b6d4);"></div>
                    <div class="space-y-4">
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #f97316, #fbbf24);">🎨</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">وجه طفلك في الرسومات</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">رسومات مخصصة بالذكاء الاصطناعي تضع وجه طفلك في كل مشهد.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #ec4899, #f43f5e);">📖</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">قصص ذات معنى</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">كل قصة تحمل درساً تربوياً أصيلاً يترسخ في ذهن طفلك.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #10b981, #06b6d4);">🛡️</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">خصوصية محمية</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">صور طفلك تُستخدم فقط لقصته ثم تُحذف تلقائياً بعد اكتمال الطلب.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 bg-white/70 backdrop-blur-sm rounded-2xl p-5 border border-emerald-100 hover:shadow-lg hover:shadow-emerald-100/60 transition">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0 text-2xl shadow-md"
                                style="background: linear-gradient(135deg, #8b5cf6, #ec4899);">🎁</div>
                            <div>
                                <h3 class="font-bold text-slate-900 text-lg mb-1">هدية مثالية للمناسبات</h3>
                                <p class="text-slate-500 text-sm leading-relaxed">عيد ميلاد، نهاية سنة دراسية — كتاب شخصي لا يُنسى.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 order-1 lg:order-2">
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-emerald-200/50 group">
                        <img src="{{ $settings['img_stat_books'] ?? 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=500&auto=format&fit=crop&q=80' }}"
                            alt="قصص" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(4,120,87,.92) 0%,rgba(4,120,87,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">+25</p>
                            <p class="text-emerald-200 text-sm mt-0.5">قصص متاحة</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">📚</div>
                    </div>
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-teal-200/50 group">
                        <img src="{{ $settings['img_stat_rating'] ?? 'https://images.unsplash.com/photo-1543269865-cbf427effbad?w=500&auto=format&fit=crop&q=80' }}"
                            alt="تقييم" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(14,116,144,.92) 0%,rgba(14,116,144,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">{{ $settings["rating"] ?? 4.8 }} ⭐</p>
                            <p class="text-cyan-200 text-sm mt-0.5">متوسط التقييم</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">⭐</div>
                    </div>
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-emerald-200/50 group">
                        <img src="{{ $settings['img_stat_family'] ?? 'https://images.unsplash.com/photo-1511895426328-dc8714191011?w=500&auto=format&fit=crop&q=80' }}"
                            alt="عائلات سعيدة" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(5,150,105,.92) 0%,rgba(5,150,105,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">+{{ $settings["happy_families"] ?? 100 }}</p>
                            <p class="text-emerald-200 text-sm mt-0.5">عائلة سعيدة</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">👨‍👩‍👧</div>
                    </div>
                    <div class="relative rounded-3xl overflow-hidden aspect-square shadow-xl shadow-teal-200/50 group">
                        <img src="{{ $settings['img_stat_delivery'] ?? 'https://images.unsplash.com/photo-1619454016518-697bc231e7cb?w=500&auto=format&fit=crop&q=80' }}"
                            alt="توصيل" class="w-full h-full object-cover transition duration-500 group-hover:scale-105" loading="lazy">
                        <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(8,145,178,.92) 0%,rgba(8,145,178,.3) 60%,transparent 100%);"></div>
                        <div class="absolute inset-0 flex flex-col items-center justify-end pb-6 text-center">
                            <p class="font-extrabold text-3xl text-white">{{ $settings["delivery_days"] ?? 5 }} يوم</p>
                            <p class="text-cyan-200 text-sm mt-0.5">متوسط التوصيل</p>
                        </div>
                        <div class="absolute top-3 right-3 w-9 h-9 bg-white/20 backdrop-blur-sm rounded-xl flex items-center justify-center text-xl">🚀</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- TESTIMONIALS (dynamic from DB) --}}
    @if($testimonials->count())
        <section class="py-24 relative overflow-hidden" dir="rtl"
            style="background: linear-gradient(155deg, #fff1f2 0%, #fce7f3 45%, #fdf4ff 100%);">
            <div class="absolute inset-0 pointer-events-none opacity-20"
                style="background-image: radial-gradient(circle, #f43f5e 1px, transparent 1px); background-size: 30px 30px;"></div>
            {{-- Large decorative quote --}}
            <div class="absolute top-4 right-12 text-[160px] leading-none font-serif text-rose-100/70 select-none pointer-events-none" aria-hidden="true">❝</div>
            <div class="absolute bottom-4 left-12 text-[120px] leading-none font-serif text-pink-100/60 select-none pointer-events-none" aria-hidden="true">❞</div>

            <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-2xl mx-auto mb-16">
                    <span class="inline-flex items-center gap-2 bg-rose-100 text-rose-700 font-black text-xs px-4 py-2 rounded-full border border-rose-200 mb-4">💬 آراء العملاء</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2">ماذا يقول الآباء؟</h2>
                    <div class="w-20 h-1.5 mx-auto rounded-full mb-4" style="background: linear-gradient(90deg, #f43f5e, #ec4899, #a855f7);"></div>
                    <p class="text-lg text-slate-500">حكايات حقيقية من عائلات أصبح أطفالها أبطالاً.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 text-right">
                    @foreach($testimonials->take(3) as $testimonial)
                        <div class="bg-white rounded-2xl p-8 shadow-lg shadow-rose-100/60 border border-rose-100 hover:shadow-xl hover:shadow-rose-200/50 hover:-translate-y-1 transition-all duration-300 flex flex-col relative overflow-hidden">
                            <div class="absolute top-0 inset-x-0 h-1.5 rounded-t-2xl" style="background: linear-gradient(90deg, #f43f5e, #ec4899, #a855f7);"></div>
                            <div class="flex items-center gap-0.5 mb-5 justify-end text-yellow-400 text-lg">
                                @for($i = 0; $i < $testimonial->rating; $i++)★@endfor
                                @for($i = $testimonial->rating; $i < 5; $i++)<span class="text-slate-200">★</span>@endfor
                            </div>
                            <p class="text-slate-600 leading-relaxed mb-6 text-sm flex-grow">"{{ $testimonial->review_text ?? $testimonial->message }}"</p>
                            <div class="flex items-center gap-3 pt-4 border-t border-rose-50 justify-end">
                                <div class="text-right">
                                    <p class="font-bold text-slate-900 text-sm">
                                        {{ $testimonial->reviewer_name ?? $testimonial->name }}
                                        @if($testimonial->reviewer_location)
                                            <span class="text-slate-400 font-normal"> — {{ $testimonial->reviewer_location }}</span>
                                        @endif
                                    </p>
                                    <p class="text-[10px] text-rose-400 font-black uppercase tracking-wider">قائد المغامرة</p>
                                </div>
                                @if($testimonial->reviewer_avatar || $testimonial->image)
                                    <img src="{{ $testimonial->reviewer_avatar ?? Storage::url($testimonial->image) }}"
                                        alt="{{ $testimonial->reviewer_name ?? $testimonial->name }}"
                                        class="w-11 h-11 rounded-full object-cover border-2 border-rose-200 flex-shrink-0">
                                @else
                                    <div class="w-11 h-11 rounded-full flex items-center justify-center text-white font-extrabold text-sm flex-shrink-0"
                                        style="background: linear-gradient(135deg, #f43f5e, #ec4899);">
                                        {{ mb_substr($testimonial->reviewer_name ?? $testimonial->name, 0, 1) }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- PRICING TEASER --}}
    <section class="py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(145deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);">
        <div class="absolute inset-0 pointer-events-none opacity-10"
            style="background-image: radial-gradient(circle, #818cf8 1px, transparent 1px); background-size: 28px 28px;"></div>
        <div class="absolute -top-10 left-1/2 -translate-x-1/2 w-[500px] h-56 blur-3xl opacity-20 rounded-full pointer-events-none"
            style="background: radial-gradient(circle, #6366f1, transparent);"></div>
        <div class="absolute bottom-0 left-0 w-80 h-80 blur-3xl opacity-10 rounded-full pointer-events-none"
            style="background: radial-gradient(circle, #ec4899, transparent);"></div>

        <div class="relative z-10 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-14">
                <span class="inline-flex items-center gap-2 bg-indigo-500/20 text-indigo-300 font-black text-xs px-4 py-2 rounded-full border border-indigo-500/30 mb-4">💎 الأسعار</span>
                <h2 class="text-4xl font-extrabold text-white mt-1 mb-2">سعر واحد، قيمة لا تُقدَّر</h2>
                <div class="w-20 h-1.5 mx-auto rounded-full mb-4" style="background: linear-gradient(90deg, #6366f1, #a855f7, #ec4899);"></div>
                <p class="text-lg text-indigo-300">لا رسوم خفية. تدفع مرة واحدة وتحصل على ذكرى للأبد.</p>
            </div>

            @if(isset($packages) && $packages->count())
            @php $colClass = $packages->count() >= 3 ? 'md:grid-cols-3' : ($packages->count() == 2 ? 'md:grid-cols-2' : 'md:grid-cols-1 max-w-sm mx-auto'); @endphp
            <div class="grid grid-cols-1 {{ $colClass }} gap-8">
                @foreach($packages as $pkg)
                    @if($pkg->is_featured)
                    {{-- Featured: gradient card --}}
                    <div class="relative rounded-3xl p-6 md:p-10 text-right overflow-hidden"
                        style="background: linear-gradient(135deg, #f97316, #ec4899, #8b5cf6); box-shadow: 0 20px 60px rgba(139,92,246,.4);">
                        @if($pkg->badge)
                        <div class="absolute -top-3 left-1/2 -translate-x-1/2 z-10">
                            <span class="bg-yellow-400 text-yellow-900 text-xs font-extrabold px-4 py-1.5 rounded-full shadow-lg">⭐ {{ $pkg->badge }}</span>
                        </div>
                        @endif
                        <div class="absolute inset-0 opacity-10" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 18px 18px;"></div>
                        <div class="relative z-10">
                            <h3 class="text-xl font-bold text-white mb-1">{{ $pkg->name }}</h3>
                            @if($pkg->subtitle)<p class="text-white/70 text-sm mb-5">{{ $pkg->subtitle }}</p>@else<div class="mb-5"></div>@endif
                            <div class="flex items-end gap-2 mb-6 justify-end">
                                <span class="text-white/70 text-xl">{{ $pkg->currency }}</span>
                                <span class="text-5xl font-extrabold text-white">{{ number_format($pkg->price, 0) }}</span>
                            </div>
                            @if($pkg->features && count($pkg->features))
                            <ul class="space-y-3 text-sm text-white/90 mb-8">
                                @foreach($pkg->features as $feature)
                                <li class="flex items-center gap-2 justify-end">
                                    <span>{{ $feature }}</span>
                                    <span class="w-5 h-5 rounded-full bg-white/20 text-white flex items-center justify-center text-xs flex-shrink-0">✓</span>
                                </li>
                                @endforeach
                            </ul>
                            @endif
                            <a href="{{ route('stories.index') }}" class="block text-center bg-white font-extrabold py-3 rounded-xl hover:shadow-2xl hover:scale-105 transition" style="color: #7c3aed;">{{ $pkg->button_text }}</a>
                        </div>
                    </div>
                    @else
                    {{-- Standard card --}}
                    <div class="bg-white/5 backdrop-blur-sm rounded-3xl p-6 md:p-10 border border-white/10 text-right hover:bg-white/8 hover:border-white/20 transition">
                        @if($pkg->badge)
                        <span class="inline-block bg-indigo-500/20 text-indigo-300 text-xs font-bold px-3 py-1 rounded-full border border-indigo-500/30 mb-3">{{ $pkg->badge }}</span>
                        @endif
                        <h3 class="text-xl font-bold text-white mb-1">{{ $pkg->name }}</h3>
                        @if($pkg->subtitle)<p class="text-slate-400 text-sm mb-5">{{ $pkg->subtitle }}</p>@else<div class="mb-5"></div>@endif
                        <div class="flex items-end gap-2 mb-6 justify-end">
                            <span class="text-slate-400 text-xl">{{ $pkg->currency }}</span>
                            <span class="text-5xl font-extrabold text-white">{{ number_format($pkg->price, 0) }}</span>
                        </div>
                        @if($pkg->features && count($pkg->features))
                        <ul class="space-y-3 text-sm text-slate-300 mb-8">
                            @foreach($pkg->features as $feature)
                            <li class="flex items-center gap-2 justify-end">
                                <span>{{ $feature }}</span>
                                <span class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-xs flex-shrink-0">✓</span>
                            </li>
                            @endforeach
                        </ul>
                        @endif
                        <a href="{{ route('stories.index') }}" class="block text-center bg-white/10 hover:bg-white/20 text-white font-bold py-3 rounded-xl transition border border-white/20">{{ $pkg->button_text }}</a>
                    </div>
                    @endif
                @endforeach
            </div>
            @endif

            <div class="text-center mt-8">
                <a href="{{ route('pricing') }}" class="text-indigo-300 font-bold hover:text-indigo-100 transition text-sm">عرض تفاصيل كاملة عن الأسعار ←</a>
            </div>
        </div>
    </section>

    {{-- FAQ SNIPPET --}}
    @if($faqs->count())
        <section class="py-24 relative overflow-hidden" dir="rtl"
            style="background: linear-gradient(155deg, #fefce8 0%, #fef9c3 40%, #fef3c7 100%);">
            <div class="absolute inset-0 pointer-events-none opacity-20"
                style="background-image: radial-gradient(circle, #ca8a04 1px, transparent 1px); background-size: 28px 28px;"></div>
            <div class="absolute top-8 left-16 w-24 h-24 rounded-full border-4 border-yellow-300/50 pointer-events-none"></div>
            <div class="absolute bottom-10 right-12 w-16 h-16 rounded-full border-4 border-amber-300/50 pointer-events-none"></div>
            <div class="absolute top-1/2 -translate-y-1/2 right-4 w-10 h-10 bg-amber-200/40 rotate-45 rounded-md pointer-events-none"></div>

            <div class="relative z-10 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <span class="inline-flex items-center gap-2 bg-amber-100 text-amber-800 font-black text-xs px-4 py-2 rounded-full border border-amber-300 mb-4">❓ الأسئلة الشائعة</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2">أسئلة يطرحها الآباء دائماً</h2>
                    <div class="w-20 h-1.5 mx-auto rounded-full" style="background: linear-gradient(90deg, #f59e0b, #f97316);"></div>
                </div>
                <div class="space-y-4" x-data="{}">
                    @foreach($faqs as $faq)
                        <div class="bg-white border border-amber-100 rounded-2xl overflow-hidden shadow-sm shadow-amber-100/50 hover:shadow-md hover:shadow-amber-200/40 transition" x-data="{ open: false }">
                            <button @click="open = !open"
                                class="w-full text-right px-6 py-5 font-bold text-slate-900 flex justify-between items-center hover:bg-amber-50/50 transition">
                                <span>{{ $faq->question }}</span>
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 transition-all duration-200"
                                    :class="open ? 'bg-amber-500 text-white' : 'bg-amber-100 text-amber-600'">
                                    <svg class="w-4 h-4 transform transition-transform duration-200" :class="{'rotate-180': open}"
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </div>
                            </button>
                            <div x-show="open" x-transition
                                class="px-6 pb-5 pt-2 text-slate-600 border-t border-amber-50 text-sm leading-relaxed text-right"
                                style="display:none">{{ $faq->answer }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="text-center mt-10">
                    <a href="{{ route('faq') }}"
                        class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white font-bold py-3 px-8 rounded-xl hover:shadow-lg hover:shadow-amber-200 transition">
                        عرض جميع الأسئلة
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                        </svg>
                    </a>
                </div>
            </div>
        </section>
    @endif

    {{-- CONTACT US SECTION --}}
    <section id="contact-us" class="py-16 md:py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(155deg, #f0f9ff 0%, #e0f2fe 40%, #eff6ff 100%);">
        <div class="absolute inset-0 pointer-events-none opacity-20"
            style="background-image: radial-gradient(circle, #0ea5e9 1px, transparent 1px); background-size: 32px 32px;"></div>
        <div class="absolute -top-16 -right-16 w-64 h-64 rounded-full border-[14px] border-sky-200/50 pointer-events-none"></div>
        <div class="absolute -bottom-12 -left-12 w-48 h-48 rounded-full border-[10px] border-blue-200/50 pointer-events-none"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">

                {{-- Left Side: Contact Info --}}
                <div class="text-right">
                    <span class="inline-flex items-center gap-2 bg-sky-100 text-sky-700 font-black text-xs px-4 py-2 rounded-full border border-sky-200 mb-4">📨 تواصل معنا</span>
                    <h2 class="text-4xl font-extrabold text-slate-900 mt-1 mb-2">نحن هنا للإجابة على استفساراتك</h2>
                    <div class="w-24 h-1.5 mb-6 rounded-full" style="background: linear-gradient(90deg, #0ea5e9, #6366f1);"></div>
                    <p class="text-lg text-slate-500 mb-10 leading-relaxed">
                        هل لديك سؤال حول قصة معينة؟ أو استفسار عن حالة طلبك؟ فريق HeroKid يسعده مساعدتك في أي وقت.
                    </p>

                    <div class="space-y-5">
                        <div class="flex items-center gap-5 p-6 bg-white/80 backdrop-blur-sm rounded-3xl border border-green-100 border-r-4 border-r-green-500 shadow-sm hover:shadow-md hover:shadow-green-100/50 transition group">
                            <div class="w-14 h-14 bg-green-500 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-green-200 group-hover:scale-110 transition flex-shrink-0">💬</div>
                            <div class="text-right">
                                <h4 class="font-bold text-slate-900">واتساب</h4>
                                <p class="text-slate-500 text-sm">رد سريع خلال ساعات العمل</p>
                                <a href="{{ $settings['whatsapp_url'] ?? '#' }}" target="_blank"
                                    class="text-green-600 font-bold mt-1 block hover:underline">{{ $settings['whatsapp_number'] ?? '' }}</a>
                            </div>
                        </div>

                        <div class="flex items-center gap-5 p-6 bg-white/80 backdrop-blur-sm rounded-3xl border border-indigo-100 border-r-4 border-r-indigo-500 shadow-sm hover:shadow-md hover:shadow-indigo-100/50 transition group">
                            <div class="w-14 h-14 bg-indigo-500 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-indigo-200 group-hover:scale-110 transition flex-shrink-0">📧</div>
                            <div class="text-right">
                                <h4 class="font-bold text-slate-900">البريد الإلكتروني</h4>
                                <p class="text-slate-500 text-sm">للاستفسارات الرسمية والطلبات الخاصة</p>
                                <a href="mailto:{{ $settings['site_email'] ?? '' }}"
                                    class="text-indigo-600 font-bold mt-1 block hover:underline">{{ $settings['site_email'] ?? '' }}</a>
                            </div>
                        </div>

                        <div class="flex items-center gap-5 p-6 bg-white/80 backdrop-blur-sm rounded-3xl border border-sky-100 border-r-4 border-r-sky-500 shadow-sm hover:shadow-md hover:shadow-sky-100/50 transition group">
                            <div class="w-14 h-14 bg-sky-500 text-white rounded-2xl flex items-center justify-center text-2xl shadow-lg shadow-sky-200 group-hover:scale-110 transition flex-shrink-0">📍</div>
                            <div class="text-right">
                                <h4 class="font-bold text-slate-900">المقر الرئيسي</h4>
                                <p class="text-slate-500 text-sm">{{ $settings['address_city'] ?? '' }}{{ !empty($settings['address_street']) ? '، ' . $settings['address_street'] : '' }}</p>
                                <span class="text-slate-400 text-xs font-bold mt-1 block">نشحن لجميع المحافظات</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Right Side: Contact Form --}}
                <div class="relative mt-12 lg:mt-0">
                    <div class="absolute -inset-4 rounded-[3rem] blur-2xl -z-10 opacity-60"
                        style="background: linear-gradient(135deg, #bae6fd, #c7d2fe);"></div>
                    <div class="bg-white rounded-[2rem] p-6 md:p-10 shadow-2xl shadow-sky-200/40 border border-sky-50">
                        <h3 class="text-2xl font-black text-slate-900 mb-8 text-right">
                            أرسل لنا رسالة <span class="text-sky-500">✉️</span>
                        </h3>

                        <form action="{{ route('contact.submit') }}" method="POST" class="space-y-5">
                            @csrf
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-right">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">الاسم <span class="text-red-500">*</span></label>
                                    <input type="text" name="name" placeholder="اسمك الكريم"
                                        class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" required>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-2">الموبايل <span class="text-red-500">*</span></label>
                                    <input type="text" name="phone" placeholder="+20 1XX XXXX XXX"
                                        class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" dir="ltr">
                                </div>
                            </div>
                            <div class="text-right">
                                <label class="block text-sm font-bold text-slate-700 mb-2">البريد الإلكتروني <span class="text-red-500">*</span></label>
                                <input type="email" name="email" placeholder="example@email.com"
                                    class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" dir="ltr" required>
                            </div>
                            <div class="text-right">
                                <label class="block text-sm font-bold text-slate-700 mb-2">الرسالة <span class="text-red-500">*</span></label>
                                <textarea name="message" rows="4" placeholder="كيف يمكننا مساعدتك اليوم؟"
                                    class="w-full bg-sky-50 border-transparent rounded-2xl py-4 px-6 focus:bg-white focus:ring-2 focus:ring-sky-400 transition text-right" required></textarea>
                            </div>
                            <button type="submit"
                                class="w-full text-white font-black py-5 rounded-2xl transition-all duration-300 hover:-translate-y-1 active:scale-95 flex items-center justify-center gap-3"
                                style="background: linear-gradient(135deg, #0ea5e9, #6366f1); box-shadow: 0 8px 30px rgba(14,165,233,.35);">
                                أرسل الرسالة الآن
                                <svg class="w-5 h-5 rtl:rotate-180" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                                </svg>
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </section>

    {{-- FINAL CTA --}}
    <div class="py-24 relative overflow-hidden" dir="rtl"
        style="background: linear-gradient(135deg, #f97316 0%, #ec4899 35%, #8b5cf6 65%, #3b82f6 100%);">
        {{-- Dot pattern --}}
        <div class="absolute inset-0 opacity-15 pointer-events-none"
            style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 24px 24px;"></div>
        {{-- Ambient blobs --}}
        <div class="absolute -top-20 -right-20 w-96 h-96 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-20 -left-20 w-80 h-80 bg-white/10 rounded-full blur-3xl pointer-events-none"></div>
        {{-- Confetti decorations --}}
        <div class="absolute top-10 left-1/4 w-4 h-4 rounded-full bg-yellow-300/60 pointer-events-none" style="animation: confetti-float 4s ease-in-out infinite;"></div>
        <div class="absolute top-16 right-1/3 w-3 h-3 rounded-full bg-white/40 pointer-events-none" style="animation: confetti-float 5s ease-in-out infinite .5s;"></div>
        <div class="absolute bottom-16 left-1/3 w-5 h-5 rounded-full bg-yellow-200/50 pointer-events-none" style="animation: confetti-float 6s ease-in-out infinite 1s;"></div>
        <div class="absolute bottom-10 right-1/4 w-3 h-3 bg-white/30 rotate-45 pointer-events-none" style="animation: confetti-float 4.5s ease-in-out infinite 1.5s;"></div>
        <div class="absolute top-1/2 left-12 w-3 h-3 rounded-full bg-white/30 pointer-events-none" style="animation: confetti-float 5.5s ease-in-out infinite .3s;"></div>
        <div class="absolute top-1/2 right-12 w-2 h-2 rounded-full bg-yellow-300/60 pointer-events-none" style="animation: confetti-float 4s ease-in-out infinite 2s;"></div>

        <div class="relative z-10 max-w-3xl mx-auto px-4 text-center">
            <div class="text-6xl mb-6 inline-block animate-bounce" style="animation-duration: 2.5s;">🚀</div>
            <h2 class="text-4xl md:text-5xl font-extrabold text-white mb-6 leading-tight drop-shadow-lg">
                ابدأ رحلة طفلك اليوم!
            </h2>
            <p class="text-white/85 text-xl mb-10 leading-relaxed">
                انضم لأكثر من <strong class="text-yellow-300">{{ $settings["happy_families"] ?? 100 }} عائلة</strong> تصنع السحر مع HeroKid.<br>
                قصتك المخصصة جاهزة خلال <strong class="text-yellow-300">{{ $settings["delivery_days"] ?? 5 }} أيام</strong> فقط.
            </p>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="{{ route('stories.index') }}"
                    class="bg-white font-extrabold py-5 px-12 rounded-2xl text-xl shadow-2xl hover:shadow-white/30 transition hover:-translate-y-1"
                    style="color: #7c3aed;">اختر قصتك الآن ✨</a>
                <a href="{{ route('how-it-works') }}"
                    class="border-2 border-white/50 text-white font-bold py-5 px-10 rounded-2xl text-xl hover:bg-white/15 transition hover:-translate-y-1 backdrop-blur-sm">
                    كيف يعمل؟ 🎬
                </a>
            </div>
            {{-- Trust micro-badges --}}
            <div class="flex flex-wrap justify-center gap-6 mt-12 text-white/75 text-sm font-bold">
                <span class="flex items-center gap-1.5">🔒 دفع آمن</span>
                <span class="flex items-center gap-1.5">🚀 شحن سريع</span>
                <span class="flex items-center gap-1.5">⭐ ضمان الجودة</span>
                <span class="flex items-center gap-1.5">💬 دعم على مدار الساعة</span>
            </div>
        </div>
    </div>
</x-front-layout>