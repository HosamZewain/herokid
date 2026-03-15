<x-admin-layout>
    <div class="mb-6 flex justify-between items-center">
        <h2 class="text-2xl font-bold border-r-4 border-indigo-600 pr-3">تعديل السؤال</h2>
        <a href="{{ route('admin.faqs.index') }}" class="text-gray-600 hover:text-gray-900 text-sm font-semibold">← العودة للأسئلة</a>
    </div>

    <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-6 max-w-2xl">
        <form action="{{ route('admin.faqs.update', $faq) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="space-y-6">
                <div>
                    <label for="question" class="block text-sm font-medium text-gray-700 mb-1">السؤال <span class="text-red-500">*</span></label>
                    <input type="text" name="question" id="question" value="{{ old('question', $faq->question) }}" required
                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <x-input-error :messages="$errors->get('question')" class="mt-2" />
                </div>

                <div>
                    <label for="answer" class="block text-sm font-medium text-gray-700 mb-1">الإجابة <span class="text-red-500">*</span></label>
                    <textarea name="answer" id="answer" rows="6" required
                              class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('answer', $faq->answer) }}</textarea>
                    <x-input-error :messages="$errors->get('answer')" class="mt-2" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-1">الترتيب</label>
                        <input type="number" name="sort_order" id="sort_order" value="{{ old('sort_order', $faq->sort_order) }}" min="0"
                               class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <p class="text-xs text-gray-500 mt-1">الأرقام الأقل تظهر أولاً</p>
                        <x-input-error :messages="$errors->get('sort_order')" class="mt-2" />
                    </div>

                    <div class="flex items-center pt-6">
                        <input type="checkbox" name="active" id="active" value="1"
                               {{ old('active', $faq->active) ? 'checked' : '' }}
                               class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                        <label for="active" class="mr-2 block text-sm font-medium text-gray-700">نشط (يظهر للزوار)</label>
                    </div>
                </div>
            </div>

            <div class="mt-8 border-t pt-5 flex gap-3">
                <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded shadow hover:bg-indigo-700 font-bold">
                    حفظ التعديلات
                </button>
                <a href="{{ route('admin.faqs.index') }}" class="px-6 py-2 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 font-semibold">
                    إلغاء
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
