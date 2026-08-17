<!DOCTYPE html>
<html lang="{{ $preview->reading_direction === 'rtl' ? 'ar' : 'en' }}" dir="{{ $preview->reading_direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive,noimageindex">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#111827">
    <title>معاينة قصة HeroKid</title>
    <meta name="description" content="معاينة خاصة لكتاب HeroKid. الرابط مخصص للعرض فقط.">
    <meta property="og:title" content="معاينة قصة HeroKid">
    <meta property="og:description" content="افتح الرابط لمشاهدة معاينة الكتاب بطريقة تفاعلية.">
    <meta property="og:image" content="{{ \App\Support\Seo::imageUrl('/images/og-cover.jpg') }}">
    <meta property="og:type" content="website">
    <link rel="icon" type="image/png" href="/images/logo-96.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        body { font-family: Tahoma, Arial, sans-serif; background: radial-gradient(circle at top, #312e81 0, #111827 46%, #030712 100%); }
        [data-book-stage] { --reader-zoom: 1; }
        [data-book-zoom] { transform: scale(var(--reader-zoom)); transform-origin: center center; transition: transform .18s ease; }
        [data-book] { width: 100%; max-width: min(100%, var(--reader-fit-width, 100%)) !important; margin: 0 auto; }
        .booklet-page { overflow: hidden; background: #fff; }
        .booklet-page__image { display: block; width: 100%; height: 100%; object-fit: contain; background: #fff; }
        .booklet-page__placeholder { position: absolute; inset: 0; display: grid; place-items: center; color: #94a3b8; background: linear-gradient(135deg, #fff, #f8fafc); font-weight: 800; }
        .booklet-page img[src]:not([src=""]) + .booklet-page__placeholder { display: none; }
        [data-booklet-reader][data-single-page-side="left"] .stf__block { transform: translateX(25%); }
        [data-booklet-reader][data-single-page-side="right"] .stf__block { transform: translateX(-25%); }
        [data-book-stage]:fullscreen { background: #030712; padding: 1rem; }
        [data-thumbnails][hidden], [data-reader-loading][hidden], [data-reader-error][hidden], [data-reader-fallback][hidden], [data-side-next-page][hidden], [data-side-previous-page][hidden] { display: none; }
        @media (prefers-reduced-motion: reduce) { [data-book-zoom], * { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; } }
    </style>
</head>
<body class="h-[100dvh] overflow-hidden text-white">
    <div class="flex h-[100dvh] flex-col overflow-hidden" data-booklet-reader
        data-document-url="{{ $documentUrl }}"
        data-reading-direction="{{ $preview->reading_direction }}"
        data-page-count="{{ $pageCount }}">
        <header class="shrink-0 border-b border-white/10 bg-slate-950/70 px-3 py-1.5 backdrop-blur sm:px-4 sm:py-2">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3">
                <a href="{{ route('home') }}" class="flex items-center gap-2" aria-label="HeroKid">
                    <img src="/images/logo-192.png" alt="HeroKid" class="h-10 w-10 rounded-lg bg-white object-contain p-0.5 sm:h-11 sm:w-11">
                    <span class="hidden text-sm font-black text-white sm:block">HeroKid</span>
                </a>
                <div class="min-w-0 text-center">
                    <h1 class="truncate text-sm font-black sm:text-base">{{ $publicTitle }}</h1>
                    <p class="text-[9px] font-bold text-slate-400 sm:text-[10px]">معاينة خاصة للعرض فقط</p>
                </div>
                <div class="flex items-center gap-1.5">
                    <a href="{{ $scenesUrl }}" class="inline-flex h-10 items-center justify-center rounded-lg border border-white/15 bg-white/10 px-2.5 text-[10px] font-black hover:bg-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 sm:px-3 sm:text-xs">عرض المشاهد</a>
                    <button type="button" data-fullscreen class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-white/15 bg-white/10 text-lg hover:bg-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300" aria-label="عرض بملء الشاشة">⛶</button>
                </div>
            </div>
        </header>

        <main class="mx-auto flex min-h-0 w-full max-w-[1800px] flex-1 flex-col px-1.5 py-1.5 sm:px-3 sm:py-2">
            <div class="relative flex min-h-0 flex-1 overflow-hidden rounded-xl border border-white/10 bg-black/30 shadow-2xl" data-book-stage>
                <aside data-thumbnails hidden class="absolute inset-y-0 right-0 z-30 w-28 overflow-y-auto border-l border-white/10 bg-slate-950/95 p-2 shadow-2xl sm:w-40" aria-label="صور الصفحات المصغرة">
                    <div class="mb-2 flex items-center justify-between gap-2"><strong class="text-xs">الصفحات</strong><button type="button" data-thumbnails-close class="h-9 w-9 rounded-lg bg-white/10" aria-label="إغلاق الصور المصغرة">×</button></div>
                    <div class="grid gap-2" data-thumbnail-list></div>
                </aside>

                <div class="absolute inset-0 z-20 grid place-items-center bg-slate-950/90 px-6 text-center" data-reader-loading role="status" aria-live="polite">
                    <div>
                        <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-indigo-300/30 border-t-indigo-300"></div>
                        <p class="mt-4 font-black">جاري تجهيز الكتاب...</p>
                        <p class="mt-2 text-xs font-bold text-slate-400" data-loading-progress>تحميل الملف</p>
                    </div>
                </div>

                <div data-reader-error hidden class="absolute inset-0 z-40 grid place-items-center bg-slate-950 px-6 text-center" role="alert">
                    <div><span class="text-4xl">⚠️</span><h2 class="mt-3 text-xl font-black">تعذر فتح المعاينة</h2><p class="mt-2 max-w-lg text-sm leading-7 text-slate-300" data-reader-error-message>حاول تحديث الصفحة أو تواصل مع HeroKid.</p><button type="button" onclick="window.location.reload()" class="mt-5 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black">إعادة المحاولة</button></div>
                </div>

                <button type="button" data-side-next-page hidden
                    class="absolute top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/20 bg-slate-950/80 shadow-2xl backdrop-blur transition hover:scale-105 hover:bg-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 disabled:pointer-events-none disabled:opacity-0 sm:h-12 sm:w-12"
                    aria-label="الصفحة التالية">
                    <img src="/images/icons/chevron-right.svg" alt="" class="h-6 w-6 {{ $preview->reading_direction === 'rtl' ? 'rotate-180' : '' }}">
                </button>
                <button type="button" data-side-previous-page hidden
                    class="absolute top-1/2 z-10 inline-flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/20 bg-slate-950/80 shadow-2xl backdrop-blur transition hover:scale-105 hover:bg-indigo-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 disabled:pointer-events-none disabled:opacity-0 sm:h-12 sm:w-12"
                    aria-label="الصفحة السابقة">
                    <img src="/images/icons/chevron-right.svg" alt="" class="h-6 w-6 {{ $preview->reading_direction === 'ltr' ? 'rotate-180' : '' }}">
                </button>

                <div class="flex min-h-0 w-full items-center justify-center overflow-auto p-1 sm:p-2" data-book-scroll>
                    <div class="flex h-full w-full items-center justify-center" data-book-zoom>
                        <div class="h-full w-full max-w-6xl" data-book aria-label="قارئ الكتاب التفاعلي"></div>
                        <div data-reader-fallback hidden class="mx-auto flex h-full w-full max-w-3xl items-center justify-center">
                            <img data-fallback-image alt="صفحة من معاينة الكتاب" class="max-h-full max-w-full rounded-lg bg-white object-contain shadow-2xl">
                        </div>
                    </div>
                </div>
            </div>

            <nav class="mt-1.5 flex shrink-0 flex-nowrap items-center justify-center gap-1 overflow-x-auto rounded-xl border border-white/10 bg-slate-950/75 p-1.5 backdrop-blur" aria-label="أدوات قارئ الكتاب">
                <button type="button" data-next-page class="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg bg-indigo-600 px-3 text-xs font-black hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"><span>{{ $preview->reading_direction === 'rtl' ? '←' : '→' }}</span><span>التالي</span></button>
                <div class="min-w-20 shrink-0 rounded-lg bg-white/10 px-2 py-1.5 text-center text-[11px] font-black" dir="ltr" aria-live="polite"><span data-current-page>1</span> / <span data-total-pages>{{ $pageCount }}</span></div>
                <button type="button" data-previous-page class="inline-flex h-9 shrink-0 items-center gap-1 rounded-lg bg-white/10 px-3 text-xs font-black hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-40"><span>السابق</span><span>{{ $preview->reading_direction === 'rtl' ? '→' : '←' }}</span></button>
                <span class="mx-0.5 hidden h-6 w-px shrink-0 bg-white/10 sm:block"></span>
                <button type="button" data-thumbnails-toggle class="inline-flex h-9 min-w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 px-2 text-xs font-black hover:bg-white/15" aria-label="عرض صور الصفحات">▦</button>
                <button type="button" data-zoom-out class="hidden h-9 min-w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 px-2 text-base font-black hover:bg-white/15 sm:inline-flex" aria-label="تصغير">−</button>
                <button type="button" data-zoom-reset class="hidden h-9 min-w-12 shrink-0 items-center justify-center rounded-lg bg-white/10 px-2 text-[10px] font-black hover:bg-white/15 sm:inline-flex" aria-label="إعادة ضبط التكبير">100%</button>
                <button type="button" data-zoom-in class="hidden h-9 min-w-9 shrink-0 items-center justify-center rounded-lg bg-white/10 px-2 text-base font-black hover:bg-white/15 sm:inline-flex" aria-label="تكبير">+</button>
            </nav>
            <p class="sr-only">المعاينة مخصصة للعرض داخل HeroKid ولا يتوفر تنزيل أو طباعة.</p>
        </main>
    </div>
</body>
</html>
