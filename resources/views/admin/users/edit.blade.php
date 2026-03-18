<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $user->id === auth()->id() ? 'حسابي — ' : 'تعديل المشرف — ' }}
            {{ $user->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">

            {{-- Profile Info Card --}}
            <div class="bg-white shadow-sm rounded-xl p-8 border border-gray-100">
                <h3 class="text-base font-bold text-gray-800 mb-6 pb-4 border-b border-gray-100">
                    المعلومات الشخصية
                </h3>

                <form action="{{ route('admin.users.update', $user) }}" method="POST" novalidate>
                    @csrf
                    @method('PUT')

                    <!-- Name -->
                    <div class="mb-6">
                        <x-input-label for="name" :value="__('الاسم الكامل')" />
                        <x-text-input id="name" class="block mt-1 w-full" type="text" name="name"
                                      :value="old('name', $user->name)" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <!-- Email -->
                    <div class="mb-8">
                        <x-input-label for="email" :value="__('البريد الإلكتروني')" />
                        <x-text-input id="email" class="block mt-1 w-full" type="email" name="email"
                                      :value="old('email', $user->email)" required dir="ltr" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    {{-- Divider --}}
                    <div class="border-t border-gray-100 pt-6 mb-6">
                        <h4 class="text-sm font-bold text-gray-700 mb-1">تغيير كلمة المرور</h4>
                        <p class="text-xs text-gray-400 mb-5">اتركها فارغة إذا لا تريد تغييرها</p>

                        <!-- New Password -->
                        <div class="mb-5">
                            <x-input-label for="password" :value="__('كلمة المرور الجديدة')" />
                            <x-text-input id="password" class="block mt-1 w-full" type="password"
                                          name="password" dir="ltr" autocomplete="new-password" />
                            <p class="text-xs text-gray-400 mt-1">8 أحرف على الأقل</p>
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <!-- Confirm Password -->
                        <div>
                            <x-input-label for="password_confirmation" :value="__('تأكيد كلمة المرور الجديدة')" />
                            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password"
                                          name="password_confirmation" dir="ltr" autocomplete="new-password" />
                            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <a href="{{ route('admin.users.index') }}" class="text-gray-500 hover:text-gray-700 text-sm font-semibold">
                            ← العودة
                        </a>
                        <x-primary-button>حفظ التغييرات</x-primary-button>
                    </div>
                </form>
            </div>

            {{-- Danger zone — only for other admins, not self --}}
            @if($user->id !== auth()->id())
            <div class="bg-white shadow-sm rounded-xl p-8 border border-red-100">
                <h3 class="text-base font-bold text-red-700 mb-2">منطقة الخطر</h3>
                <p class="text-sm text-gray-500 mb-5">سيتم حذف هذا المشرف نهائياً ولا يمكن التراجع.</p>
                <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                      onsubmit="return confirm('هل أنت متأكد من حذف هذا المشرف نهائياً؟')">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="bg-red-600 text-white font-bold px-6 py-2.5 rounded-lg hover:bg-red-700 transition text-sm">
                        حذف هذا المشرف
                    </button>
                </form>
            </div>
            @endif

        </div>
    </div>
</x-admin-layout>
