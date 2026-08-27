@php
    $unitOld = old("personalizations.$unitIndex", []);
    $unitConfig = $hasPhotoField ? array_merge($photoUploadConfig, [
        'batchToken' => \Illuminate\Support\Str::random(48),
        'storageKey' => 'herokid:product:'.$product->slug.':unit:'.$unitIndex.':photo-upload-ids',
        'hiddenInputName' => "personalizations[$unitIndex][photo_upload_ids][]",
        'serverRejectedUploads' => $errors->has("personalizations.$unitIndex.photo_upload_ids") || $errors->has("personalizations.$unitIndex.photo_upload_ids.*"),
        'restoredUploadIds' => $errors->has("personalizations.$unitIndex.photo_upload_ids") ? [] : ($unitOld['photo_upload_ids'] ?? []),
        'readyLabel' => 'بيانات الطفل جاهزة',
        'clearStorageOnSubmit' => true,
    ]) : null;
@endphp

<section class="rounded-3xl border border-indigo-200 bg-indigo-50/60 p-4 sm:p-5"
    data-personalization-unit="{{ $unitIndex }}"
    @if($hasPhotoField) data-identity-intake @endif
    @if($unitIndex >= $initialQuantity) hidden @endif>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3 text-right">
        <div>
            <span class="inline-flex rounded-full bg-pink-500 px-3 py-1 text-xs font-black text-white">الطفل {{ arabic_number($unitIndex + 1) }}</span>
            <h2 class="mt-2 text-lg font-black text-slate-950">بيانات وصور الطفل</h2>
        </div>
        @if($unitIndex > 0)
            <label class="flex cursor-pointer items-center gap-2 rounded-2xl border border-indigo-200 bg-white px-3 py-2 text-sm font-black text-indigo-800">
                <input type="checkbox" name="personalizations[{{ $unitIndex }}][reuse_first]" value="1"
                    class="rounded border-indigo-300 text-indigo-600 focus:ring-indigo-500"
                    data-reuse-first-child @checked(! empty($unitOld['reuse_first']))>
                استخدم نفس بيانات وصور الطفل الأول
            </label>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2" data-personalization-fields>
        @foreach($personalizationFields as $fieldKey => $field)
            @continue($field['type'] === 'photos')
            <label for="product-{{ $unitIndex }}-{{ str_replace('_', '-', $fieldKey) }}" class="block text-sm font-black text-slate-700 {{ in_array($field['type'], ['textarea', 'gender'], true) ? 'sm:col-span-2' : '' }}">
                {{ $field['label'] }}
                @unless($field['required'])<span class="font-bold text-slate-400">(اختياري)</span>@endunless

                @if($field['type'] === 'age')
                    <select id="product-{{ $unitIndex }}-{{ str_replace('_', '-', $fieldKey) }}" name="personalizations[{{ $unitIndex }}][{{ $fieldKey }}]"
                        class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3" data-personalization-field data-required="{{ $field['required'] ? '1' : '0' }}" @required($field['required'])>
                        <option value="">اختر العمر</option>
                        @foreach($ageOptions as $age)<option value="{{ $age }}" @selected((string) ($unitOld[$fieldKey] ?? '') === (string) $age)>{{ arabic_number($age) }} سنوات</option>@endforeach
                    </select>
                @elseif($field['type'] === 'gender')
                    <select id="product-{{ $unitIndex }}-{{ str_replace('_', '-', $fieldKey) }}" name="personalizations[{{ $unitIndex }}][{{ $fieldKey }}]"
                        class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3" data-personalization-field data-required="{{ $field['required'] ? '1' : '0' }}" @required($field['required'])>
                        <option value="">اختر الجنس</option>
                        <option value="boy" @selected(($unitOld[$fieldKey] ?? '') === 'boy')>ولد 👦</option>
                        <option value="girl" @selected(($unitOld[$fieldKey] ?? '') === 'girl')>بنت 👧</option>
                    </select>
                @elseif($field['type'] === 'textarea')
                    <textarea id="product-{{ $unitIndex }}-{{ str_replace('_', '-', $fieldKey) }}" name="personalizations[{{ $unitIndex }}][{{ $fieldKey }}]" rows="2"
                        class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 text-right" data-personalization-field data-required="{{ $field['required'] ? '1' : '0' }}" @required($field['required'])>{{ $unitOld[$fieldKey] ?? '' }}</textarea>
                @else
                    <input id="product-{{ $unitIndex }}-{{ str_replace('_', '-', $fieldKey) }}" name="personalizations[{{ $unitIndex }}][{{ $fieldKey }}]" value="{{ $unitOld[$fieldKey] ?? '' }}"
                        class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 text-right" data-personalization-field data-required="{{ $field['required'] ? '1' : '0' }}" @required($field['required'])>
                @endif
                <x-input-error :messages="$errors->get('personalizations.'.$unitIndex.'.'.$fieldKey)" class="mt-1" />
            </label>
        @endforeach
    </div>

    @if($hasPhotoField)
        <input type="hidden" name="upload_session_token" value="{{ $photoUploadConfig['sessionToken'] }}">
        <script type="application/json" data-identity-upload-config>@json($unitConfig)</script>
        <div class="mt-4 rounded-2xl border-2 border-dashed border-indigo-200 bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-black text-indigo-950">{{ $photoField['label'] }}</h3>
                <span class="rounded-full bg-indigo-50 px-3 py-1.5 text-xs font-black text-indigo-700" data-identity-photo-count></span>
            </div>
            <input type="file" id="product-child-photos-{{ $unitIndex }}" multiple accept="image/*,.jpg,.jpeg,.png,.webp,.heic,.heif" class="sr-only" data-identity-photo-input>
            <div data-identity-photo-ids></div>
            <label for="product-child-photos-{{ $unitIndex }}" data-identity-photo-picker class="mt-4 flex min-h-20 cursor-pointer flex-col items-center justify-center rounded-xl border border-indigo-200 bg-indigo-50/50 px-4 py-3 text-center">
                <span class="font-black text-indigo-700" data-identity-photo-picker-title></span>
                <span class="mt-1 text-xs font-bold text-slate-500" data-identity-photo-picker-help></span>
            </label>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3" data-identity-photo-queue aria-live="polite"></div>
            <div class="mt-3 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2" role="status" aria-live="polite" data-identity-photo-requirement>
                <p class="text-sm font-black" data-identity-photo-requirement-title></p>
                <p class="mt-1 text-xs font-bold" data-identity-photo-requirement-description></p>
            </div>
            <div class="mt-3 hidden rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-black text-red-700" data-identity-photo-error></div>
            <x-input-error :messages="$errors->get('personalizations.'.$unitIndex.'.photo_upload_ids')" class="mt-2" />
        </div>
        <button type="button" class="hidden" data-identity-submit><span data-submit-label></span></button>
    @endif
</section>
