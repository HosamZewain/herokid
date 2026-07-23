<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-black text-slate-900">إعدادات هويات الأطفال</h2></x-slot>

    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-4xl px-4 sm:px-6">
            @if(session('success'))<div class="mb-5 rounded-xl bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>@endif
            @if($errors->any())<div class="mb-5 rounded-xl bg-red-50 p-4 font-bold text-red-700">{{ $errors->first() }}</div>@endif

            <form method="POST" action="{{ route('admin.child-identities.settings.update') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                @csrf @method('PUT')
                <label class="flex items-center gap-3 rounded-xl bg-indigo-50 p-4 font-black text-indigo-900">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $values['enabled'])) class="rounded border-indigo-300 text-indigo-600">
                    تفعيل خدمة هويات الأطفال العامة
                </label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="text-sm font-bold text-slate-700">حجم الصورة
                        <select name="image_size" class="mt-2 w-full rounded-xl border-slate-300">
                            @foreach(['1536x1024', '1024x1536', '1024x1024'] as $size)
                                <option value="{{ $size }}" @selected(old('image_size', $values['size']) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700">الجودة
                        <select name="image_quality" class="mt-2 w-full rounded-xl border-slate-300">
                            @foreach(['low', 'medium', 'high'] as $quality)
                                <option value="{{ $quality }}" @selected(old('image_quality', $values['quality']) === $quality)>{{ $quality }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="text-sm font-bold text-slate-700 sm:col-span-2">نسخة البرومبت
                        <input name="prompt_version" value="{{ old('prompt_version', $values['version']) }}" class="mt-2 w-full rounded-xl border-slate-300" dir="ltr">
                    </label>
                    <label class="text-sm font-bold text-slate-700 sm:col-span-2">قالب character sheet
                        <textarea name="prompt_template" rows="12" class="mt-2 w-full rounded-xl border-slate-300 font-mono text-sm" dir="ltr">{{ old('prompt_template', $values['prompt']) }}</textarea>
                    </label>
                </div>
                <div class="rounded-xl bg-slate-50 p-4 text-sm leading-7 text-slate-600">
                    النموذج يُحل من إعداد OpenAI الافتراضي لقدرة <code>character_sheet</code> مع fallback إلى <code>gpt-image-2</code>.
                    حد العميل ثابت: {{ arabic_number($values['limit']) }} نتائج ناجحة. التكلفة الداخلية بالدولار فقط والخدمة مجانية للعميل.
                </div>
                <button class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white">حفظ الإعدادات</button>
            </form>
        </div>
    </div>
</x-admin-layout>
