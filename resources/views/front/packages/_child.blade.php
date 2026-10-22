@php $childFields = \App\Support\ProductPersonalizationSchema::enabledFields($childSchema); @endphp
<input type="hidden" name="shared_child" value="1">
@if($childFields)
    <section class="rounded-3xl border border-indigo-100 bg-white p-4 shadow-sm sm:p-6" data-package-child>
        <h2 class="text-xl font-black text-slate-950">بيانات الطفل — مرة واحدة لكل الباكدج</h2>
        <p class="mt-2 text-sm text-slate-600">كل القصص والمنتجات لنفس الطفل. يستخدم كل منتج بياناته المطلوبة فقط؛ المنتجات التي تحتاج صورة واحدة تستخدم أول صورة بالترتيب.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach($childFields as $key => $field)
                @if($key === 'photos')
                    <div class="sm:col-span-2" data-package-uploader data-slot="0">
                        <label class="block rounded-2xl border border-dashed border-indigo-300 p-4">
                            <span class="block font-bold">صور الطفل {{ $field['required'] ? '*' : '(اختياري)' }}</span>
                            <span class="block text-sm text-slate-500">{{ $field['required'] ? 'من '.$field['min_files'].' إلى' : 'حتى' }} {{ $field['max_files'] }} صور · {{ $photoUploadConfig['maxSizeMb'] }} ميجا للصورة</span>
                            <input type="file" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" class="mt-2 block w-full" data-package-photo-input>
                            <span class="mt-1 hidden text-xs text-red-600" data-package-photo-error></span>
                        </label>
                        <div class="mt-2 flex flex-wrap gap-2" data-package-photo-previews></div>
                        <div data-package-photo-hidden></div>
                    </div>
                @else
                    <label class="block">
                        <span class="mb-1 block text-sm font-bold">{{ $field['label'] }} {{ $field['required'] ? '*' : '(اختياري)' }}</span>
                        @if($field['type'] === 'age')
                            <select name="child[{{ $key }}]" @required($field['required']) class="w-full rounded-xl border-slate-300" data-package-required>
                                <option value="">اختر العمر</option>
                                @foreach(\App\Support\StoryAgeOptions::forPersonalization() as $age)<option value="{{ $age }}" @selected((string) old('child.'.$key) === (string) $age)>{{ $age }} سنوات</option>@endforeach
                            </select>
                        @elseif($field['type'] === 'gender')
                            <select name="child[{{ $key }}]" @required($field['required']) class="w-full rounded-xl border-slate-300" data-package-required>
                                <option value="">اختر الجنس</option><option value="boy" @selected(old('child.'.$key) === 'boy')>ولد</option><option value="girl" @selected(old('child.'.$key) === 'girl')>بنت</option>
                            </select>
                        @elseif($field['type'] === 'textarea')
                            <textarea name="child[{{ $key }}]" @required($field['required']) maxlength="{{ \App\Support\ProductPersonalizationSchema::definitions()[$key]['max'] }}" class="w-full rounded-xl border-slate-300" data-package-required>{{ old('child.'.$key) }}</textarea>
                        @else
                            <input name="child[{{ $key }}]" value="{{ old('child.'.$key) }}" @required($field['required']) maxlength="{{ \App\Support\ProductPersonalizationSchema::definitions()[$key]['max'] }}" class="w-full rounded-xl border-slate-300" data-package-required>
                        @endif
                    </label>
                @endif
            @endforeach
        </div>
    </section>
@endif
