<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('إضافة قصة جديدة') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                
                <form action="{{ route('admin.stories.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Title -->
                        <div>
                            <x-input-label for="title" :value="__('عنوان القصة')" />
                            <x-text-input id="title" class="block mt-1 w-full" type="text" name="title" :value="old('title')" required autofocus />
                            <x-input-error :messages="$errors->get('title')" class="mt-2" />
                        </div>

                        <!-- Slug -->
                        <div>
                            <x-input-label for="slug" :value="__('الرابط المختصر (Slug)')" />
                            <x-text-input id="slug" class="block mt-1 w-full" type="text" name="slug" :value="old('slug')" required />
                            <x-input-error :messages="$errors->get('slug')" class="mt-2" />
                        </div>

                        <!-- Price -->
                        <div>
                            <x-input-label for="price" :value="__('السعر')" />
                            <x-text-input id="price" class="block mt-1 w-full" type="number" step="0.01" name="price" :value="old('price', 0)" required />
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
                                <option value="{{ $range }}" @selected(old('age_range') === $range)>{{ $range }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('age_range')" class="mt-2" />
                        </div>

                        <!-- Language -->
                        <div>
                            <x-input-label for="language" :value="__('اللغة')" />
                            <select id="language" name="language" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="ar" @selected(old('language') == 'ar')>العربية</option>
                                <option value="en" @selected(old('language') == 'en')>الإنجليزية</option>
                            </select>
                            <x-input-error :messages="$errors->get('language')" class="mt-2" />
                        </div>

                        <!-- Lesson -->
                        <div>
                            <x-input-label for="lesson_value" :value="__('الدرس المستفاد')" />
                            <x-text-input id="lesson_value" class="block mt-1 w-full" type="text" name="lesson_value" :value="old('lesson_value')" placeholder="مثال: الشجاعة، الصدق" />
                            <x-input-error :messages="$errors->get('lesson_value')" class="mt-2" />
                        </div>

                        <!-- Gender -->
                        <div>
                            <x-input-label for="gender" :value="__('مناسبة لـ')" />
                            <select id="gender" name="gender" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                <option value="both" @selected(old('gender', 'both') == 'both')>للجنسين (ولد وبنت)</option>
                                <option value="boy" @selected(old('gender') == 'boy')>👦 ولد فقط</option>
                                <option value="girl" @selected(old('gender') == 'girl')>👧 بنت فقط</option>
                            </select>
                            <x-input-error :messages="$errors->get('gender')" class="mt-2" />
                        </div>
                    </div>

                    <!-- Categories -->
                    <div class="mt-6">
                        <x-input-label :value="__('التصنيفات')" />
                        @if($categories->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-2 p-4 border border-gray-300 rounded-md bg-gray-50">
                            @foreach($categories as $cat)
                            @php $checked = in_array($cat->id, old('category_ids', [])); @endphp
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
                        <textarea id="short_desc" name="short_desc" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" rows="3">{{ old('short_desc') }}</textarea>
                        <x-input-error :messages="$errors->get('short_desc')" class="mt-2" />
                    </div>

                    <!-- Full Description -->
                    <div class="mt-6">
                        <x-input-label for="full_desc" :value="__('وصف كامل')" />
                        <textarea id="full_desc" name="full_desc" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" rows="6">{{ old('full_desc') }}</textarea>
                        <x-input-error :messages="$errors->get('full_desc')" class="mt-2" />
                    </div>

                    <!-- Full Story (Rich Text Editor - internal only) -->
                    <div class="mt-6">
                        <x-input-label for="full_story" :value="__('القصة الكاملة')" />
                        <p class="text-xs text-gray-400 mt-0.5 mb-2">للاستخدام الداخلي فقط — لا يظهر على الموقع</p>
                        <textarea id="full_story" name="full_story" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm" rows="16">{{ old('full_story') }}</textarea>
                        <x-input-error :messages="$errors->get('full_story')" class="mt-2" />
                    </div>

                    <!-- Prompt (internal only) -->
                    <div class="mt-6">
                        <x-input-label for="prompt" :value="__('البرومبت (Prompt)')" />
                        <p class="text-xs text-gray-400 mt-0.5 mb-2">للاستخدام الداخلي فقط — لا يظهر على الموقع</p>
                        <textarea id="prompt" name="prompt" dir="ltr" class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm font-mono text-sm" rows="8" placeholder="Enter the AI prompt here...">{{ old('prompt') }}</textarea>
                        <x-input-error :messages="$errors->get('prompt')" class="mt-2" />
                    </div>

                    <!-- Cover Image -->
                    <div class="mt-6">
                        <x-input-label for="cover_image" :value="__('صورة الغلاف')" />
                        <input id="cover_image" class="block mt-1 w-full text-sm text-gray-900 border border-gray-300 rounded-md cursor-pointer bg-gray-50 focus:outline-none" type="file" name="cover_image" accept="image/*">
                        <x-input-error :messages="$errors->get('cover_image')" class="mt-2" />
                    </div>

                    <!-- Status -->
                    <div class="block mt-6">
                        <label for="active" class="inline-flex items-center">
                            <input id="active" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="active" value="1" {{ old('active', true) ? 'checked' : '' }}>
                            <span class="ms-2 text-sm text-gray-600">{{ __('نشط (متاح للجمهور)') }}</span>
                        </label>
                    </div>

                    <div class="flex items-center justify-end mt-6 gap-4">
                        <a href="{{ route('admin.stories.index') }}" class="text-gray-600 hover:text-gray-900">إلغاء</a>
                        <x-primary-button>
                            {{ __('حفظ القصة') }}
                        </x-primary-button>
                    </div>
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
        // Sync TinyMCE content back to the textarea before form submit
        editor.on('change', function() {
            editor.save();
        });
    }
});
</script>
@endpush
</x-admin-layout>
