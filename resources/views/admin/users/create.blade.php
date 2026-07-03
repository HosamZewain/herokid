<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">إضافة مشرف جديد</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <form action="{{ route('admin.users.store') }}" method="POST" novalidate class="space-y-6">
                @csrf

                <div class="rounded-xl border border-gray-100 bg-white p-8 shadow-sm">
                    <h3 class="mb-6 border-b border-gray-100 pb-4 text-base font-bold text-gray-800">بيانات الحساب</h3>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <x-input-label for="name" :value="__('الاسم الكامل')" />
                            <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name')" required autofocus />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" :value="__('البريد الإلكتروني')" />
                            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required dir="ltr" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password" :value="__('كلمة المرور')" />
                            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required dir="ltr" />
                            <p class="mt-1 text-xs text-gray-400">8 أحرف على الأقل</p>
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password_confirmation" :value="__('تأكيد كلمة المرور')" />
                            <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" required dir="ltr" />
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>
                    </div>

                    <label class="mt-6 flex items-center gap-3 rounded-xl border border-green-100 bg-green-50 p-4">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" @checked((string) old('is_active', '1') === '1')>
                        <span>
                            <span class="block font-black text-green-800">نشط</span>
                            <span class="text-xs text-green-700">الحساب النشط يمكنه دخول لوحة الإدارة حسب الصلاحيات المختارة.</span>
                        </span>
                    </label>
                    <x-input-error :messages="$errors->get('is_active')" class="mt-2" />
                </div>

                <div class="rounded-xl border border-gray-100 bg-white p-8 shadow-sm">
                    @include('admin.users._permissions-matrix', [
                        'permissionGroups' => $permissionGroups,
                        'selected' => [],
                    ])
                </div>

                <div class="flex items-center justify-between">
                    <a href="{{ route('admin.users.index') }}" class="text-sm font-semibold text-gray-500 hover:text-gray-700">← العودة</a>
                    <x-primary-button>حفظ المشرف</x-primary-button>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
