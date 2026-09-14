@props([
    'role' => null,
    'permissionGroups',
    'selectedPermissions' => [],
    'isOwnerRole' => false,
])

<div class="space-y-6">
    <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
                <x-input-label for="name_ar" value="اسم الدور بالعربية" />
                <x-text-input id="name_ar" name="name_ar" type="text" class="mt-1 block w-full" :value="old('name_ar', $role?->name_ar)" required />
                <x-input-error :messages="$errors->get('name_ar')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="name_en" value="اسم الدور بالإنجليزية" />
                <x-text-input id="name_en" name="name_en" type="text" class="mt-1 block w-full" :value="old('name_en', $role?->name_en)" required dir="ltr" />
                <x-input-error :messages="$errors->get('name_en')" class="mt-2" />
            </div>
        </div>

        <div class="mt-5">
            <x-input-label for="description_ar" value="وصف الدور" />
            <textarea id="description_ar" name="description_ar" rows="3" class="mt-1 block w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">{{ old('description_ar', $role?->description_ar) }}</textarea>
            <x-input-error :messages="$errors->get('description_ar')" class="mt-2" />
        </div>

        <label class="mt-5 flex items-center gap-3 rounded-xl border p-4 {{ $isOwnerRole ? 'border-amber-200 bg-amber-50' : 'border-green-100 bg-green-50' }}">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                @checked((string) old('is_active', ($role?->is_active ?? true) ? '1' : '0') === '1')
                @disabled($isOwnerRole)>
            @if($isOwnerRole)
                <input type="hidden" name="is_active" value="1">
            @endif
            <span>
                <span class="block font-black text-gray-900">الدور نشط</span>
                <span class="text-xs text-gray-600">{{ $isOwnerRole ? 'دور مالك النظام محمي ولا يمكن تعطيله.' : 'الأدوار الموقوفة لا تمنح صلاحيات ولا تظهر عند تعيين مستخدم.' }}</span>
            </span>
        </label>
        <x-input-error :messages="$errors->get('is_active')" class="mt-2" />
    </div>

    <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        @if($isOwnerRole)
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-7 text-amber-800">
                مالك النظام يحصل دائمًا على جميع الصلاحيات الحالية والمضافة مستقبلًا. يمكنك تعديل الاسم والوصف فقط.
            </div>
        @endif

        @include('admin.users._permissions-matrix', [
            'permissionGroups' => $permissionGroups,
            'selected' => $selectedPermissions,
            'disabled' => $isOwnerRole,
            'title' => 'صلاحيات الدور',
            'hint' => 'حدد ما يستطيع مستخدمو هذا الدور مشاهدته أو تنفيذه. التغيير ينعكس فورًا على كل المستخدمين المرتبطين به.',
            'open' => true,
        ])
    </div>
</div>
