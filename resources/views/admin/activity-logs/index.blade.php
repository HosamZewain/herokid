<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-bold text-gray-800">سجل نشاط الإدارة</h2>
            <p class="text-xs text-gray-500 mt-1">راجع عمليات الدخول، العرض، الإضافة، التعديل، والحذف داخل لوحة الإدارة.</p>
        </div>
    </x-slot>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-6">
        <form method="GET" action="{{ route('admin.activity-logs.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">بحث</label>
                <input type="text" name="q" value="{{ request('q') }}"
                    placeholder="وصف، مسار، IP..."
                    class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">المشرف</label>
                <select name="user_id" class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    <option value="">كل المشرفين</option>
                    @foreach($admins as $admin)
                        <option value="{{ $admin->id }}" @selected((string) request('user_id') === (string) $admin->id)>
                            {{ $admin->name }} — {{ $admin->email }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">نوع الإجراء</label>
                <select name="action" class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    <option value="">كل الإجراءات</option>
                    @foreach($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-lg transition">
                    فلترة
                </button>
                <a href="{{ route('admin.activity-logs.index') }}"
                    class="px-4 py-2.5 rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 font-bold transition">
                    إعادة
                </a>
            </div>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-right text-xs font-extrabold text-gray-500 uppercase">الوقت</th>
                        <th class="px-5 py-3 text-right text-xs font-extrabold text-gray-500 uppercase">المشرف</th>
                        <th class="px-5 py-3 text-right text-xs font-extrabold text-gray-500 uppercase">الإجراء</th>
                        <th class="px-5 py-3 text-right text-xs font-extrabold text-gray-500 uppercase">التفاصيل</th>
                        <th class="px-5 py-3 text-right text-xs font-extrabold text-gray-500 uppercase">IP</th>
                        <th class="px-5 py-3 text-right text-xs font-extrabold text-gray-500 uppercase"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($logs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-600">
                                <div class="font-bold text-gray-900">{{ app_datetime($log->created_at, 'Y-m-d') }}</div>
                                <div class="text-xs text-gray-400">{{ app_datetime($log->created_at, 'H:i:s') }}</div>
                            </td>
                            <td class="px-5 py-4 text-sm">
                                <div class="font-bold text-gray-900">{{ $log->user?->name ?? 'غير معروف' }}</div>
                                <div class="text-xs text-gray-400">{{ $log->user?->email }}</div>
                            </td>
                            <td class="px-5 py-4 whitespace-nowrap">
                                <span class="inline-flex px-2.5 py-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-extrabold">
                                    {{ $log->action }}
                                </span>
                                @if($log->route_name)
                                    <div class="text-[11px] text-gray-400 mt-1">{{ $log->route_name }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-700 max-w-xl">
                                <div class="font-semibold">{{ $log->description ?: 'بدون وصف' }}</div>
                                @if($log->subject_type && $log->subject_id)
                                    <div class="text-xs text-gray-400 mt-1">{{ class_basename($log->subject_type) }} #{{ $log->subject_id }}</div>
                                @endif
                            </td>
                            <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-500">{{ $log->ip_address }}</td>
                            <td class="px-5 py-4 whitespace-nowrap text-left">
                                <a href="{{ route('admin.activity-logs.show', $log) }}"
                                    class="text-indigo-600 hover:text-indigo-800 font-bold text-sm">
                                    عرض التفاصيل
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-gray-500">
                                لا توجد سجلات نشاط بعد.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-4 border-t border-gray-100">
            {{ $logs->links() }}
        </div>
    </div>
</x-admin-layout>
