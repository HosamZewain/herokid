<x-admin-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.testimonials.index') }}" class="text-gray-400 hover:text-gray-600 transition">← العودة</a>
            <h2 class="text-xl font-bold text-gray-800">إضافة رأي عميل</h2>
        </div>
    </x-slot>

    <div class="max-w-2xl bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <form action="{{ route('admin.testimonials.store') }}" method="POST">
            @csrf
            <div class="space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">اسم العميل <span class="text-red-500">*</span></label>
                        <input type="text" name="reviewer_name" value="{{ old('reviewer_name') }}" required
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <x-input-error :messages="$errors->get('reviewer_name')" class="mt-1"/>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">المدينة</label>
                        <input type="text" name="reviewer_location" value="{{ old('reviewer_location') }}"
                               placeholder="مثال: القاهرة"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">رابط الصورة الشخصية</label>
                    <input type="url" name="reviewer_avatar" value="{{ old('reviewer_avatar') }}"
                           placeholder="https://... (اتركه فارغاً للاستخدام الأحرف الأولى)"
                           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    <p class="text-xs text-gray-400 mt-1">يمكن استخدام: https://i.pravatar.cc/80?img=1 (غيّر الرقم من 1 إلى 70)</p>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">نص الرأي <span class="text-red-500">*</span></label>
                    <textarea name="review_text" rows="4" required maxlength="1000"
                              class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ old('review_text') }}</textarea>
                    <x-input-error :messages="$errors->get('review_text')" class="mt-1"/>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">التقييم <span class="text-red-500">*</span></label>
                        <select name="rating" class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                            @for($i = 5; $i >= 1; $i--)
                                <option value="{{ $i }}" {{ old('rating', 5) == $i ? 'selected' : '' }}>
                                    {{ str_repeat('★', $i) }} ({{ $i }} نجوم)
                                </option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">الترتيب</label>
                        <input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0"
                               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <input type="checkbox" name="active" id="active" value="1" checked
                           class="h-4 w-4 text-indigo-600 rounded border-gray-300">
                    <label for="active" class="text-sm font-semibold text-gray-700">نشط (يظهر في الموقع)</label>
                </div>
            </div>

            <div class="mt-6 flex gap-3 border-t pt-5">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2 rounded-lg transition">
                    إضافة الرأي
                </button>
                <a href="{{ route('admin.testimonials.index') }}" class="px-6 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-50 font-semibold transition">
                    إلغاء
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
