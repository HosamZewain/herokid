<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('تعديل القصة') }} - {{ $story->title }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                
                <form action="{{ route('admin.stories.update', $story) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    @method('PUT')
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Title -->
                        <div>
                            <x-input-label for="title" :value="__('عنوان القصة')" />
                            <x-text-input id="title" class="block mt-1 w-full" type="text" name="title" :value="old('title', $story->title)" required autofocus />
                            <x-input-error :messages="$errors->get('title')" class="mt-2" />
                        </div>

                        <!-- Slug -->
                        <div>
                            <x-input-label for="slug" :value="__('الرابط المختصر (Slug)')" />
                            <x-text-input id="slug" class="block mt-1 w-full" type="text" name="slug" :value="old('slug', $story->slug)" required />
                            <x-input-error :messages="$errors->get('slug')" class="mt-2" />
                        </div>

                        <!-- Price -->
                        <div>
                            <x-input-label for="price" :value="__('السعر')" />
                            <x-text-input id="price" class="block mt-1 w-full" type="number" step="0.01" name="price" :value="old('price', $story->price)" required />
                            <x-input-error :messages="$errors->get('price')" class="mt-2" />
                        </div>

                        <!-- Age Range -->
                        @php
                            $ageRangeOptions = json_decode($settings['age_ranges'] ?? '[]', true) ?: [
                                '٢ - ٤ سنوات','٢ - ٦ سنوات','٣ - ٥ سنوات','٣ - ٦ سنوات','٣ - ٧ سنوات',
                                '٤ - ٦ سنوات','٤ - ٨ سنوات','٥ - ٧ سنوات','٥ - ٨ سنوات','٥ - ١٠ سنوات',
                                '٦ - ٨ سنوات','٦ - ١٠ سنوات','٧ - ١٠ سنوات','٨ - ١٢ سنوات',
                            ];
                        @endphp
                        <div>
                            <x-input-label for="age_range" :value="__('الفئة العمرية')" />
                            <select id="age_range" name="age_range" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="">-- اختر الفئة العمرية --</option>
                                @foreach($ageRangeOptions as $range)
                                <option value="{{ $range }}" @selected(old('age_range', $story->age_range) === $range)>{{ $range }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('age_range')" class="mt-2" />
                        </div>

                        <!-- Language -->
                        <div>
                            <x-input-label for="language" :value="__('اللغة')" />
                            <select id="language" name="language" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="ar" @selected(old('language', $story->language) == 'ar')>العربية</option>
                                <option value="en" @selected(old('language', $story->language) == 'en')>الإنجليزية</option>
                            </select>
                            <x-input-error :messages="$errors->get('language')" class="mt-2" />
                        </div>

                        <!-- Lesson -->
                        <div>
                            <x-input-label for="lesson_value" :value="__('الدرس المستفاد')" />
                            <x-text-input id="lesson_value" class="block mt-1 w-full" type="text" name="lesson_value" :value="old('lesson_value', $story->lesson_value)" placeholder="مثال: الشجاعة، الصدق" />
                            <x-input-error :messages="$errors->get('lesson_value')" class="mt-2" />
                        </div>

                        <!-- Gender -->
                        <div>
                            <x-input-label for="gender" :value="__('مناسبة لـ')" />
                            <select id="gender" name="gender" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="both" @selected(old('gender', $story->gender ?? 'both') == 'both')>للجنسين (ولد وبنت)</option>
                                <option value="boy" @selected(old('gender', $story->gender) == 'boy')>👦 ولد فقط</option>
                                <option value="girl" @selected(old('gender', $story->gender) == 'girl')>👧 بنت فقط</option>
                            </select>
                            <x-input-error :messages="$errors->get('gender')" class="mt-2" />
                        </div>
                    </div>

                    <!-- Categories -->
                    <div class="mt-6">
                        <x-input-label :value="__('التصنيفات')" />
                        @if($categories->isNotEmpty())
                        @php $selectedIds = old('category_ids', $story->categories->pluck('id')->toArray()); @endphp
                        <div class="mt-2 flex flex-wrap gap-2 p-4 border border-gray-300 rounded-md bg-gray-50">
                            @foreach($categories as $cat)
                            @php $checked = in_array($cat->id, $selectedIds); @endphp
                            <label class="inline-flex items-center gap-2 cursor-pointer px-3 py-1.5 rounded-full border transition
                                          {{ $checked ? 'bg-indigo-600 border-indigo-600 text-white' : 'bg-white border-gray-300 text-gray-700 hover:border-indigo-400' }}">
                                <input type="checkbox" name="category_ids[]" value="{{ $cat->id }}" class="sr-only"
                                       {{ $checked ? 'checked' : '' }}
                                       onchange="this.closest('label').className = this.checked
                                           ? 'inline-flex items-center gap-2 cursor-pointer px-3 py-1.5 rounded-full border transition bg-indigo-600 border-indigo-600 text-white'
                                           : 'inline-flex items-center gap-2 cursor-pointer px-3 py-1.5 rounded-full border transition bg-white border-gray-300 text-gray-700 hover:border-indigo-400'">
                                <span class="text-sm font-semibold">{{ $cat->name }}</span>
                            </label>
                            @endforeach
                        </div>
                        @else
                        <p class="mt-2 text-sm text-gray-500">لا توجد تصنيفات. <a href="{{ route('admin.categories.index') }}" class="text-indigo-600 underline font-semibold">أضف تصنيفات أولاً</a></p>
                        @endif
                        <x-input-error :messages="$errors->get('category_ids')" class="mt-2" />
                    </div>

                    <!-- Short Description -->
                    <div class="mt-6">
                        <x-input-label for="short_desc" :value="__('وصف قصير')" />
                        <textarea id="short_desc" name="short_desc" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" rows="3">{{ old('short_desc', $story->short_desc) }}</textarea>
                        <x-input-error :messages="$errors->get('short_desc')" class="mt-2" />
                    </div>

                    <!-- Full Description -->
                    <div class="mt-6">
                        <x-input-label for="full_desc" :value="__('وصف كامل')" />
                        <textarea id="full_desc" name="full_desc" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" rows="6">{{ old('full_desc', $story->full_desc) }}</textarea>
                        <x-input-error :messages="$errors->get('full_desc')" class="mt-2" />
                    </div>

                    <!-- Full Story (Rich Text Editor - internal only) -->
                    <div class="mt-6">
                        <x-input-label for="full_story" :value="__('القصة الكاملة')" />
                        <p class="text-xs text-gray-400 mt-0.5 mb-2">للاستخدام الداخلي فقط — لا يظهر على الموقع</p>
                        <textarea id="full_story" name="full_story" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm" rows="16">{{ old('full_story', $story->full_story) }}</textarea>
                        <x-input-error :messages="$errors->get('full_story')" class="mt-2" />
                    </div>

                    <!-- Prompt (internal only) -->
                    <div class="mt-6">
                        <x-input-label for="prompt" :value="__('البرومبت (Prompt)')" />
                        <p class="text-xs text-gray-400 mt-0.5 mb-2">للاستخدام الداخلي فقط — لا يظهر على الموقع</p>
                        <textarea id="prompt" name="prompt" dir="ltr" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm font-mono text-sm" rows="8" placeholder="Enter the AI prompt here...">{{ old('prompt', $story->prompt) }}</textarea>
                        <x-input-error :messages="$errors->get('prompt')" class="mt-2" />
                    </div>

                    <!-- Cover Image -->
                    <div class="mt-6">
                        <x-input-label for="cover_image" :value="__('تحديث صورة الغلاف (اختياري)')" />
                        <input id="cover_image" class="block mt-1 w-full text-sm text-gray-900 border border-gray-300 rounded-md cursor-pointer bg-gray-50 focus:outline-none" type="file" name="cover_image" accept="image/*">
                        @if($story->cover_image)
                            <div class="mt-2">
                                <p class="text-sm text-gray-500">الصورة الحالية:</p>
                                <img src="{{ $story->cover_url }}" alt="Cover" class="h-32 rounded mt-1">
                            </div>
                        @endif
                        <x-input-error :messages="$errors->get('cover_image')" class="mt-2" />
                    </div>

                    <!-- Status -->
                    <div class="block mt-6">
                        <label for="active" class="inline-flex items-center">
                            <input id="active" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="active" value="1" {{ old('active', $story->active) ? 'checked' : '' }}>
                            <span class="ms-2 text-sm text-gray-600">{{ __('نشط (متاح للجمهور)') }}</span>
                        </label>
                    </div>

                    <div class="flex items-center justify-end mt-6 gap-4">
                        <a href="{{ route('admin.stories.index') }}" class="text-gray-600 hover:text-gray-900">إلغاء</a>
                        <x-primary-button>
                            {{ __('تحديث القصة') }}
                        </x-primary-button>
                    </div>
                </form>

            </div>

            {{-- ===== ATTACHMENTS SECTION ===== --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6 mt-6">
                <div class="flex items-center justify-between mb-5 pb-4 border-b border-gray-100">
                    <div>
                        <h3 class="text-base font-bold text-gray-900">📎 المرفقات الداخلية</h3>
                        <p class="text-xs text-gray-400 mt-0.5">للاستخدام الداخلي فقط — لا تظهر على الموقع</p>
                    </div>
                    <span class="text-xs bg-gray-100 text-gray-500 font-bold px-3 py-1.5 rounded-full">
                        {{ $story->attachments->count() }} مرفق
                    </span>
                </div>

                {{-- Existing Attachments --}}
                @if($story->attachments->isNotEmpty())
                <div class="space-y-2 mb-6">
                    @foreach($story->attachments as $attachment)
                    <div class="flex items-center justify-between bg-gray-50 border border-gray-100 rounded-xl px-4 py-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-2xl flex-shrink-0">{{ $attachment->icon }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-800 truncate">{{ $attachment->original_name }}</p>
                                <p class="text-xs text-gray-400">{{ $attachment->human_size }} · {{ $attachment->created_at->format('Y/m/d H:i') }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0 mr-3">
                            <a href="{{ route('admin.attachments.download', $attachment) }}"
                               class="text-indigo-600 hover:text-indigo-800 text-xs font-bold border border-indigo-200 px-3 py-1.5 rounded-lg hover:bg-indigo-50 transition">
                                تحميل
                            </a>
                            <form action="{{ route('admin.attachments.destroy', $attachment) }}" method="POST"
                                  onsubmit="return confirm('هل أنت متأكد من حذف هذا المرفق؟')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                        class="text-red-500 hover:text-red-700 text-xs font-bold border border-red-200 px-3 py-1.5 rounded-lg hover:bg-red-50 transition">
                                    حذف
                                </button>
                            </form>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- Upload New Attachments --}}
                <form action="{{ route('admin.stories.attachments.store', $story) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div id="attachment-drop-zone"
                         class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center hover:border-indigo-400 hover:bg-indigo-50/30 transition-all cursor-pointer"
                         onclick="document.getElementById('attachment-input').click()">
                        <div class="text-4xl mb-3">📂</div>
                        <p class="text-sm font-bold text-gray-600 mb-1">اضغط لاختيار الملفات أو اسحبها هنا</p>
                        <p class="text-xs text-gray-400">PDF, Word, Excel, صور، ZIP — حجم أقصى 20 MB لكل ملف</p>
                        <input id="attachment-input" type="file" name="attachments[]" multiple class="hidden"
                               onchange="updateFileList(this)">
                    </div>

                    {{-- Selected files preview --}}
                    <div id="file-list" class="mt-3 space-y-1 hidden"></div>

                    <div class="mt-4 flex justify-end">
                        <button type="submit" id="upload-btn"
                                class="hidden bg-indigo-600 text-white font-bold px-6 py-2.5 rounded-lg hover:bg-indigo-700 transition text-sm">
                            ⬆️ رفع المرفقات
                        </button>
                    </div>
                    <x-input-error :messages="$errors->get('attachments')" class="mt-2" />
                    <x-input-error :messages="$errors->get('attachments.*')" class="mt-1" />
                </form>
            </div>

        </div>
    </div>
@push('scripts')
<script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
    selector: '#full_story',
    language: 'ar',
    directionality: 'rtl',
    height: 500,
    menubar: true,
    plugins: 'anchor autolink lists link image charmap preview searchreplace visualblocks code fullscreen insertdatetime table wordcount',
    toolbar: 'undo redo | formatselect | bold italic underline | alignright aligncenter alignleft alignjustify | bullist numlist outdent indent | removeformat | code fullscreen',
    content_style: 'body { font-family: Cairo, Tahoma, sans-serif; font-size: 16px; direction: rtl; text-align: right; }',
    setup: function(editor) {
        editor.on('change', function() {
            editor.save();
        });
    }
});
</script>

<script>
// File list preview + drag & drop for attachments
function updateFileList(input) {
    const list  = document.getElementById('file-list');
    const btn   = document.getElementById('upload-btn');
    const files = Array.from(input.files);

    list.innerHTML = '';
    if (!files.length) { list.classList.add('hidden'); btn.classList.add('hidden'); return; }

    list.classList.remove('hidden');
    btn.classList.remove('hidden');

    files.forEach(file => {
        const kb   = (file.size / 1024).toFixed(1);
        const size = file.size >= 1048576 ? (file.size/1048576).toFixed(1)+' MB' : kb+' KB';
        const row  = document.createElement('div');
        row.className = 'flex items-center gap-2 text-xs text-gray-600 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2';
        row.innerHTML = `<span class="text-base">📎</span><span class="truncate flex-1 font-medium">${file.name}</span><span class="text-gray-400 flex-shrink-0">${size}</span>`;
        list.appendChild(row);
    });
}

// Drag & drop support
const dropZone = document.getElementById('attachment-drop-zone');
if (dropZone) {
    ['dragenter','dragover'].forEach(e => dropZone.addEventListener(e, ev => {
        ev.preventDefault(); dropZone.classList.add('border-indigo-500','bg-indigo-50');
    }));
    ['dragleave','drop'].forEach(e => dropZone.addEventListener(e, ev => {
        ev.preventDefault(); dropZone.classList.remove('border-indigo-500','bg-indigo-50');
    }));
    dropZone.addEventListener('drop', ev => {
        const input = document.getElementById('attachment-input');
        input.files = ev.dataTransfer.files;
        updateFileList(input);
    });
}
</script>
@endpush
</x-admin-layout>
