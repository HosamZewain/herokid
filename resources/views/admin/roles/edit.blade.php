<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-gray-900">تعديل الدور — {{ $role->name_ar }}</h2>
            <p class="mt-1 text-sm text-gray-500">أي تغيير سيؤثر مباشرة على جميع المستخدمين المرتبطين بهذا الدور.</p>
        </div>
    </x-slot>

    <div class="py-10">
        <form action="{{ route('admin.roles.update', $role) }}" method="POST" class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @csrf
            @method('PUT')
            @include('admin.roles._form', [
                'role' => $role,
                'permissionGroups' => $permissionGroups,
                'selectedPermissions' => $selectedPermissions,
                'isOwnerRole' => $isOwnerRole,
            ])
            <div class="flex items-center justify-between">
                <a href="{{ route('admin.roles.index') }}" class="text-sm font-bold text-gray-500">رجوع</a>
                <x-primary-button>حفظ التغييرات</x-primary-button>
            </div>
        </form>
    </div>
</x-admin-layout>
