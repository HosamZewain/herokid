<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-gray-900">إضافة معاينة كتاب</h2>
            <p class="mt-1 text-xs font-bold text-gray-500">ارفع ملف PDF مرتب الصفحات لتحصل على رابط قارئ خاص.</p>
        </div>
    </x-slot>

    <div class="mx-auto max-w-3xl py-5 sm:py-8" dir="rtl">
        <form action="{{ route('admin.booklet-previews.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5 rounded-3xl border border-gray-100 bg-white p-5 shadow-sm sm:p-8">
            @csrf
            @if($errors->any())
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700"><ul class="list-inside list-disc space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-2 block text-sm font-black text-gray-700">نوع المعاينة</span>
                    <select name="source_type" required data-preview-source class="w-full rounded-xl border-gray-200">
                        <option value="story" @selected(old('source_type', 'story') === 'story')>مرتبطة بقصة</option>
                        <option value="standalone" @selected(old('source_type') === 'standalone')>معاينة مستقلة</option>
                    </select>
                </label>
                <label class="block" data-story-field>
                    <span class="mb-2 block text-sm font-black text-gray-700">القصة</span>
                    <select name="story_id" class="w-full rounded-xl border-gray-200">
                        <option value="">اختر القصة</option>
                        @foreach($stories as $story)<option value="{{ $story->id }}" data-language="{{ $story->language }}" @selected((string) old('story_id') === (string) $story->id)>{{ $story->title }}</option>@endforeach
                    </select>
                </label>
            </div>

            <label class="block">
                <span class="mb-2 block text-sm font-black text-gray-700">عنوان المعاينة</span>
                <input name="title" value="{{ old('title') }}" required maxlength="255" class="w-full rounded-xl border-gray-200" placeholder="مثال: معاينة قصة بطل الفضاء الصغير">
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-2 block text-sm font-black text-gray-700">اتجاه القراءة</span>
                    <select name="reading_direction" class="w-full rounded-xl border-gray-200">
                        <option value="rtl" @selected(old('reading_direction', 'rtl') === 'rtl')>من اليمين إلى اليسار — عربي</option>
                        <option value="ltr" @selected(old('reading_direction') === 'ltr')>من اليسار إلى اليمين — English</option>
                    </select>
                </label>
                <label class="block">
                    <span class="mb-2 block text-sm font-black text-gray-700">ملف PDF</span>
                    <input type="file" name="pdf_file" accept="application/pdf,.pdf" required class="block w-full rounded-xl border border-gray-200 p-2 text-sm file:ml-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:font-black file:text-white">
                    <span class="mt-1 block text-xs text-gray-500">حتى {{ $maxUploadMb }} ميجا و{{ $maxPages }} صفحة. ملفات PDF المشفرة غير مدعومة.</span>
                </label>
            </div>

            <label class="block">
                <span class="mb-2 block text-sm font-black text-gray-700">ملاحظة داخلية (اختياري)</span>
                <textarea name="note" rows="3" maxlength="1000" class="w-full rounded-xl border-gray-200">{{ old('note') }}</textarea>
            </label>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
                <a href="{{ route('admin.booklet-previews.index') }}" class="rounded-xl bg-slate-100 px-5 py-3 text-center text-sm font-black text-slate-700">إلغاء</a>
                <button class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">رفع وإنشاء الرابط</button>
            </div>
        </form>
    </div>

    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const source = document.querySelector('[data-preview-source]');
        const storyField = document.querySelector('[data-story-field]');
        if (!source || !storyField) return;
        const sync = () => storyField.classList.toggle('hidden', source.value !== 'story');
        source.addEventListener('change', sync); sync();
    });
    </script>
    @endpush
</x-admin-layout>
