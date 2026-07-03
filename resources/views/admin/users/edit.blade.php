@php
    $isSelf = $user->id === auth()->id();
    $canManagePermissions = auth()->user()->hasPermission('admin_users.permissions.manage');
    $canDelete = auth()->user()->hasPermission('admin_users.delete');
    $selectedPermissions = $user->permissions->pluck('key')->all();
@endphp

<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $isSelf ? 'حسابي — ' : 'تعديل المشرف — ' }} {{ $user->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <form action="{{ route('admin.users.update', $user) }}" method="POST" novalidate class="space-y-6">
                @csrf
                @method('PUT')

                <div class="rounded-xl border border-gray-100 bg-white p-8 shadow-sm">
                    <div class="mb-6 flex flex-col gap-3 border-b border-gray-100 pb-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h3 class="text-base font-bold text-gray-800">المعلومات الشخصية</h3>
                            <p class="mt-1 text-xs text-gray-400">يمكن للمشرف دائماً تعديل بياناته الشخصية وكلمة المرور.</p>
                        </div>
                        @if($user->is_active)
                            <span class="w-fit rounded-full bg-green-50 px-3 py-1 text-xs font-black text-green-700">نشط</span>
                        @else
                            <span class="w-fit rounded-full bg-red-50 px-3 py-1 text-xs font-black text-red-700">موقوف</span>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <div>
                            <x-input-label for="name" :value="__('الاسم الكامل')" />
                            <x-text-input id="name" class="mt-1 block w-full" type="text" name="name" :value="old('name', $user->name)" required autofocus />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="email" :value="__('البريد الإلكتروني')" />
                            <x-text-input id="email" class="mt-1 block w-full" type="email" name="email" :value="old('email', $user->email)" required dir="ltr" />
                            <x-input-error :messages="$errors->get('email')" class="mt-2" />
                        </div>
                    </div>

                    <div class="mt-6 border-t border-gray-100 pt-6">
                        <h4 class="mb-1 text-sm font-bold text-gray-700">تغيير كلمة المرور</h4>
                        <p class="mb-5 text-xs text-gray-400">اتركها فارغة إذا لا تريد تغييرها</p>

                        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <x-input-label for="password" :value="__('كلمة المرور الجديدة')" />
                                <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" dir="ltr" autocomplete="new-password" />
                                <p class="mt-1 text-xs text-gray-400">8 أحرف على الأقل</p>
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="password_confirmation" :value="__('تأكيد كلمة المرور الجديدة')" />
                                <x-text-input id="password_confirmation" class="mt-1 block w-full" type="password" name="password_confirmation" dir="ltr" autocomplete="new-password" />
                                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                            </div>
                        </div>
                    </div>
                </div>

                @if($canManagePermissions)
                    <div class="rounded-xl border border-gray-100 bg-white p-8 shadow-sm">
                        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-7 text-amber-800">
                            أي تغيير في حالة الحساب أو الصلاحيات يتم تسجيله في سجل النشاط.
                        </div>

                        <label class="mb-6 flex items-center gap-3 rounded-xl border border-gray-100 bg-slate-50 p-4">
                            <input type="hidden" name="is_active" value="0">
                            <input
                                type="checkbox"
                                name="is_active"
                                value="1"
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                @checked((string) old('is_active', $user->is_active ? '1' : '0') === '1')
                                @disabled($isSelf)
                            >
                            @if($isSelf)
                                <input type="hidden" name="is_active" value="1">
                            @endif
                            <span>
                                <span class="block font-black text-gray-900">الحساب نشط</span>
                                <span class="text-xs text-gray-500">{{ $isSelf ? 'لا يمكنك إيقاف حسابك الخاص.' : 'أوقف الحساب لمنع دخوله إلى لوحة الإدارة.' }}</span>
                            </span>
                        </label>
                        <x-input-error :messages="$errors->get('is_active')" class="mt-2" />

                        @include('admin.users._permissions-matrix', [
                            'permissionGroups' => $permissionGroups,
                            'selected' => $selectedPermissions,
                        ])
                    </div>
                @endif

                <div class="flex items-center justify-between">
                    @can('admin_users.view')
                        <a href="{{ route('admin.users.index') }}" class="text-sm font-semibold text-gray-500 hover:text-gray-700">← العودة</a>
                    @else
                        <span></span>
                    @endcan
                    <x-primary-button>حفظ التغييرات</x-primary-button>
                </div>
            </form>

            @if(!$isSelf && $canDelete)
                <div class="rounded-xl border border-red-100 bg-white p-8 shadow-sm">
                    <h3 class="mb-2 text-base font-bold text-red-700">منطقة الخطر</h3>
                    <p class="mb-5 text-sm text-gray-500">سيتم حذف هذا المشرف نهائياً ولا يمكن التراجع.</p>
                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST"
                          onsubmit="return confirm('هل أنت متأكد من حذف هذا المشرف نهائياً؟')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="rounded-lg bg-red-600 px-6 py-2.5 text-sm font-bold text-white transition hover:bg-red-700">
                            حذف هذا المشرف
                        </button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
