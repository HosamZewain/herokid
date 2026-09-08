<x-admin-layout>
    <x-slot name="header">
        <h2 class="text-xl font-black text-gray-900">إنشاء دور وظيفي</h2>
    </x-slot>

    <div class="py-10">
        <form action="{{ route('admin.roles.store') }}" method="POST" class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
            @csrf
            @include('admin.roles._form', [
                'permissionGroups' => $permissionGroups,
            ])
            <div class="flex items-center justify-between">
                <a href="{{ route('admin.roles.index') }}" class="text-sm font-bold text-gray-500">رجوع</a>
                <x-primary-button>إنشاء الدور</x-primary-button>
            </div>
        </form>
    </div>
</x-admin-layout>
