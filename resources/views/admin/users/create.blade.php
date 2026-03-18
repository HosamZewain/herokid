<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">إضافة مشرف جديد</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm rounded-xl p-8 border border-gray-100">

                <form action="{{ route('admin.users.store') }}" method="POST" novalidate>
                    @csrf

                    <!-- Name -->
                    <div class="mb-6">
                        <x-input-label for="name" :value="__('الاسم الكامل')" />
                        <x-text-input id="name" class="block mt-1 w-full" type="text" name="name"
                                      :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <!-- Email -->
                    <div class="mb-6">
                        <x-input-label for="email" :value="__('البريد الإلكتروني')" />
                        <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                                      :value="old('email')" required dir="ltr" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <!-- Password -->
                    <div class="mb-6">
                        <x-input-label for="password" :value="__('كلمة المرور')" />
                        <x-text-input id="password" class="block mt-1 w-full" type="password"
                                      name="password" required dir="ltr" />
                        <p class="text-xs text-gray-400 mt-1">8 أحرف على الأقل</p>
                        <x-input-error :messages="$errors->get('password')" class="mt-2" />
                    </div>

                    <!-- Password Confirm -->
                    <div class="mb-8">
                        <x-input-label for="password_confirmation" :value="__('تأكيد كلمة المرور')" />
                        <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password"
                                      name="password_confirmation" required dir="ltr" />
                        <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-between">
                        <a href="{{ route('admin.users.index') }}" class="text-gray-500 hover:text-gray-700 text-sm font-semibold">
                            ← العودة
                        </a>
                        <x-primary-button>حفظ المشرف</x-primary-button>
                    </div>
                </form>

            </div>
        </div>
    </div>
</x-admin-layout>
