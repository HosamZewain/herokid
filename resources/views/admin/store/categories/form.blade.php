<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">{{ $category->exists ? 'تعديل تصنيف' : 'إضافة تصنيف' }}</h2></x-slot>
    <div class="py-8" dir="rtl">
        <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
            <form action="{{ $category->exists ? route('admin.product-categories.update', $category) : route('admin.product-categories.store') }}" method="POST" enctype="multipart/form-data" class="space-y-5 rounded-2xl bg-white p-6 shadow-sm">
                @csrf
                @if($category->exists) @method('PUT') @endif
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div><label class="mb-1 block font-bold">الاسم العربي</label><input name="name_ar" value="{{ old('name_ar', $category->name_ar) }}" required class="w-full rounded-xl border-gray-300 text-right"><x-input-error :messages="$errors->get('name_ar')" /></div>
                    <div><label class="mb-1 block font-bold">الاسم الإنجليزي</label><input name="name_en" value="{{ old('name_en', $category->name_en) }}" class="w-full rounded-xl border-gray-300 text-left" dir="ltr"></div>
                    <div><label class="mb-1 block font-bold">Slug</label><input name="slug" value="{{ old('slug', $category->slug) }}" class="w-full rounded-xl border-gray-300 text-left" dir="ltr"></div>
                    <div><label class="mb-1 block font-bold">الترتيب</label><input type="number" name="sort_order" value="{{ old('sort_order', $category->sort_order ?? 0) }}" class="w-full rounded-xl border-gray-300"></div>
                </div>
                <div><label class="mb-1 block font-bold">وصف عربي قصير</label><textarea name="short_description_ar" rows="3" class="w-full rounded-xl border-gray-300 text-right">{{ old('short_description_ar', $category->short_description_ar) }}</textarea></div>
                <div><label class="mb-1 block font-bold">وصف إنجليزي قصير</label><textarea name="short_description_en" rows="3" class="w-full rounded-xl border-gray-300 text-left" dir="ltr">{{ old('short_description_en', $category->short_description_en) }}</textarea></div>
                <div><label class="mb-1 block font-bold">صورة الغلاف</label><input type="file" name="cover_image" accept="image/*" class="w-full rounded-xl border border-gray-200 p-3"></div>
                <div class="flex gap-6">
                    <label class="inline-flex items-center gap-2 font-bold"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active ?? true))> نشط</label>
                    <label class="inline-flex items-center gap-2 font-bold"><input type="checkbox" name="show_in_store" value="1" @checked(old('show_in_store', $category->show_in_store ?? true))> يظهر في المتجر</label>
                </div>
                <div class="flex gap-3">
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">حفظ</button>
                    <a href="{{ route('admin.product-categories.index') }}" class="rounded-xl border px-5 py-3 font-bold">رجوع</a>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
