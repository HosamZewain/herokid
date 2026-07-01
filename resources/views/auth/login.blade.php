<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Login identifier -->
        <div>
            <x-input-label for="login" :value="__('البريد الإلكتروني أو رقم الموبايل')" />
            <x-text-input id="login" class="block mt-1 w-full text-left font-sans" type="text" name="login" :value="old('login', old('email'))" required autofocus autocomplete="username" dir="ltr" />
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('كلمة المرور')" />
            <x-text-input id="password" class="block mt-1 w-full text-left font-sans"
                            type="password"
                            name="password"
                            required autocomplete="current-password" dir="ltr" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center cursor-pointer">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('تذكرني') }}</span>
            </label>
        </div>

        <div class="mt-6">
            <x-primary-button class="w-full justify-center py-3">
                {{ __('دخول') }}
            </x-primary-button>

            @if (Route::has('password.request'))
                <div class="mt-4 text-center">
                    <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                        {{ __('هل نسيت كلمة المرور؟') }}
                    </a>
                </div>
            @endif
        </div>
    </form>
</x-guest-layout>
