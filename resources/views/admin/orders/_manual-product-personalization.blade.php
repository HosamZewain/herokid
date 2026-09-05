@php
    $schema = \App\Support\ProductPersonalizationSchema::forProduct($product);
    $fields = \App\Support\ProductPersonalizationSchema::enabledFields($schema);
    $quantity = (int) ($productForm['quantity'] ?? 0);
    $units = is_array($productForm['units'] ?? null) ? array_values($productForm['units']) : [];
    if ($units === [] && is_array($productForm['personalization'] ?? null)) {
        $units[] = ['personalization' => $productForm['personalization'], 'existing_photo_count' => $productForm['existing_photo_count'] ?? 0, 'existing_order_id' => $productForm['existing_order_id'] ?? null];
    }
@endphp

<div class="mt-4 space-y-3" data-product-personalization @if($quantity < 1) hidden @endif>
    <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-right">
        <p class="text-xs font-black text-indigo-800">بيانات مستقلة لكل طفل</p>
        <p class="mt-1 text-[11px] font-bold text-indigo-600">سيظهر كارت إنتاج مستقل لكل نسخة، أو استخدم بيانات الطفل الأول.</p>
    </div>
    @for($unitIndex = 0; $unitIndex < 10; $unitIndex++)
        @php
            $unit = $units[$unitIndex] ?? [];
            $personalization = is_array($unit['personalization'] ?? null) ? $unit['personalization'] : [];
            $existingPhotoCount = (int) ($unit['existing_photo_count'] ?? 0);
            $existingOrderId = (int) ($unit['existing_order_id'] ?? 0);
        @endphp
        <section class="rounded-2xl border border-slate-200 bg-white p-4" data-product-personalization-unit="{{ $unitIndex }}" @if($unitIndex >= $quantity) hidden @endif>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded-lg bg-red-50 px-2.5 py-1.5 text-[11px] font-black text-red-600 hover:bg-red-100" data-remove-product-child>حذف الطفل</button>
                    <p class="text-sm font-black text-slate-900">الطفل {{ arabic_number($unitIndex + 1) }}</p>
                </div>
                @if($unitIndex > 0)
                    <label class="flex items-center gap-2 text-xs font-black text-indigo-700">
                        <input type="checkbox" name="products[{{ $product->id }}][units][{{ $unitIndex }}][reuse_first]" value="1" class="rounded border-indigo-300" data-admin-reuse-first @checked(! empty($unit['reuse_first']))>
                        استخدم بيانات وصور الطفل الأول
                    </label>
                @endif
            </div>
            @if($existingOrderId)<input type="hidden" name="products[{{ $product->id }}][units][{{ $unitIndex }}][existing_order_id]" value="{{ $existingOrderId }}">@endif
            <div class="grid gap-3 sm:grid-cols-2" data-admin-unit-fields>
                @foreach($fields as $fieldKey => $field)
                    @if($field['type'] === 'photos')
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-[11px] font-black text-gray-700">{{ $field['label'] }}</label>
                            @if($existingPhotoCount > 0)
                                <div class="mb-2 rounded-xl border border-emerald-200 bg-emerald-50 p-2 text-xs font-black text-emerald-800">
                                    محفوظ: {{ arabic_number($existingPhotoCount) }} صورة
                                    @can('orders.photos.view')<div class="mt-2 flex flex-wrap gap-2" data-existing-photos>@for($photoIndex=0;$photoIndex<$existingPhotoCount;$photoIndex++)<div class="relative" data-existing-photo data-photo-index="{{ $photoIndex }}"><a href="{{ route('admin.orders.photo', ['order'=>$existingOrderId,'index'=>$photoIndex]) }}" target="_blank"><img src="{{ route('admin.orders.photo', ['order'=>$existingOrderId,'index'=>$photoIndex]) }}" alt="" class="h-14 w-14 rounded-lg object-cover"></a><button type="button" data-delete-order-photo data-delete-url="{{ route('admin.orders.photos.destroy', ['order'=>$existingOrderId,'index'=>$photoIndex]) }}" class="absolute -left-1 -top-1 rounded-full bg-red-600 px-1.5 py-0.5 text-[9px] font-black text-white">حذف</button></div>@endfor</div>@endcan
                                </div>
                            @endif
                            <input type="file" name="products[{{ $product->id }}][units][{{ $unitIndex }}][personalization][photos][]" multiple accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif" class="block w-full rounded-xl border border-dashed border-indigo-200 p-3 text-xs" data-product-personalization-input data-admin-unit-field data-product-photo-input data-required="{{ $field['required'] && $existingPhotoCount < $field['min_files'] ? '1' : '0' }}" data-max-files="{{ max(0,$field['max_files']-$existingPhotoCount) }}" @required($quantity>$unitIndex && $field['required'] && $existingPhotoCount<$field['min_files']) @disabled($unitIndex >= $quantity)>
                            <p class="mt-1 text-[10px] font-bold text-gray-500" data-product-photo-names>اختر الصور المطلوبة لهذا الطفل.</p>
                        </div>
                    @else
                        <label class="block text-[11px] font-black text-gray-700 {{ in_array($field['type'],['textarea','gender'],true)?'sm:col-span-2':'' }}">
                            {{ $field['label'] }} @unless($field['required'])<span class="text-gray-400">(اختياري)</span>@endunless
                            @if($field['type']==='age')
                                <select name="products[{{ $product->id }}][units][{{ $unitIndex }}][personalization][{{ $fieldKey }}]" class="mt-1 w-full rounded-xl border-gray-200 text-xs" data-product-personalization-input data-admin-unit-field data-required="{{ $field['required']?'1':'0' }}" @required($quantity>$unitIndex && $field['required']) @disabled($unitIndex >= $quantity)><option value="">اختر العمر</option>@for($age=3;$age<=12;$age++)<option value="{{ $age }}" @selected((string)($personalization[$fieldKey]??'')===(string)$age)>{{ arabic_number($age) }} سنوات</option>@endfor</select>
                            @elseif($field['type']==='gender')
                                <select name="products[{{ $product->id }}][units][{{ $unitIndex }}][personalization][{{ $fieldKey }}]" class="mt-1 w-full rounded-xl border-gray-200 text-xs" data-product-personalization-input data-admin-unit-field data-required="{{ $field['required']?'1':'0' }}" @required($quantity>$unitIndex && $field['required']) @disabled($unitIndex >= $quantity)><option value="">اختر الجنس</option><option value="boy" @selected(($personalization[$fieldKey]??'')==='boy')>ولد</option><option value="girl" @selected(($personalization[$fieldKey]??'')==='girl')>بنت</option></select>
                            @elseif($field['type']==='textarea')
                                <textarea name="products[{{ $product->id }}][units][{{ $unitIndex }}][personalization][{{ $fieldKey }}]" rows="2" class="mt-1 w-full rounded-xl border-gray-200 text-xs" data-product-personalization-input data-admin-unit-field data-required="{{ $field['required']?'1':'0' }}" @required($quantity>$unitIndex && $field['required']) @disabled($unitIndex >= $quantity)>{{ $personalization[$fieldKey]??'' }}</textarea>
                            @else
                                <input name="products[{{ $product->id }}][units][{{ $unitIndex }}][personalization][{{ $fieldKey }}]" value="{{ $personalization[$fieldKey]??'' }}" class="mt-1 w-full rounded-xl border-gray-200 text-xs" data-product-personalization-input data-admin-unit-field data-required="{{ $field['required']?'1':'0' }}" @required($quantity>$unitIndex && $field['required']) @disabled($unitIndex >= $quantity)>
                            @endif
                        </label>
                    @endif
                @endforeach
            </div>
        </section>
    @endfor
</div>
