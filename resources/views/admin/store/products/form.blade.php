@php
    $personalizationDefinitions = \App\Support\ProductPersonalizationSchema::definitions();
    $savedPersonalization = \App\Support\ProductPersonalizationSchema::normalize(
        $product->personalization_fields ?: \App\Support\ProductPersonalizationSchema::legacyDefault()
    );
    $personalizationFieldValues = session()->hasOldInput()
        ? old('personalization_fields', [])
        : $savedPersonalization['fields'];
@endphp

<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">{{ $product->exists ? 'تعديل منتج' : 'إضافة منتج' }}</h2></x-slot>
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
            @if(session('success'))<div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 font-bold text-green-700">{{ session('success') }}</div>@endif
            @if($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-4 text-right text-sm font-bold text-red-700" role="alert" aria-live="assertive">
                    <p class="font-black">لم يتم حفظ المنتج. راجع الحقول التالية:</p>
                    <ul class="mt-2 list-inside list-disc space-y-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <form action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}" method="POST" enctype="multipart/form-data" class="space-y-6 rounded-2xl bg-white p-6 shadow-sm">
                @csrf
                @if($product->exists) @method('PUT') @endif
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block font-bold">التصنيف</label><select name="product_category_id" required class="w-full rounded-xl border-gray-300 text-right"><option value="">اختر...</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('product_category_id', $product->product_category_id) == $category->id)>{{ $category->name_ar }}</option>@endforeach</select><x-input-error :messages="$errors->get('product_category_id')" /></div>
                    <div><label class="mb-1 block font-bold">SKU</label><input name="sku" value="{{ old('sku', $product->sku) }}" class="w-full rounded-xl border-gray-300 text-left" dir="ltr"></div>
                    <div><label class="mb-1 block font-bold">الاسم العربي</label><input name="name_ar" value="{{ old('name_ar', $product->name_ar) }}" required class="w-full rounded-xl border-gray-300 text-right"><x-input-error :messages="$errors->get('name_ar')" /></div>
                    <div><label class="mb-1 block font-bold">الاسم الإنجليزي</label><input name="name_en" value="{{ old('name_en', $product->name_en) }}" class="w-full rounded-xl border-gray-300 text-left" dir="ltr"></div>
                    <div><label class="mb-1 block font-bold">Slug</label><input name="slug" value="{{ old('slug', $product->slug) }}" class="w-full rounded-xl border-gray-300 text-left" dir="ltr"></div>
                    <div><label class="mb-1 block font-bold">الترتيب</label><input type="number" name="sort_order" value="{{ old('sort_order', $product->sort_order ?? 0) }}" class="w-full rounded-xl border-gray-300"></div>
                    <div><label class="mb-1 block font-bold">السعر</label><input type="number" step="0.01" name="price" value="{{ old('price', $product->exists ? $product->price_cents / 100 : 0) }}" required class="w-full rounded-xl border-gray-300"></div>
                    <div><label class="mb-1 block font-bold">سعر التخفيض</label><input type="number" step="0.01" name="sale_price" value="{{ old('sale_price', $product->sale_price_cents !== null ? $product->sale_price_cents / 100 : '') }}" class="w-full rounded-xl border-gray-300"></div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block font-bold">وصف قصير عربي</label><textarea name="short_description_ar" rows="3" class="w-full rounded-xl border-gray-300 text-right">{{ old('short_description_ar', $product->short_description_ar) }}</textarea></div>
                    <div><label class="mb-1 block font-bold">وصف قصير إنجليزي</label><textarea name="short_description_en" rows="3" class="w-full rounded-xl border-gray-300 text-left" dir="ltr">{{ old('short_description_en', $product->short_description_en) }}</textarea></div>
                    <div><label class="mb-1 block font-bold">الوصف العربي</label><textarea name="description_ar" rows="5" class="w-full rounded-xl border-gray-300 text-right">{{ old('description_ar', $product->description_ar) }}</textarea></div>
                    <div><label class="mb-1 block font-bold">الوصف الإنجليزي</label><textarea name="description_en" rows="5" class="w-full rounded-xl border-gray-300 text-left" dir="ltr">{{ old('description_en', $product->description_en) }}</textarea></div>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div><label class="mb-1 block font-bold">نوع التنفيذ</label><select name="fulfillment_type" class="w-full rounded-xl border-gray-300 text-right"><option value="physical" @selected(old('fulfillment_type', $product->fulfillment_type) === 'physical')>Physical</option><option value="digital" @selected(old('fulfillment_type', $product->fulfillment_type) === 'digital')>Digital</option></select></div>
                    <div><label class="mb-1 block font-bold">وضع الشراء</label><select name="purchase_mode" class="w-full rounded-xl border-gray-300 text-right"><option value="standalone" @selected(old('purchase_mode', $product->purchase_mode) === 'standalone')>Standalone</option><option value="add_on_only" @selected(old('purchase_mode', $product->purchase_mode) === 'add_on_only')>Add-on only</option><option value="standalone_or_add_on" @selected(old('purchase_mode', $product->purchase_mode) === 'standalone_or_add_on')>Standalone or add-on</option></select></div>
                    <div><label class="mb-1 block font-bold">وضع التخصيص</label><select name="personalization_mode" data-product-personalization-mode class="w-full rounded-xl border-gray-300 text-right"><option value="none" @selected(old('personalization_mode', $product->personalization_mode) === 'none')>None</option><option value="inherit_from_linked_story" @selected(old('personalization_mode', $product->personalization_mode) === 'inherit_from_linked_story')>Inherit from linked story</option><option value="collect_child_details" @selected(old('personalization_mode', $product->personalization_mode) === 'collect_child_details')>Collect child details</option></select></div>
                    <div><label class="mb-1 block font-bold">المخزون</label><select name="inventory_mode" class="w-full rounded-xl border-gray-300 text-right"><option value="no_tracking" @selected(old('inventory_mode', $product->inventory_mode) === 'no_tracking')>No tracking</option><option value="track_stock" @selected(old('inventory_mode', $product->inventory_mode) === 'track_stock')>Track stock</option><option value="made_to_order" @selected(old('inventory_mode', $product->inventory_mode) === 'made_to_order')>Made to order</option></select></div>
                    <div><label class="mb-1 block font-bold">كمية المخزون</label><input type="number" name="stock_quantity" value="{{ old('stock_quantity', $product->stock_quantity) }}" class="w-full rounded-xl border-gray-300"></div>
                    <div><label class="mb-1 block font-bold">مدة الإنتاج بالأيام</label><input type="number" name="production_lead_time_days" value="{{ old('production_lead_time_days', $product->production_lead_time_days ?? 0) }}" class="w-full rounded-xl border-gray-300"></div>
                </div>

                <section data-product-personalization-fields class="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5">
                    <div class="mb-4 text-right">
                        <h3 class="text-lg font-black text-indigo-950">بيانات التخصيص المطلوبة</h3>
                        <p class="mt-1 text-sm leading-6 text-indigo-700">حدد ما يظهر لولي الأمر، وما إذا كان كل حقل مطلوبًا أو اختياريًا. يتم التحقق من الإعدادات والبيانات على الخادم.</p>
                    </div>

                    <x-input-error :messages="$errors->get('personalization_fields')" class="mb-3" />

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                        @foreach($personalizationDefinitions as $fieldKey => $definition)
                            @php
                                $fieldValue = $personalizationFieldValues[$fieldKey] ?? [];
                                $enabled = filter_var($fieldValue['enabled'] ?? false, FILTER_VALIDATE_BOOL);
                                $required = filter_var($fieldValue['required'] ?? false, FILTER_VALIDATE_BOOL);
                                $label = $fieldValue['label'] ?? $definition['label'];
                            @endphp
                            <div class="rounded-xl border border-indigo-100 bg-white p-4" data-personalization-field-row>
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="font-black text-slate-900">{{ $definition['label'] }}</p>
                                    <div class="flex flex-wrap gap-4 text-sm font-bold">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="hidden" name="personalization_fields[{{ $fieldKey }}][enabled]" value="0">
                                            <input type="checkbox" name="personalization_fields[{{ $fieldKey }}][enabled]" value="1" data-field-enabled @checked($enabled)>
                                            إظهار
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="hidden" name="personalization_fields[{{ $fieldKey }}][required]" value="0">
                                            <input type="checkbox" name="personalization_fields[{{ $fieldKey }}][required]" value="1" data-field-required @checked($required)>
                                            مطلوب
                                        </label>
                                    </div>
                                </div>

                                <label class="mt-3 block text-xs font-black text-slate-600">
                                    الاسم الظاهر لولي الأمر
                                    <input name="personalization_fields[{{ $fieldKey }}][label]" value="{{ $label }}" maxlength="100"
                                        class="mt-1.5 w-full rounded-lg border-gray-300 text-right text-sm">
                                </label>

                                @if($definition['type'] === 'photos')
                                    <div class="mt-3 grid grid-cols-2 gap-3">
                                        <label class="block text-xs font-black text-slate-600">
                                            أقل عدد صور
                                            <input type="number" min="1" max="3" name="personalization_fields[photos][min_files]"
                                                value="{{ $fieldValue['min_files'] ?? config('photo_uploads.min_files', 2) }}"
                                                class="mt-1.5 w-full rounded-lg border-gray-300 text-center text-sm">
                                        </label>
                                        <label class="block text-xs font-black text-slate-600">
                                            أقصى عدد صور
                                            <input type="number" min="1" max="3" name="personalization_fields[photos][max_files]"
                                                value="{{ $fieldValue['max_files'] ?? config('photo_uploads.max_files', 3) }}"
                                                class="mt-1.5 w-full rounded-lg border-gray-300 text-center text-sm">
                                        </label>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-2xl border border-fuchsia-200 bg-fuchsia-50/50 p-5">
                    <div class="mb-4 text-right">
                        <h3 class="text-lg font-black text-fuchsia-950">برومبت إنتاج المنتج</h3>
                        <p class="mt-1 text-sm leading-6 text-fuchsia-700">اتركه فارغًا للمنتجات التي لا تحتاج برومبت. عند حفظ أي تعديل هنا يُطبّق القالب فورًا على كل الطلبات الحالية والجديدة التي تحتوي على هذا المنتج، مع تعبئة بيانات وصور الطفل الخاصة بكل طلب.</p>
                    </div>

                    <textarea
                        name="production_prompt_template"
                        rows="24"
                        maxlength="{{ \App\Support\ProductProductionPrompt::MAX_TEMPLATE_LENGTH }}"
                        dir="ltr"
                        spellcheck="false"
                        class="block w-full rounded-xl border-fuchsia-200 bg-white text-left font-mono text-sm leading-6 focus:border-fuchsia-500 focus:ring-fuchsia-500"
                    >{{ old('production_prompt_template', $product->production_prompt_template) }}</textarea>
                    <x-input-error :messages="$errors->get('production_prompt_template')" class="mt-2" />

                    <details class="mt-4 rounded-xl border border-fuchsia-100 bg-white p-4 text-right">
                        <summary class="cursor-pointer text-sm font-black text-fuchsia-800">المتغيرات المتاحة داخل القالب</summary>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach(\App\Support\ProductProductionPrompt::supportedVariables() as $variable => $details)
                                @php($promptToken = chr(123).chr(123).$variable.chr(125).chr(125))
                                <div class="rounded-lg bg-slate-50 px-3 py-2 text-xs">
                                    <code dir="ltr" class="font-bold text-fuchsia-700">{{ $promptToken }}</code>
                                    <span class="mr-2 text-slate-600">{{ $details['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </details>
                </section>

                <div>
                    <label class="mb-2 block font-bold">الفئات العمرية</label>
                    <div class="flex flex-wrap gap-4">
                        @foreach(['1-3','3-6','6-9','9-12','12+'] as $age)
                            <label class="inline-flex items-center gap-2"><input type="checkbox" name="age_groups[]" value="{{ $age }}" @checked(in_array($age, old('age_groups', $product->age_groups ?? [])))> {{ $age }}</label>
                        @endforeach
                    </div>
                    <p class="mt-1 text-xs text-gray-400">اتركها فارغة ليظهر المنتج لكل الأعمار.</p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block font-bold">الصورة الرئيسية</label><input type="file" name="featured_image" accept="image/*" class="w-full rounded-xl border border-gray-200 p-3"></div>
                    <div><label class="mb-1 block font-bold">صور إضافية</label><input type="file" name="gallery_images[]" multiple accept="image/*" class="w-full rounded-xl border border-gray-200 p-3"></div>
                </div>
                <div><label class="mb-1 block font-bold">المميزات - كل سطر ميزة</label><textarea name="features_text" rows="4" class="w-full rounded-xl border-gray-300 text-right">{{ old('features_text', implode("\n", $product->features ?? [])) }}</textarea></div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block font-bold">SEO Title عربي</label><input name="seo_title_ar" value="{{ old('seo_title_ar', $product->seo_title_ar) }}" class="w-full rounded-xl border-gray-300 text-right"></div>
                    <div><label class="mb-1 block font-bold">SEO Description عربي</label><textarea name="seo_description_ar" rows="2" class="w-full rounded-xl border-gray-300 text-right">{{ old('seo_description_ar', $product->seo_description_ar) }}</textarea></div>
                </div>

                <div class="flex gap-6">
                    <label class="inline-flex items-center gap-2 font-bold"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))> نشط</label>
                    <label class="inline-flex items-center gap-2 font-bold"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured))> مميز</label>
                </div>

                <div class="flex gap-3">
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">حفظ</button>
                    <a href="{{ route('admin.products.index') }}" class="rounded-xl border px-5 py-3 font-bold">رجوع</a>
                </div>
            </form>

            @if($product->exists)
                <section class="rounded-2xl bg-white p-6 shadow-sm">
                    <div class="mb-5">
                        <h3 class="text-lg font-black">متغيرات المنتج</h3>
                        <p class="mt-1 text-sm text-gray-500">كل متغير يُباع كخيار محدد بسعره وصوره وSKU ومخزونه، ويُحفظ كما اختاره العميل داخل الطلب.</p>
                    </div>
                    <div class="space-y-5">
                        @foreach($product->variants as $variant)
                            <form action="{{ route('admin.product-variants.update', $variant) }}" method="POST" enctype="multipart/form-data" class="rounded-2xl border p-4">
                                @csrf
                                @method('PUT')
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                                    <div><label class="mb-1 block text-xs font-bold">اسم المتغير</label><input name="name_ar" value="{{ $variant->name_ar }}" required class="w-full rounded-xl border-gray-300 text-right"></div>
                                    <div><label class="mb-1 block text-xs font-bold">SKU</label><input name="sku" value="{{ $variant->sku }}" class="w-full rounded-xl border-gray-300 text-left" dir="ltr"></div>
                                    <div><label class="mb-1 block text-xs font-bold">السعر المستقل</label><input type="number" step="0.01" name="price_override" value="{{ $variant->price_override_cents !== null ? $variant->price_override_cents / 100 : '' }}" class="w-full rounded-xl border-gray-300" placeholder="يستبدل سعر المنتج"></div>
                                    <div><label class="mb-1 block text-xs font-bold">فرق السعر</label><input type="number" step="0.01" name="price_adjustment" value="{{ $variant->price_adjustment_cents / 100 }}" class="w-full rounded-xl border-gray-300" placeholder="يضاف لسعر المنتج"></div>
                                    <div><label class="mb-1 block text-xs font-bold">المخزون</label><input type="number" name="stock_quantity" value="{{ $variant->stock_quantity }}" class="w-full rounded-xl border-gray-300"></div>
                                    <div><label class="mb-1 block text-xs font-bold">الترتيب</label><input type="number" name="sort_order" value="{{ $variant->sort_order }}" class="w-full rounded-xl border-gray-300"></div>
                                    <div class="md:col-span-2"><label class="mb-1 block text-xs font-bold">الخصائص (كل سطر خاصية)</label><textarea name="attributes_text" rows="2" class="w-full rounded-xl border-gray-300 text-right">{{ implode("\n", $variant->attributes ?? []) }}</textarea></div>
                                    <div><label class="mb-1 block text-xs font-bold">الصورة الرئيسية</label><input type="file" name="image" accept="image/*" class="w-full text-sm"></div>
                                    <div class="md:col-span-2"><label class="mb-1 block text-xs font-bold">صور إضافية (حتى ٨)</label><input type="file" name="gallery_images[]" accept="image/*" multiple class="w-full text-sm"></div>
                                </div>
                                @if($variant->all_image_urls)
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        @if($variant->image_url)
                                            <img src="{{ $variant->image_url }}" alt="{{ $variant->name_ar }}" class="h-20 w-20 rounded-xl border object-cover">
                                        @endif
                                        @foreach(($variant->gallery_images ?? []) as $index => $path)
                                            <label class="relative block">
                                                <img src="{{ $variant->gallery_image_urls[$index] ?? '' }}" alt="" class="h-20 w-20 rounded-xl border object-cover">
                                                <span class="mt-1 flex items-center gap-1 text-xs text-red-600"><input type="checkbox" name="remove_gallery_images[]" value="{{ $path }}"> حذف</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex items-center gap-4">
                                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($variant->is_active)> نشط</label>
                                        <span class="text-sm font-bold text-emerald-700">تم طلبه {{ (int) ($variant->sold_quantity ?? 0) }} مرة</span>
                                    </div>
                                    <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white">حفظ المتغير</button>
                                </div>
                            </form>
                            <form action="{{ route('admin.product-variants.destroy', $variant) }}" method="POST" onsubmit="return confirm('حذف المتغير؟')" class="-mt-2">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm font-bold text-red-600">حذف المتغير</button>
                            </form>
                        @endforeach
                    </div>
                    <form action="{{ route('admin.products.variants.store', $product) }}" method="POST" enctype="multipart/form-data" class="mt-6 grid grid-cols-1 gap-3 rounded-xl border border-dashed p-4 md:grid-cols-4">
                        @csrf
                        <input name="name_ar" required class="rounded-xl border-gray-300 text-right" placeholder="اسم المتغير">
                        <input name="sku" class="rounded-xl border-gray-300 text-left" dir="ltr" placeholder="SKU">
                        <input type="number" step="0.01" name="price_override" class="rounded-xl border-gray-300" placeholder="السعر المستقل">
                        <input type="number" name="stock_quantity" class="rounded-xl border-gray-300" placeholder="المخزون">
                        <input type="file" name="image" accept="image/*" class="text-sm">
                        <input type="file" name="gallery_images[]" accept="image/*" multiple class="text-sm md:col-span-2">
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" checked> نشط</label>
                        <button class="rounded-xl bg-emerald-600 px-3 py-2 font-bold text-white">إضافة متغير</button>
                    </form>
                </section>
            @endif
        </div>
    </div>

    <script>
        (() => {
            const mode = document.querySelector('[data-product-personalization-mode]');
            const fields = document.querySelector('[data-product-personalization-fields]');

            if (!mode || !fields) return;

            const syncSection = () => fields.classList.toggle('hidden', mode.value !== 'collect_child_details');
            const syncRequired = (row) => {
                const enabled = row.querySelector('[data-field-enabled]');
                const required = row.querySelector('[data-field-required]');
                if (!enabled || !required) return;
                required.disabled = !enabled.checked;
                if (!enabled.checked) required.checked = false;
            };

            fields.querySelectorAll('[data-personalization-field-row]').forEach((row) => {
                syncRequired(row);
                row.querySelector('[data-field-enabled]')?.addEventListener('change', () => syncRequired(row));
            });
            mode.addEventListener('change', syncSection);
            syncSection();
        })();
    </script>
</x-admin-layout>
