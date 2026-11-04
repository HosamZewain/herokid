<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>معاينة الطلب{{ $orderReference ? ' '.$orderReference : '' }} — HeroKid</title>
    @vite(['resources/css/app.css'])
    <style>
        .protected-preview { -webkit-user-select: none; user-select: none; -webkit-touch-callout: none; }
        @media print {
            body > * { display: none !important; }
            body::after { content: 'هذه المعاينة غير متاحة للطباعة.'; display: grid; min-height: 100vh; place-items: center; color: #0f172a; background: white; font: 700 24px sans-serif; }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900" oncontextmenu="return false" ondragstart="return false">
    <header class="border-b border-slate-200 bg-white px-4 py-5">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4">
            <div>
                <p class="text-sm font-bold text-indigo-600">معاينة طلبك من HeroKid</p>
                <h1 class="mt-1 text-2xl font-black">{{ $orderReference ? 'طلب رقم ' : 'معاينة طلبك' }}@if($orderReference)<bdi>{{ $orderReference }}</bdi>@endif</h1>
            </div>
            <img src="{{ \App\Support\SiteBranding::url() }}" alt="HeroKid" class="h-16 w-24 object-contain">
        </div>
    </header>
    <main class="mx-auto max-w-6xl px-3 py-6 sm:px-6">
        <div class="mb-6 rounded-2xl border border-indigo-100 bg-white p-5">
            <p class="font-bold">راجع اسم الطفل وكل الصور، ثم ارجع لمحادثة واتساب لتأكيد المعاينة أو طلب تعديل.</p>
            <p class="mt-2 text-sm leading-7 text-slate-600">لو الرسالة فيها أزرار، اضغط «تأكيد المعاينة» أو «طلب تعديل المعاينة». ويمكنك إرسال ردك للفريق في نفس المحادثة. تأكيد المعاينة لا يعني تسجيل الدفع.</p>
        </div>
        <div class="mb-4 flex items-center justify-between gap-3">
            <p class="text-sm font-bold text-slate-600">{{ $images->count() }} صورة معاينة</p>
            <p class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700">خاصة بالعرض فقط</p>
        </div>
        <div class="grid items-start gap-6 sm:grid-cols-2">
            @foreach($images as $image)
                <figure class="protected-preview overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <figcaption class="border-b border-slate-100 px-5 py-4">
                        <p class="mb-1 text-xs font-bold text-slate-500">المعاينة {{ $loop->iteration }}</p>
                        <h2 class="text-lg font-bold leading-7">{{ $image['label'] }}</h2>
                    </figcaption>
                    <div class="relative">
                        <img src="{{ $image['url'] }}" alt="{{ $image['label'] }}" class="pointer-events-none mx-auto h-auto w-full object-contain" draggable="false" @if(!$loop->first) loading="lazy" @endif>
                        <span class="absolute inset-0" aria-hidden="true"></span>
                    </div>
                </figure>
            @endforeach
        </div>
    </main>
    <script>
        document.addEventListener('copy', (event) => event.preventDefault());
        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && ['c', 'p', 's', 'u'].includes(event.key.toLowerCase())) event.preventDefault();
        });
    </script>
</body>
</html>
