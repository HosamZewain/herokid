@php($active = filter_var($template['is_active'] ?? true, FILTER_VALIDATE_BOOL))
<section data-template-row class="rounded-3xl border border-gray-100 bg-white p-5 shadow-sm">
    <input type="hidden" name="templates[{{ $index }}][id]" value="{{ $template['id'] ?? '' }}">
    <div class="grid gap-4 md:grid-cols-6">
        <div class="md:col-span-3">
            <label class="mb-1.5 block text-xs font-black text-gray-600">عنوان زر الرسالة</label>
            <input name="templates[{{ $index }}][title]" value="{{ $template['title'] ?? '' }}" required maxlength="100" class="w-full rounded-xl border-gray-200 text-right text-sm" placeholder="مثال: إرسال المعاينة للعميل">
        </div>
        <div>
            <label class="mb-1.5 block text-xs font-black text-gray-600">الترتيب</label>
            <input name="templates[{{ $index }}][sort_order]" type="number" min="0" max="9999" value="{{ $template['sort_order'] ?? (($index + 1) * 10) }}" required class="w-full rounded-xl border-gray-200 text-sm" dir="ltr">
        </div>
        <div class="flex items-end">
            <label class="flex min-h-11 w-full items-center gap-2 rounded-xl bg-emerald-50 px-3 text-xs font-black text-emerald-800">
                <input type="hidden" name="templates[{{ $index }}][is_active]" value="0">
                <input name="templates[{{ $index }}][is_active]" type="checkbox" value="1" @checked($active) class="rounded border-emerald-300 text-emerald-600">
                إظهار الزر
            </label>
        </div>
        <div class="flex items-end">
            <button type="button" data-remove-template class="min-h-11 w-full rounded-xl bg-rose-50 px-3 text-xs font-black text-rose-700">حذف</button>
        </div>
        <div class="md:col-span-6">
            <label class="mb-1.5 block text-xs font-black text-gray-600">نص الرسالة</label>
            <textarea name="templates[{{ $index }}][message]" rows="5" required maxlength="4000" class="w-full rounded-xl border-gray-200 text-right text-sm leading-7" placeholder="اكتب الرسالة واستعمل المتغيرات المتاحة">{{ $template['message'] ?? '' }}</textarea>
            @error("templates.$index.message")<p class="mt-1 text-xs font-bold text-rose-600">{{ $message }}</p>@enderror
        </div>
    </div>
</section>
