<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name') }} — دخول / تسجيل</title>

    <!-- Fonts -->
    <link rel="preload" href="{{ asset('fonts/cairo-regular.ttf') }}" as="font" type="font/ttf" crossorigin>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="{{ \App\Support\SiteBranding::url() }}">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/css/front-theme.css', 'resources/js/app.js'])
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }
    </style>
</head>

<body class="herokid-front font-sans text-gray-900 antialiased bg-gray-100">
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
        <div>
            <a href="/">
                <x-site-logo sizes="240px" alt="HeroKid Logo" class="h-auto w-60 object-contain" />
            </a>
        </div>

        <div class="w-full sm:max-w-md mt-6 px-6 py-8 bg-white shadow-md overflow-hidden sm:rounded-2xl">
            @if (Route::has('login') && Route::has('register') && (request()->routeIs('login') || request()->routeIs('register')))
                <div class="grid grid-cols-2 gap-2 mb-6 rounded-2xl bg-gray-100 p-1">
                    <a href="{{ route('login') }}"
                        class="text-center rounded-xl px-4 py-2.5 text-sm font-extrabold transition {{ request()->routeIs('login') ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-900' }}">
                        Login
                    </a>
                    <a href="{{ route('register') }}"
                        class="text-center rounded-xl px-4 py-2.5 text-sm font-extrabold transition {{ request()->routeIs('register') ? 'bg-white text-indigo-700 shadow-sm' : 'text-gray-500 hover:text-gray-900' }}">
                        Register
                    </a>
                </div>
            @endif

            {{ $slot }}
        </div>
    </div>
</body>

</html>
