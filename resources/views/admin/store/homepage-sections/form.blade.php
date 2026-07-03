<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">{{ $section->exists ? 'تعديل قسم' : 'إضافة قسم' }}</h2></x-slot>
    <div class="py-8" dir="rtl"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
        <form action="{{ $section->exists ? route('admin.homepage-store-sections.update', $section) : route('admin.homepage-store-sections.store') }}" method="POST" class="space-y-4 rounded-2xl bg-white p-6 shadow-sm">
            @csrf @if($section->exists) @method('PUT') @endif
            <div><label class="mb-1 block font-bold">التصنيف</label><select name="product_category_id" class="w-full rounded-xl border-gray-300 text-right"><option value="">بدون</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('product_category_id', $section->product_category_id) == $category->id)>{{ $category->name_ar }}</option>@endforeach</select></div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2"><div><label class="mb-1 block font-bold">العنوان العربي</label><input name="title_ar" value="{{ old('title_ar', $section->title_ar) }}" required class="w-full rounded-xl border-gray-300 text-right"></div><div><label class="mb-1 block font-bold">العنوان الإنجليزي</label><input name="title_en" value="{{ old('title_en', $section->title_en) }}" class="w-full rounded-xl border-gray-300 text-left" dir="ltr"></div></div>
            <div><label class="mb-1 block font-bold">وصف عربي</label><textarea name="subtitle_ar" rows="2" class="w-full rounded-xl border-gray-300 text-right">{{ old('subtitle_ar', $section->subtitle_ar) }}</textarea></div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3"><div><label class="mb-1 block font-bold">عدد المنتجات</label><input type="number" name="max_products" value="{{ old('max_products', $section->max_products ?? 4) }}" class="w-full rounded-xl border-gray-300"></div><div><label class="mb-1 block font-bold">الترتيب</label><input type="number" name="sort_order" value="{{ old('sort_order', $section->sort_order ?? 0) }}" class="w-full rounded-xl border-gray-300"></div><div><label class="mb-1 block font-bold">نص CTA</label><input name="cta_text_ar" value="{{ old('cta_text_ar', $section->cta_text_ar) }}" class="w-full rounded-xl border-gray-300 text-right"></div></div>
            <label class="inline-flex items-center gap-2 font-bold"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $section->is_active ?? true))> نشط</label>
            <div class="flex gap-3"><button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">حفظ</button><a href="{{ route('admin.homepage-store-sections.index') }}" class="rounded-xl border px-5 py-3 font-bold">رجوع</a></div>
        </form>
    </div></div>
</x-admin-layout>
