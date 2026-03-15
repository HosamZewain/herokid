<x-admin-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="text-xl font-bold text-gray-800">آراء العملاء</h2>
            <a href="{{ route('admin.testimonials.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold px-4 py-2 rounded-lg flex items-center gap-2 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                إضافة رأي
            </a>
        </div>
    </x-slot>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-100" dir="rtl">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase">الترتيب</th>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase">الصورة</th>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase">الاسم</th>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase">الرأي</th>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase">التقييم</th>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase">الحالة</th>
                    <th class="px-5 py-3 text-right text-xs font-bold text-gray-500 uppercase">إجراءات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($testimonials as $t)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-5 py-4 text-center text-sm text-gray-500">{{ $t->sort_order }}</td>
                        <td class="px-5 py-4">
                            @if($t->reviewer_avatar)
                                <img src="{{ $t->reviewer_avatar }}" alt="{{ $t->reviewer_name }}"
                                     class="w-10 h-10 rounded-full object-cover border-2 border-gray-100">
                            @else
                                <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-600 font-bold text-sm">
                                    {{ mb_substr($t->reviewer_name, 0, 1) }}
                                </div>
                            @endif
                        </td>
                        <td class="px-5 py-4">
                            <p class="font-bold text-sm text-gray-900">{{ $t->reviewer_name }}</p>
                            <p class="text-xs text-gray-400">{{ $t->reviewer_location }}</p>
                        </td>
                        <td class="px-5 py-4 max-w-xs">
                            <p class="text-sm text-gray-600 truncate">{{ $t->review_text }}</p>
                        </td>
                        <td class="px-5 py-4 text-yellow-500 text-sm">
                            {{ str_repeat('★', $t->rating) }}{{ str_repeat('☆', 5 - $t->rating) }}
                        </td>
                        <td class="px-5 py-4">
                            @if($t->active)
                                <span class="px-2 py-1 text-xs font-bold bg-green-100 text-green-700 rounded-full">نشط</span>
                            @else
                                <span class="px-2 py-1 text-xs font-bold bg-red-100 text-red-700 rounded-full">مخفي</span>
                            @endif
                        </td>
                        <td class="px-5 py-4 text-sm">
                            <div class="flex gap-3">
                                <a href="{{ route('admin.testimonials.edit', $t) }}" class="text-indigo-600 hover:text-indigo-800 font-semibold">تعديل</a>
                                <form action="{{ route('admin.testimonials.destroy', $t) }}" method="POST" class="inline">
                                    @csrf @method('DELETE')
                                    <button type="submit" onclick="return confirm('حذف هذا الرأي؟')" class="text-red-500 hover:text-red-700 font-semibold">حذف</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-12 text-center text-gray-400">
                            <div class="text-4xl mb-2">💬</div>
                            <p class="text-sm">لا توجد آراء بعد. أضف أول رأي للعملاء.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-admin-layout>
