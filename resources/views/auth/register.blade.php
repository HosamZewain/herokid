<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label for="name" :value="__('الاسم')" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email or Phone -->
        <div class="mt-4">
            <x-input-label for="login" :value="__('رقم الموبايل أو البريد الإلكتروني')" />
            <x-text-input id="login" class="block mt-1 w-full text-left font-sans" type="text" name="login" :value="old('login', old('email', old('phone')))" required autocomplete="username" dir="ltr" placeholder="201000000000" />
            <p class="mt-1 text-xs text-gray-500">استخدم رقم الموبايل للتسجيل بشكل أسرع. يمكنك استخدام البريد الإلكتروني أيضاً.</p>
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('كلمة المرور')" />
            <x-text-input id="password" class="block mt-1 w-full text-left font-sans"
                            type="password"
                            name="password"
                            required autocomplete="new-password" dir="ltr" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" :value="__('تأكيد كلمة المرور')" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full text-left font-sans"
                            type="password"
                            name="password_confirmation" required autocomplete="new-password" dir="ltr" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="mt-6">
            <x-primary-button class="w-full justify-center py-3">
                {{ __('إنشاء الحساب') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
