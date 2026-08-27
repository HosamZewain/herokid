@php
    $personalization = is_array($productForm['personalization'] ?? null) ? $productForm['personalization'] : [];
    $schema = \App\Support\ProductPersonalizationSchema::forProduct($product);
    $fields = \App\Support\ProductPersonalizationSchema::enabledFields($schema);
    $isSelected = (int) ($productForm['quantity'] ?? 0) > 0;
    $existingPhotoCount = (int) ($productForm['existing_photo_count'] ?? 0);
    $existingOrderId = (int) ($productForm['existing_order_id'] ?? 0);
@endphp

<div class="mt-4 rounded-2xl border border-indigo-200 bg-white p-4" data-product-personalization @if(! $isSelected) hidden @endif>
    <div class="mb-3 text-right">
        <p class="text-xs font-black text-indigo-700">بيانات التخصيص لهذا المنتج</p>
        <p class="mt-1 text-[11px] font-bold text-gray-500">تُحفظ الحقول والصور مع المنتج داخل الطلب.</p>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach($fields as $fieldKey => $field)
            @if($field['type'] === 'photos')
                <div class="sm:col-span-2">
                    <label for="product-{{ $product->id }}-photos" class="mb-1 block text-[11px] font-black text-gray-700">
                        {{ $field['label'] }}
                        @unless($field['required'])<span class="font-bold text-gray-400">(اختياري)</span>@endunless
                    </label>
                    @if($existingPhotoCount > 0)
                        <div class="mb-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                            <p class="text-xs font-black text-emerald-800">محفوظ بالفعل: {{ arabic_number($existingPhotoCount) }} صورة</p>
                            @can('orders.photos.view')
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @for($photoIndex = 0; $photoIndex < $existingPhotoCount; $photoIndex++)
                                        <a href="{{ route('admin.orders.photo', ['order' => $existingOrderId, 'index' => $photoIndex]) }}" target="_blank" class="overflow-hidden rounded-lg border border-emerald-200 bg-white">
                                            <img src="{{ route('admin.orders.photo', ['order' => $existingOrderId, 'index' => $photoIndex]) }}" alt="صورة الطفل {{ $photoIndex + 1 }}" class="h-16 w-16 object-cover">
                                        </a>
                                    @endfor
                                </div>
                            @endcan
                        </div>
                    @endif
                    <input id="product-{{ $product->id }}-photos"
                        name="products[{{ $product->id }}][personalization][photos][]"
                        type="file"
                        accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"
                        multiple
                        class="block w-full rounded-xl border border-dashed border-indigo-200 bg-indigo-50/30 p-3 text-xs file:ml-3 file:rounded-lg file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:font-black file:text-white"
                        data-product-personalization-input
                        data-product-photo-input
                        data-required="{{ $field['required'] && $existingPhotoCount < $field['min_files'] ? '1' : '0' }}"
                        data-max-files="{{ max(0, $field['max_files'] - $existingPhotoCount) }}"
                        @required($isSelected && $field['required'] && $existingPhotoCount < $field['min_files'])
                        @disabled(! $isSelected)>
                    <p class="mt-1.5 text-[10px] font-bold text-gray-500" data-product-photo-names>
                        @if($existingPhotoCount >= $field['min_files'])
                            الصور المطلوبة محفوظة. يمكنك إضافة {{ arabic_number(max(0, $field['max_files'] - $existingPhotoCount)) }} صورة أخرى.
                        @else
                            ارفع {{ arabic_number($field['min_files'] - $existingPhotoCount) }} صورة أخرى على الأقل.
                        @endif
                    </p>
                    @error("products.{$product->id}.personalization.photos")<p class="mt-1 text-xs font-bold text-red-600">{{ $message }}</p>@enderror
                    @error("products.{$product->id}.personalization.photos.*")<p class="mt-1 text-xs font-bold text-red-600">{{ $message }}</p>@enderror
                </div>
            @else
                <div class="{{ in_array($field['type'], ['textarea', 'gender'], true) ? 'sm:col-span-2' : '' }}">
                    <label for="product-{{ $product->id }}-{{ $fieldKey }}" class="mb-1 block text-[11px] font-black text-gray-700">
                        {{ $field['label'] }}
                        @unless($field['required'])<span class="font-bold text-gray-400">(اختياري)</span>@endunless
                    </label>

                    @if($field['type'] === 'age')
                        <select id="product-{{ $product->id }}-{{ $fieldKey }}"
                            name="products[{{ $product->id }}][personalization][{{ $fieldKey }}]"
                            class="w-full rounded-xl border-gray-200 bg-white text-right text-xs"
                            data-product-personalization-input data-required="{{ $field['required'] ? '1' : '0' }}"
                            @required($isSelected && $field['required']) @disabled(! $isSelected)>
                            <option value="">اختر العمر</option>
                            @for($age = 3; $age <= 12; $age++)
                                <option value="{{ $age }}" @selected((string) ($personalization[$fieldKey] ?? '') === (string) $age)>{{ arabic_number($age) }} سنوات</option>
                            @endfor
                        </select>
                    @elseif($field['type'] === 'gender')
                        <select id="product-{{ $product->id }}-{{ $fieldKey }}"
                            name="products[{{ $product->id }}][personalization][{{ $fieldKey }}]"
                            class="w-full rounded-xl border-gray-200 bg-white text-right text-xs"
                            data-product-personalization-input data-required="{{ $field['required'] ? '1' : '0' }}"
                            @required($isSelected && $field['required']) @disabled(! $isSelected)>
                            <option value="">اختر الجنس</option>
                            <option value="boy" @selected(($personalization[$fieldKey] ?? '') === 'boy')>ولد</option>
                            <option value="girl" @selected(($personalization[$fieldKey] ?? '') === 'girl')>بنت</option>
                        </select>
                    @elseif($field['type'] === 'textarea')
                        <textarea id="product-{{ $product->id }}-{{ $fieldKey }}"
                            name="products[{{ $product->id }}][personalization][{{ $fieldKey }}]"
                            rows="2"
                            class="w-full rounded-xl border-gray-200 text-right text-xs"
                            data-product-personalization-input data-required="{{ $field['required'] ? '1' : '0' }}"
                            @required($isSelected && $field['required']) @disabled(! $isSelected)>{{ $personalization[$fieldKey] ?? '' }}</textarea>
                    @else
                        <input id="product-{{ $product->id }}-{{ $fieldKey }}"
                            name="products[{{ $product->id }}][personalization][{{ $fieldKey }}]"
                            value="{{ $personalization[$fieldKey] ?? '' }}"
                            class="w-full rounded-xl border-gray-200 text-right text-xs"
                            data-product-personalization-input data-required="{{ $field['required'] ? '1' : '0' }}"
                            @required($isSelected && $field['required']) @disabled(! $isSelected)>
                    @endif

                    @error("products.{$product->id}.personalization.{$fieldKey}")<p class="mt-1 text-xs font-bold text-red-600">{{ $message }}</p>@enderror
                </div>
            @endif
        @endforeach
    </div>
</div>
