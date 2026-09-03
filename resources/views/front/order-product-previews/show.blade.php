<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>معاينة طلب HeroKid</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-white">
    <header class="border-b border-white/10 bg-slate-950/95 px-4 py-3">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
            <div class="text-right">
                <h1 class="text-lg font-black sm:text-xl">معاينة طلبك من HeroKid</h1>
                <p class="mt-1 text-xs font-bold text-slate-400">راجع جميع الصور وأرسل تأكيدك لنا عبر واتساب.</p>
            </div>
            <img src="{{ asset('images/logo-192.png') }}" alt="HeroKid" class="h-14 w-14 rounded-xl bg-white object-contain p-1">
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-3 py-5 sm:px-6 sm:py-8">
        <div class="mb-4 flex items-center justify-between gap-3">
            <p class="text-sm font-black text-slate-300">{{ $gallery->previews->count() }} صورة معاينة</p>
            <p class="rounded-full bg-indigo-500/15 px-3 py-1 text-xs font-black text-indigo-200">خاصة بالعرض فقط</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            @foreach($gallery->previews as $preview)
                <figure class="overflow-hidden rounded-2xl border border-white/10 bg-slate-900 shadow-xl">
                    <a href="{{ route('order-product-previews.image', ['token' => $token, 'preview' => $preview]) }}" target="_blank" rel="noopener" class="block bg-black/30">
                        <img
                            src="{{ route('order-product-previews.image', ['token' => $token, 'preview' => $preview]) }}"
                            alt="صورة معاينة {{ $loop->iteration }}"
                            class="mx-auto max-h-[80vh] w-full object-contain"
                            @if(!$loop->first) loading="lazy" @endif
                        >
                    </a>
                    <figcaption class="px-4 py-3 text-center text-xs font-bold text-slate-400">المعاينة {{ $loop->iteration }}</figcaption>
                </figure>
            @endforeach
        </div>
    </main>
</body>
</html>
