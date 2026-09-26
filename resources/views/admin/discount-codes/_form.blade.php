@php
    $editing = isset($discountCode) && $discountCode->exists;
    $field = fn (string $name, mixed $fallback = null) => old($name, $editing ? $discountCode->{$name} : $fallback);
@endphp

<div class="grid gap-5 lg:grid-cols-2">
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">كود الخصم</label>
        <input name="code" value="{{ $field('code') }}" required maxlength="40" dir="ltr" autocomplete="off"
            class="w-full rounded-2xl border-slate-200 py-3 font-black uppercase tracking-wider focus:border-indigo-500 focus:ring-indigo-500"
            placeholder="HERO20">
        <x-input-error :messages="$errors->get('code')" class="mt-2" />
    </div>
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">اسم الحملة <span class="font-medium text-slate-400">(اختياري)</span></label>
        <input name="name" value="{{ $field('name') }}" maxlength="120"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500"
            placeholder="عرض العودة للمدارس">
        <x-input-error :messages="$errors->get('name')" class="mt-2" />
    </div>
</div>

<div class="mt-5 grid gap-5 md:grid-cols-3">
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">نوع الخصم</label>
        <select name="discount_type" class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500">
            <option value="percent" @selected($field('discount_type', 'percent') === 'percent')>نسبة مئوية</option>
            <option value="fixed" @selected($field('discount_type') === 'fixed')>مبلغ ثابت</option>
        </select>
    </div>
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">قيمة الخصم</label>
        <input name="discount_value" type="number" min="0.01" step="0.01" required
            value="{{ old('discount_value', $editing ? $discountCode->discount_value / 100 : null) }}"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500" placeholder="10">
        <p class="mt-1 text-xs text-slate-500">اكتب 10 لنسبة 10% أو مبلغ 10 ج.م حسب النوع.</p>
        <x-input-error :messages="$errors->get('discount_value')" class="mt-2" />
    </div>
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">الحد الأدنى للطلب</label>
        <input name="minimum_subtotal" type="number" min="0" step="0.01"
            value="{{ old('minimum_subtotal', $editing ? $discountCode->minimum_subtotal_cents / 100 : 0) }}"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500">
        <p class="mt-1 text-xs text-slate-500">يُحسب من المنتجات قبل التوصيل.</p>
    </div>
</div>

<div class="mt-5 grid gap-5 md:grid-cols-3">
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">أقصى خصم <span class="font-medium text-slate-400">(اختياري)</span></label>
        <input name="maximum_discount" type="number" min="0.01" step="0.01"
            value="{{ old('maximum_discount', $editing && $discountCode->maximum_discount_cents !== null ? $discountCode->maximum_discount_cents / 100 : null) }}"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500" placeholder="بدون حد">
    </div>
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">إجمالي مرات الاستخدام</label>
        <input name="usage_limit" type="number" min="1" step="1" value="{{ $field('usage_limit') }}"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500" placeholder="مفتوح">
        <p class="mt-1 text-xs text-slate-500">اتركه فارغًا للاستخدام المفتوح.</p>
    </div>
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">مرات الاستخدام لكل عميل</label>
        <input name="per_user_limit" type="number" min="1" step="1" value="{{ $field('per_user_limit') }}"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500" placeholder="مفتوح">
        <p class="mt-1 text-xs text-slate-500">يُحدد العميل برقم الهاتف على الموقع.</p>
    </div>
</div>

<div class="mt-5 grid gap-5 md:grid-cols-2">
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">يبدأ في <span class="font-medium text-slate-400">(اختياري)</span></label>
        <input name="starts_at" type="datetime-local"
            value="{{ old('starts_at', $editing ? $discountCode->starts_at?->format('Y-m-d\TH:i') : null) }}"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500">
    </div>
    <div>
        <label class="mb-2 block text-sm font-black text-slate-700">ينتهي في <span class="font-medium text-slate-400">(اختياري)</span></label>
        <input name="ends_at" type="datetime-local"
            value="{{ old('ends_at', $editing ? $discountCode->ends_at?->format('Y-m-d\TH:i') : null) }}"
            class="w-full rounded-2xl border-slate-200 py-3 focus:border-indigo-500 focus:ring-indigo-500">
        <x-input-error :messages="$errors->get('ends_at')" class="mt-2" />
    </div>
</div>

<div class="mt-6 grid gap-3 md:grid-cols-3">
    @foreach([
        'website_enabled' => ['الموقع', 'يظهر ويُقبل في سلة الموقع.', true],
        'mobile_enabled' => ['تطبيق الهاتف', 'يُقبل داخل تطبيق HeroKid.', false],
        'is_active' => ['الكود نشط', 'يمكن إيقافه فورًا دون حذفه.', true],
    ] as $name => [$title, $description, $default])
        <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <input type="hidden" name="{{ $name }}" value="0">
            <input type="checkbox" name="{{ $name }}" value="1" @checked((bool) $field($name, $default))
                class="mt-1 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            <span><strong class="block text-sm text-slate-900">{{ $title }}</strong><span class="mt-1 block text-xs leading-5 text-slate-500">{{ $description }}</span></span>
        </label>
    @endforeach
</div>
<x-input-error :messages="$errors->get('website_enabled')" class="mt-2" />

<div class="mt-7 flex flex-wrap gap-3">
    <button class="rounded-2xl bg-indigo-600 px-7 py-3 font-black text-white shadow-lg shadow-indigo-100 hover:bg-indigo-700">
        {{ $editing ? 'حفظ التعديلات' : 'إنشاء كود الخصم' }}
    </button>
    @if($editing)
        <a href="{{ route('admin.discount-codes.index') }}" class="rounded-2xl border border-slate-200 px-7 py-3 font-black text-slate-700">إلغاء</a>
    @endif
</div>
