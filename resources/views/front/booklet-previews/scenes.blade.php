<!DOCTYPE html>
<html lang="{{ $preview->reading_direction === 'rtl' ? 'ar' : 'en' }}" dir="{{ $preview->reading_direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow,noarchive,noimageindex">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#111827">
    <title>معاينة مشاهد قصة HeroKid</title>
    <meta name="description" content="معاينة خاصة لمشاهد كتاب HeroKid. الرابط مخصص للعرض فقط.">
    <meta property="og:title" content="معاينة قصة HeroKid">
    <meta property="og:description" content="افتح الرابط لمشاهدة مشاهد الكتاب بالترتيب.">
    <meta property="og:image" content="{{ \App\Support\Seo::imageUrl('/images/og-cover.jpg') }}">
    <meta property="og:type" content="website">
    <link rel="icon" type="image/png" href="/images/logo-96.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        html, body { overscroll-behavior: none; }
        body { font-family: Tahoma, Arial, sans-serif; background: radial-gradient(circle at top, #312e81 0, #111827 42%, #030712 100%); }
        [data-reader-loading][hidden], [data-reader-error][hidden] { display: none; }
        .scene-reader__list { scroll-snap-type: y proximity; scrollbar-color: #6366f1 #0f172a; }
        .scene-reader__section { min-height: calc(100dvh - 4.25rem); padding: .75rem; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .5rem; scroll-snap-align: start; scroll-snap-stop: always; }
        .scene-reader__frame { width: min(100%, 1500px); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); overflow: hidden; border-radius: .75rem; background: white; box-shadow: 0 24px 70px rgb(0 0 0 / .42); touch-action: pan-y pinch-zoom; }
        .scene-reader__frame--cover, .scene-reader__frame--back-cover { width: min(80vw, 620px); grid-template-columns: minmax(0, 1fr); }
        .scene-reader__page { position: relative; min-width: 0; aspect-ratio: 1 / 1.4142; overflow: hidden; background: linear-gradient(135deg, #fff, #f1f5f9); }
        .scene-reader__page img { display: block; width: 100%; height: 100%; object-fit: contain; background: white; }
        .scene-reader__page > span { position: absolute; inset: 0; display: grid; place-items: center; color: #94a3b8; font-weight: 900; }
        .scene-reader__page img[src]:not([src=""]) + span { display: none; }
        .scene-reader__caption { margin: 0; border-radius: 9999px; background: rgb(15 23 42 / .82); padding: .35rem .85rem; color: white; font-size: .75rem; font-weight: 900; }
        @media (min-width: 768px) {
            .scene-reader__section { padding: 1.25rem; }
            .scene-reader__frame { width: auto; height: min(calc(100dvh - 7rem), calc((100vw - 2.5rem) / 1.4142)); aspect-ratio: 1.4142 / 1; }
            .scene-reader__frame--cover, .scene-reader__frame--back-cover { width: auto; height: min(calc(100dvh - 7rem), calc((100vw - 2.5rem) * 1.4142)); aspect-ratio: 1 / 1.4142; }
        }
        @media (prefers-reduced-motion: reduce) { .scene-reader__list { scroll-behavior: auto !important; scroll-snap-type: none; } }
    </style>
</head>
<body class="h-[100dvh] overflow-hidden text-white">
    <div class="relative flex h-[100dvh] flex-col overflow-hidden" data-scene-reader
        data-document-url="{{ $documentUrl }}"
        data-reading-direction="{{ $preview->reading_direction }}"
        data-page-count="{{ $pageCount }}">
        <header class="relative z-20 shrink-0 border-b border-white/10 bg-slate-950/85 px-3 py-1.5 backdrop-blur sm:px-4 sm:py-2">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-3">
                <a href="{{ route('home') }}" class="flex items-center gap-2" aria-label="HeroKid">
                    <img src="/images/logo-192.png" alt="HeroKid" class="h-10 w-10 rounded-lg bg-white object-contain p-0.5 sm:h-11 sm:w-11">
                    <span class="hidden text-sm font-black sm:block">HeroKid</span>
                </a>
                <div class="min-w-0 text-center">
                    <h1 class="truncate text-sm font-black sm:text-base">{{ $publicTitle }}</h1>
                    <p class="text-[9px] font-bold text-slate-400 sm:text-[10px]" data-current-scene>الغلاف</p>
                </div>
                <a href="{{ $flipbookUrl }}" class="inline-flex h-10 items-center justify-center rounded-lg border border-white/15 bg-white/10 px-2.5 text-[10px] font-black hover:bg-white/15 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-300 sm:px-3 sm:text-xs">عرض التقليب</a>
            </div>
            <div class="absolute inset-x-0 bottom-0 h-0.5 bg-white/10"><div data-scene-progress class="h-full w-0 bg-indigo-400 transition-[width] duration-300"></div></div>
        </header>

        <main class="scene-reader__list min-h-0 flex-1 overflow-y-auto" data-scene-list aria-label="مشاهد الكتاب بالترتيب"></main>

        <div class="absolute inset-0 z-30 grid place-items-center bg-slate-950/95 px-6 text-center" data-reader-loading role="status" aria-live="polite">
            <div>
                <div class="mx-auto h-12 w-12 animate-spin rounded-full border-4 border-indigo-300/30 border-t-indigo-300"></div>
                <p class="mt-4 font-black">جاري تجهيز المشاهد...</p>
                <p class="mt-2 text-xs font-bold text-slate-400" data-loading-progress>تحميل الملف</p>
            </div>
        </div>

        <div data-reader-error hidden class="absolute inset-0 z-40 grid place-items-center bg-slate-950 px-6 text-center" role="alert">
            <div><span class="text-4xl">⚠️</span><h2 class="mt-3 text-xl font-black">تعذر فتح المعاينة</h2><p class="mt-2 max-w-lg text-sm leading-7 text-slate-300" data-reader-error-message>حاول تحديث الصفحة أو تواصل مع HeroKid.</p><button type="button" onclick="window.location.reload()" class="mt-5 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black">إعادة المحاولة</button></div>
        </div>

        <p class="sr-only">المعاينة مخصصة للعرض داخل HeroKid ولا يتوفر تنزيل أو طباعة.</p>
    </div>
</body>
</html>
