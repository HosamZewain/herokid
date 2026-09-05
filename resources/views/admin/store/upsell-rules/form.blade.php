<x-admin-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold text-gray-800">{{ $rule->exists ? 'تعديل قاعدة ترشيح' : 'إضافة قاعدة ترشيح' }}</h2></x-slot>
    <div class="py-8" dir="rtl"><div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
        <form action="{{ $rule->exists ? route('admin.upsell-rules.update', $rule) : route('admin.upsell-rules.store') }}" method="POST" class="space-y-4 rounded-2xl bg-white p-6 shadow-sm">
            @csrf @if($rule->exists) @method('PUT') @endif
            <div><label class="mb-1 block font-bold">المنتج المقترح</label><select name="target_product_id" required class="w-full rounded-xl border-gray-300 text-right"><option value="">اختر...</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(old('target_product_id', $rule->target_product_id) == $product->id)>{{ $product->name_ar }}</option>@endforeach</select></div>
            <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4">
                <label class="mb-1 block font-bold text-indigo-950">المنتج الأساسي الذي يُظهر الاقتراح</label>
                <select name="source_product_id" class="w-full rounded-xl border-indigo-200 bg-white text-right">
                    <option value="">لا يوجد — استخدم شروط القصص بالأسفل</option>
                    @foreach($products as $product)<option value="{{ $product->id }}" @selected(old('source_product_id', $rule->source_product_id) == $product->id)>{{ $product->name_ar }}</option>@endforeach
                </select>
                @error('source_product_id')<p class="mt-2 text-sm font-bold text-red-600">{{ $message }}</p>@enderror
                <p class="mt-2 text-xs font-bold leading-6 text-indigo-700">عند اختيار منتج أساسي، ستظهر هذه التوصية في صفحة المنتج وفي السلة، ويتم تجاهل شروط القصة والعمر والجنس لهذه القاعدة.</p>
            </div>
            <div><label class="mb-1 block font-bold">قصة محددة</label><select name="source_story_id" class="w-full rounded-xl border-gray-300 text-right"><option value="">كل القصص</option>@foreach($stories as $story)<option value="{{ $story->id }}" @selected(old('source_story_id', $rule->source_story_id) == $story->id)>{{ $story->title }}</option>@endforeach</select></div>
            <div><label class="mb-1 block font-bold">تصنيف قصة</label><select name="source_story_category_id" class="w-full rounded-xl border-gray-300 text-right"><option value="">كل التصنيفات</option>@foreach($storyCategories as $category)<option value="{{ $category->id }}" @selected(old('source_story_category_id', $rule->source_story_category_id) == $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3"><div><label class="mb-1 block font-bold">العمر</label><input name="age_group" value="{{ old('age_group', $rule->age_group) }}" class="w-full rounded-xl border-gray-300"></div><div><label class="mb-1 block font-bold">الجنس</label><select name="gender" class="w-full rounded-xl border-gray-300 text-right"><option value="">الكل</option><option value="boy" @selected(old('gender', $rule->gender) === 'boy')>ولد</option><option value="girl" @selected(old('gender', $rule->gender) === 'girl')>بنت</option></select></div><div><label class="mb-1 block font-bold">الأولوية</label><input type="number" name="priority" value="{{ old('priority', $rule->priority ?? 0) }}" class="w-full rounded-xl border-gray-300"></div></div>
            <input type="hidden" name="trigger_scope" value="{{ old('trigger_scope', $rule->trigger_scope ?? 'story_added') }}">
            <label class="inline-flex items-center gap-2 font-bold"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active ?? true))> نشطة</label>
            <div class="flex gap-3"><button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">حفظ</button><a href="{{ route('admin.upsell-rules.index') }}" class="rounded-xl border px-5 py-3 font-bold">رجوع</a></div>
        </form>
    </div></div>
</x-admin-layout>
