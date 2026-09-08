<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-black text-gray-900">إدارة الأدوار الوظيفية</h2>
                <p class="mt-1 text-sm text-gray-500">تحكم مركزي في صلاحيات كل دور بدل تعديل كل موظف على حدة.</p>
            </div>
            <a href="{{ route('admin.roles.create') }}" class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white hover:bg-indigo-700">+ دور جديد</a>
        </div>
    </x-slot>

    <div class="py-10">
        <div class="mx-auto max-w-7xl space-y-5 px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-xl border border-green-200 bg-green-50 p-4 text-sm font-bold text-green-800">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-800">{{ session('error') }}</div>
            @endif

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($roles as $role)
                    <article class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-black text-gray-900">{{ $role->name_ar }}</h3>
                                    @if($role->is_system)
                                        <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[10px] font-black text-indigo-700">دور أساسي</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-xs text-gray-400" dir="ltr">{{ $role->name_en }}</p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-black {{ $role->is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ $role->is_active ? 'نشط' : 'موقوف' }}
                            </span>
                        </div>

                        <p class="mt-4 min-h-10 text-sm leading-6 text-gray-600">{{ $role->description_ar ?: 'لا يوجد وصف.' }}</p>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-slate-50 p-3 text-center">
                                <p class="text-lg font-black text-gray-900">{{ $role->permissions->count() }}</p>
                                <p class="text-xs text-gray-500">صلاحية</p>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-3 text-center">
                                <p class="text-lg font-black text-gray-900">{{ $role->users_count }}</p>
                                <p class="text-xs text-gray-500">مستخدم</p>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            @if(in_array($role->id, $manageableRoleIds, true))
                                <a href="{{ route('admin.roles.edit', $role) }}" class="flex-1 rounded-lg bg-indigo-600 px-3 py-2 text-center text-xs font-black text-white hover:bg-indigo-700">تعديل الصلاحيات</a>
                                <form action="{{ route('admin.roles.duplicate', $role) }}" method="POST">
                                    @csrf
                                    <button class="rounded-lg border border-indigo-200 px-3 py-2 text-xs font-black text-indigo-700 hover:bg-indigo-50">نسخ</button>
                                </form>
                                @unless($role->is_system)
                                    <form action="{{ route('admin.roles.destroy', $role) }}" method="POST" onsubmit="return confirm('حذف هذا الدور؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-lg border border-red-200 px-3 py-2 text-xs font-black text-red-600 hover:bg-red-50">حذف</button>
                                    </form>
                                @endunless
                            @else
                                <span class="text-xs font-bold text-gray-400">لا يمكنك تعديل دور يحتوي صلاحيات لا تملكها.</span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </div>
    </div>
</x-admin-layout>
