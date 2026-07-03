<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">إدارة المشرفين والصلاحيات</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-700">قائمة المشرفين ({{ $admins->count() }})</h3>
                @can('admin_users.create')
                    @can('admin_users.permissions.manage')
                        <a href="{{ route('admin.users.create') }}"
                           class="inline-flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition text-sm">
                            + إضافة مشرف جديد
                        </a>
                    @endcan
                @endcan
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm">
                <table class="w-full text-right text-sm">
                    <thead class="border-b border-gray-100 bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 font-bold text-gray-600">الاسم</th>
                            <th class="px-6 py-4 font-bold text-gray-600">البريد الإلكتروني</th>
                            <th class="px-6 py-4 font-bold text-gray-600">الحالة</th>
                            <th class="px-6 py-4 font-bold text-gray-600">الصلاحيات</th>
                            <th class="px-6 py-4 font-bold text-gray-600">تاريخ الإضافة</th>
                            <th class="px-6 py-4 text-center font-bold text-gray-600">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($admins as $admin)
                            @php
                                $permissionKeys = $admin->permissions->pluck('key')->sort()->values();
                            @endphp
                            <tr class="transition hover:bg-gray-50 {{ $admin->id === auth()->id() ? 'bg-indigo-50/40' : '' }}">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-black text-indigo-700">
                                            {{ mb_substr($admin->name, 0, 1) }}
                                        </div>
                                        <div>
                                            <p class="font-bold text-gray-900">{{ $admin->name }}</p>
                                            @if($admin->id === auth()->id())
                                                <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-600">أنت</span>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-gray-600">{{ $admin->email }}</td>
                                <td class="px-6 py-4">
                                    @if($admin->is_active)
                                        <span class="rounded-full bg-green-50 px-3 py-1 text-xs font-black text-green-700">نشط</span>
                                    @else
                                        <span class="rounded-full bg-red-50 px-3 py-1 text-xs font-black text-red-700">موقوف</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <p class="font-black text-gray-900">{{ $permissionKeys->count() }} صلاحية</p>
                                    <p class="mt-1 max-w-xs truncate text-xs text-gray-400" dir="ltr">{{ $permissionKeys->take(5)->implode(', ') }}{{ $permissionKeys->count() > 5 ? '...' : '' }}</p>
                                </td>
                                <td class="px-6 py-4 text-xs text-gray-400">{{ $admin->created_at->format('Y/m/d') }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-3">
                                        @if($admin->id === auth()->id() || auth()->user()->hasAnyPermission(['admin_users.update', 'admin_users.permissions.manage']))
                                            <a href="{{ route('admin.users.edit', $admin) }}"
                                               class="rounded-lg border border-indigo-200 px-3 py-1.5 text-xs font-semibold text-indigo-600 transition hover:bg-indigo-50 hover:text-indigo-800">
                                                تعديل
                                            </a>
                                        @endif
                                        @can('admin_users.delete')
                                            @if($admin->id !== auth()->id())
                                                <form action="{{ route('admin.users.destroy', $admin) }}" method="POST"
                                                      onsubmit="return confirm('هل أنت متأكد من حذف هذا المشرف؟')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit"
                                                            class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-500 transition hover:bg-red-50 hover:text-red-700">
                                                        حذف
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-admin-layout>
