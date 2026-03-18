@php $p = $package ?? null; @endphp

@if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm mb-4">
        <ul class="list-disc list-inside space-y-1">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

{{-- Name --}}
<div>
    <label class="block text-sm font-bold text-gray-700 mb-1">اسم الباقة <span class="text-red-500">*</span></label>
    <input type="text" name="name" value="{{ old('name', $p?->name) }}" required
           placeholder="مثال: الباقة الأساسية"
           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
</div>

{{-- Subtitle --}}
<div>
    <label class="block text-sm font-bold text-gray-700 mb-1">العنوان الفرعي</label>
    <input type="text" name="subtitle" value="{{ old('subtitle', $p?->subtitle) }}"
           placeholder="مثال: كتاب القصة المطبوع"
           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
</div>

{{-- Description --}}
<div>
    <label class="block text-sm font-bold text-gray-700 mb-1">الوصف</label>
    <textarea name="description" rows="2"
              placeholder="وصف مختصر للباقة..."
              class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">{{ old('description', $p?->description) }}</textarea>
</div>

{{-- Price + Currency --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-bold text-gray-700 mb-1">السعر <span class="text-red-500">*</span></label>
        <input type="number" name="price" value="{{ old('price', $p?->price) }}" required min="0" step="0.01"
               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
    </div>
    <div>
        <label class="block text-sm font-bold text-gray-700 mb-1">العملة</label>
        <input type="text" name="currency" value="{{ old('currency', $p?->currency ?? 'ج.م') }}"
               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
    </div>
</div>

{{-- Features --}}
<div>
    <label class="block text-sm font-bold text-gray-700 mb-1">المزايا (سطر لكل ميزة)</label>
    <textarea name="features_raw" rows="6"
              placeholder="24 صفحة مصورة بالكامل&#10;غلاف ناعم (Soft Cover)&#10;اسم الطفل في كل صفحة"
              class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm font-mono">{{ old('features_raw', $p ? implode("\n", $p->features ?? []) : '') }}</textarea>
    <p class="text-xs text-gray-400 mt-1">كل سطر = ميزة واحدة تظهر في القائمة</p>
</div>

{{-- Badge + Button Text --}}
<div class="grid grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-bold text-gray-700 mb-1">شارة (اختياري)</label>
        <input type="text" name="badge" value="{{ old('badge', $p?->badge) }}"
               placeholder="مثال: الأكثر طلباً ⭐"
               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
    </div>
    <div>
        <label class="block text-sm font-bold text-gray-700 mb-1">نص زر الطلب</label>
        <input type="text" name="button_text" value="{{ old('button_text', $p?->button_text ?? 'اختر قصتك الآن') }}"
               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
    </div>
</div>

{{-- Sort order --}}
<div class="w-32">
    <label class="block text-sm font-bold text-gray-700 mb-1">الترتيب</label>
    <input type="number" name="sort_order" value="{{ old('sort_order', $p?->sort_order ?? 0) }}" min="0"
           class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
</div>

{{-- Toggles --}}
<div class="flex gap-6 pt-1">
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="is_featured" value="0">
        <input type="checkbox" name="is_featured" value="1"
               {{ old('is_featured', $p?->is_featured) ? 'checked' : '' }}
               class="h-4 w-4 text-indigo-600 rounded border-gray-300">
        <span class="text-sm font-bold text-gray-700">باقة مميزة (تظهر بتصميم بارز)</span>
    </label>
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1"
               {{ old('active', $p?->active ?? true) ? 'checked' : '' }}
               class="h-4 w-4 text-indigo-600 rounded border-gray-300">
        <span class="text-sm font-bold text-gray-700">نشطة (تظهر على الموقع)</span>
    </label>
</div>
