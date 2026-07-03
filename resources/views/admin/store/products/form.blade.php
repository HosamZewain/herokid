<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">{{ $product->exists ? 'تعديل منتج' : 'إضافة منتج' }}</h2></x-slot>
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
            @if(session('success'))<div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 font-bold text-green-700">{{ session('success') }}</div>@endif
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
                    <div><label class="mb-1 block font-bold">وضع التخصيص</label><select name="personalization_mode" class="w-full rounded-xl border-gray-300 text-right"><option value="none" @selected(old('personalization_mode', $product->personalization_mode) === 'none')>None</option><option value="inherit_from_linked_story" @selected(old('personalization_mode', $product->personalization_mode) === 'inherit_from_linked_story')>Inherit from linked story</option><option value="collect_child_details" @selected(old('personalization_mode', $product->personalization_mode) === 'collect_child_details')>Collect child details</option></select></div>
                    <div><label class="mb-1 block font-bold">المخزون</label><select name="inventory_mode" class="w-full rounded-xl border-gray-300 text-right"><option value="no_tracking" @selected(old('inventory_mode', $product->inventory_mode) === 'no_tracking')>No tracking</option><option value="track_stock" @selected(old('inventory_mode', $product->inventory_mode) === 'track_stock')>Track stock</option><option value="made_to_order" @selected(old('inventory_mode', $product->inventory_mode) === 'made_to_order')>Made to order</option></select></div>
                    <div><label class="mb-1 block font-bold">كمية المخزون</label><input type="number" name="stock_quantity" value="{{ old('stock_quantity', $product->stock_quantity) }}" class="w-full rounded-xl border-gray-300"></div>
                    <div><label class="mb-1 block font-bold">مدة الإنتاج بالأيام</label><input type="number" name="production_lead_time_days" value="{{ old('production_lead_time_days', $product->production_lead_time_days ?? 0) }}" class="w-full rounded-xl border-gray-300"></div>
                </div>

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
                    <h3 class="mb-4 text-lg font-black">متغيرات المنتج</h3>
                    <div class="space-y-3">
                        @foreach($product->variants as $variant)
                            <form action="{{ route('admin.product-variants.update', $variant) }}" method="POST" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 rounded-xl border p-3 md:grid-cols-6">
                                @csrf
                                @method('PUT')
                                <input name="name_ar" value="{{ $variant->name_ar }}" class="rounded-xl border-gray-300 text-right" placeholder="الاسم">
                                <input name="sku" value="{{ $variant->sku }}" class="rounded-xl border-gray-300 text-left" dir="ltr" placeholder="SKU">
                                <input type="number" step="0.01" name="price_adjustment" value="{{ $variant->price_adjustment_cents / 100 }}" class="rounded-xl border-gray-300" placeholder="فرق السعر">
                                <input type="number" step="0.01" name="price_override" value="{{ $variant->price_override_cents !== null ? $variant->price_override_cents / 100 : '' }}" class="rounded-xl border-gray-300" placeholder="سعر ثابت">
                                <label class="inline-flex items-center gap-2"><input type="checkbox" name="is_active" value="1" @checked($variant->is_active)> نشط</label>
                                <div class="flex gap-2">
                                    <button class="rounded-xl bg-indigo-600 px-3 py-2 text-sm font-bold text-white">حفظ</button>
                                </div>
                            </form>
                            <form action="{{ route('admin.product-variants.destroy', $variant) }}" method="POST" onsubmit="return confirm('حذف المتغير؟')" class="-mt-2">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm font-bold text-red-600">حذف المتغير</button>
                            </form>
                        @endforeach
                    </div>
                    <form action="{{ route('admin.products.variants.store', $product) }}" method="POST" enctype="multipart/form-data" class="mt-6 grid grid-cols-1 gap-3 rounded-xl border border-dashed p-4 md:grid-cols-5">
                        @csrf
                        <input name="name_ar" required class="rounded-xl border-gray-300 text-right" placeholder="اسم المتغير">
                        <input name="sku" class="rounded-xl border-gray-300 text-left" dir="ltr" placeholder="SKU">
                        <input type="number" step="0.01" name="price_adjustment" class="rounded-xl border-gray-300" placeholder="فرق السعر">
                        <input type="number" name="stock_quantity" class="rounded-xl border-gray-300" placeholder="المخزون">
                        <button class="rounded-xl bg-emerald-600 px-3 py-2 font-bold text-white">إضافة متغير</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-admin-layout>
