<x-admin-layout>
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold">إدارة الأسئلة الشائعة</h2>
        <a href="{{ route('admin.faqs.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded shadow-sm flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
            إضافة سؤال
        </a>
    </div>

    @if(session('success'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded relative mb-4">
            {{ $errors->first() }}
        </div>
    @endif

    <form id="faq-bulk-delete-form" action="{{ route('admin.faqs.bulk-destroy') }}" method="POST"
        onsubmit="return confirm('هل أنت متأكد من حذف الأسئلة المحددة؟');">
        @csrf
        @method('DELETE')
    </form>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-gray-100 px-6 py-4" dir="rtl">
            <p class="text-sm text-gray-500">اختر أكثر من سؤال ثم احذفهم دفعة واحدة.</p>
            <button type="submit"
                form="faq-bulk-delete-form"
                class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-red-700">
                حذف المحدد
            </button>
        </div>
        <table class="min-w-full divide-y divide-gray-200" dir="rtl">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <input type="checkbox" data-faq-select-all
                            class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            aria-label="تحديد كل الأسئلة">
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الترتيب</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">السؤال</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">الحالة</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">إجراءات</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($faqs as $faq)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <input type="checkbox"
                                name="faq_ids[]"
                                value="{{ $faq->id }}"
                                form="faq-bulk-delete-form"
                                data-faq-row-checkbox
                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                aria-label="تحديد سؤال {{ $faq->question }}">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                            {{ $faq->sort_order }}
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm font-bold text-gray-900">{{ $faq->question }}</div>
                            <div class="text-sm text-gray-500 truncate max-w-md">{{ Str::limit($faq->answer, 100) }}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            @if($faq->active)
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">نشط</span>
                            @else
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">معطل</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium flex gap-3">
                            <a href="{{ route('admin.faqs.edit', $faq) }}" class="text-indigo-600 hover:text-indigo-900">تعديل</a>
                            <form action="{{ route('admin.faqs.destroy', $faq) }}" method="POST" class="inline-block">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:text-red-900" onclick="return confirm('هل متأكد من حذف هذا السؤال؟')">حذف</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">لا توجد أسئلة مضافة بعد.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var selectAll = document.querySelector('[data-faq-select-all]');
                var checkboxes = Array.prototype.slice.call(document.querySelectorAll('[data-faq-row-checkbox]'));

                if (!selectAll || checkboxes.length === 0) {
                    return;
                }

                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = selectAll.checked;
                    });
                });

                checkboxes.forEach(function (checkbox) {
                    checkbox.addEventListener('change', function () {
                        var checkedCount = checkboxes.filter(function (item) {
                            return item.checked;
                        }).length;

                        selectAll.checked = checkedCount === checkboxes.length;
                        selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
                    });
                });
            });
        </script>
    @endpush
</x-admin-layout>
