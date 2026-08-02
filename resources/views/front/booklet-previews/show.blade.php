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
        [data-book] { margin: 0 auto; }
        .booklet-page { overflow: hidden; background: #fff; }
        .booklet-page__image { display: block; width: 100%; height: 100%; object-fit: contain; background: #fff; }
        .booklet-page__placeholder { position: absolute; inset: 0; display: grid; place-items: center; color: #94a3b8; background: linear-gradient(135deg, #fff, #f8fafc); font-weight: 800; }
        .booklet-page img[src]:not([src=""]) + .booklet-page__placeholder { display: none; }
        [data-book-stage]:fullscreen { background: #030712; padding: 1rem; }
        [data-thumbnails][hidden], [data-reader-loading][hidden], [data-reader-error][hidden], [data-reader-fallback][hidden] { display: none; }
        @media (prefers-reduced-motion: reduce) { [data-book-zoom], * { scroll-behavior: auto !important; transition-duration: .01ms !important; animation-duration: .01ms !important; } }
    </style>
</head>
<body class="min-h-screen overflow-x-hidden text-white">
    <div class="min-h-screen" data-booklet-reader
        data-document-url="{{ $documentUrl }}"
        data-reading-direction="{{ $preview->reading_direction }}"
        data-page-count="{{ $pageCount }}">
        <header class="border-b border-white/10 bg-slate-950/70 px-3 py-3 backdrop-blur sm:px-6">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3">
                <a href="{{ route('home') }}" class="flex items-center gap-2" aria-label="HeroKid">
                    <img src="/images/logo-192.png" alt="HeroKid" class="h-12 w-12 rounded-xl bg-white object-contain p-0.5 sm:h-14 sm:w-14">
                    <span class="hidden text-sm font-black text-white sm:block">HeroKid</span>
                </a>
                <div class="min-w-0 text-center">
                    <h1 class="truncate text-sm font-black sm:text-lg">{{ $publicTitle }}</h1>
                    <p class="mt-0.5 text-[10px] font-bold text-slate-400 sm:text-xs">معاينة خاصة للعرض فقط</p>
                </div>
                <button type="button" data-fullscreen class="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/15 bg-white/10 text-xl hover:bg-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300" aria-label="عرض بملء الشاشة">⛶</button>
            </div>
        </header>

        <main class="mx-auto flex min-h-[calc(100vh-76px)] max-w-[1600px] flex-col px-2 py-3 sm:px-5 sm:py-5">
            <div class="relative flex min-h-0 flex-1 overflow-hidden rounded-2xl border border-white/10 bg-black/30 shadow-2xl" data-book-stage>
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

                <div class="flex min-h-[420px] w-full items-center justify-center overflow-auto p-2 sm:min-h-[600px] sm:p-6" data-book-scroll>
                    <div class="flex h-full w-full items-center justify-center" data-book-zoom>
                        <div class="h-full w-full max-w-6xl" data-book aria-label="قارئ الكتاب التفاعلي"></div>
                        <div data-reader-fallback hidden class="mx-auto flex h-full w-full max-w-3xl items-center justify-center">
                            <img data-fallback-image alt="صفحة من معاينة الكتاب" class="max-h-full max-w-full rounded-lg bg-white object-contain shadow-2xl">
                        </div>
                    </div>
                </div>
            </div>

            <nav class="mt-3 flex flex-wrap items-center justify-center gap-2 rounded-2xl border border-white/10 bg-slate-950/75 p-2.5 backdrop-blur" aria-label="أدوات قارئ الكتاب">
                <button type="button" data-next-page class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"><span>{{ $preview->reading_direction === 'rtl' ? '→' : '←' }}</span><span>التالي</span></button>
                <div class="min-w-24 rounded-xl bg-white/10 px-3 py-2 text-center text-xs font-black" dir="ltr" aria-live="polite"><span data-current-page>1</span> / <span data-total-pages>{{ $pageCount }}</span></div>
                <button type="button" data-previous-page class="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white/10 px-4 py-2 text-sm font-black hover:bg-white/15 disabled:cursor-not-allowed disabled:opacity-40"><span>السابق</span><span>{{ $preview->reading_direction === 'rtl' ? '←' : '→' }}</span></button>
                <span class="mx-1 hidden h-7 w-px bg-white/10 sm:block"></span>
                <button type="button" data-thumbnails-toggle class="inline-flex h-11 min-w-11 items-center justify-center rounded-xl bg-white/10 px-3 text-sm font-black hover:bg-white/15" aria-label="عرض صور الصفحات">▦</button>
                <button type="button" data-zoom-out class="inline-flex h-11 min-w-11 items-center justify-center rounded-xl bg-white/10 px-3 text-lg font-black hover:bg-white/15" aria-label="تصغير">−</button>
                <button type="button" data-zoom-reset class="inline-flex h-11 min-w-14 items-center justify-center rounded-xl bg-white/10 px-3 text-xs font-black hover:bg-white/15" aria-label="إعادة ضبط التكبير">100%</button>
                <button type="button" data-zoom-in class="inline-flex h-11 min-w-11 items-center justify-center rounded-xl bg-white/10 px-3 text-lg font-black hover:bg-white/15" aria-label="تكبير">+</button>
            </nav>
            <p class="mt-2 text-center text-[10px] font-bold text-slate-500">المعاينة مخصصة للعرض داخل HeroKid ولا يتوفر تنزيل أو طباعة.</p>
        </main>
    </div>
</body>
</html>
