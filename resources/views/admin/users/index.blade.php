<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">إدارة المشرفين</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            <div class="flex items-center justify-between mb-6">
                <h3 class="text-lg font-bold text-gray-700">قائمة المشرفين ({{ $admins->count() }})</h3>
                <a href="{{ route('admin.users.create') }}"
                   class="inline-flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-lg hover:bg-indigo-700 transition text-sm">
                    + إضافة مشرف جديد
                </a>
            </div>

            <div class="bg-white shadow-sm rounded-xl overflow-hidden border border-gray-100">
                <table class="w-full text-right text-sm">
                    <thead class="bg-gray-50 border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-4 font-bold text-gray-600">الاسم</th>
                            <th class="px-6 py-4 font-bold text-gray-600">البريد الإلكتروني</th>
                            <th class="px-6 py-4 font-bold text-gray-600">تاريخ الإضافة</th>
                            <th class="px-6 py-4 font-bold text-gray-600 text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($admins as $admin)
                        <tr class="hover:bg-gray-50 transition {{ $admin->id === auth()->id() ? 'bg-indigo-50/40' : '' }}">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center font-black text-sm flex-shrink-0">
                                        {{ mb_substr($admin->name, 0, 1) }}
                                    </div>
                                    <div>
                                        <p class="font-bold text-gray-900">{{ $admin->name }}</p>
                                        @if($admin->id === auth()->id())
                                            <span class="text-[10px] bg-indigo-100 text-indigo-600 font-bold px-2 py-0.5 rounded-full">أنت</span>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-gray-600">{{ $admin->email }}</td>
                            <td class="px-6 py-4 text-gray-400 text-xs">{{ $admin->created_at->format('Y/m/d') }}</td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-3">
                                    <a href="{{ route('admin.users.edit', $admin) }}"
                                       class="text-indigo-600 hover:text-indigo-800 font-semibold text-xs border border-indigo-200 px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition">
                                        تعديل
                                    </a>
                                    @if($admin->id !== auth()->id())
                                    <form action="{{ route('admin.users.destroy', $admin) }}" method="POST"
                                          onsubmit="return confirm('هل أنت متأكد من حذف هذا المشرف؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="text-red-500 hover:text-red-700 font-semibold text-xs border border-red-200 px-3 py-1.5 rounded-lg hover:bg-red-50 transition">
                                            حذف
                                        </button>
                                    </form>
                                    @endif
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
