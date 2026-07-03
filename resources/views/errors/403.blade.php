<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — ليس لديك صلاحية</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=cairo:400,600,700,800&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>body{font-family:'Cairo',sans-serif}</style>
</head>
<body class="bg-slate-50 text-slate-900">
    <main class="min-h-screen flex items-center justify-center px-6">
        <section class="max-w-lg rounded-2xl border border-slate-100 bg-white p-8 text-center shadow-sm">
            <p class="mb-3 text-sm font-black text-indigo-600">403</p>
            <h1 class="text-2xl font-black">ليس لديك صلاحية للوصول إلى هذه الصفحة</h1>
            <p class="mt-4 text-sm leading-7 text-slate-500">تواصل مع مدير النظام إذا كنت تحتاج إلى هذه الصلاحية.</p>
            <div class="mt-6 flex justify-center gap-3">
                @auth
                    @if(auth()->user()->isAdmin())
                        <a href="{{ route('admin.home') }}" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-indigo-700">العودة للوحة الإدارة</a>
                    @else
                        <a href="{{ route('home') }}" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-indigo-700">العودة للموقع</a>
                    @endif
                @else
                    <a href="{{ route('login') }}" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-indigo-700">تسجيل الدخول</a>
                @endauth
            </div>
        </section>
    </main>
</body>
</html>
