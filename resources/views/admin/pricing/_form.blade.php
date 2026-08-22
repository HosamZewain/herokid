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

{{-- Package components --}}
<section class="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-4" data-package-builder data-story-price-cents="{{ $storyPriceCents }}">
    <div class="mb-4">
        <h3 class="font-black text-gray-900">محتويات الباقة</h3>
        <p class="mt-1 text-xs text-gray-500">حدد عدد القصص والمنتجات من المتجر. السعر الذي تدخله في حقل السعر هو السعر النهائي للعميل. المنتج الذي يستخدم بيانات قصة الطفل سيرتبط بأول قصة في الباقة.</p>
    </div>

    <div class="mb-4 max-w-xs">
        <label class="mb-1 block text-sm font-bold text-gray-700">عدد القصص المخصصة</label>
        <input type="number" name="story_count" value="{{ old('story_count', $p?->story_count ?? 0) }}" min="0" max="10" required
            class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" data-package-story-count>
    </div>

    @php
        $storyScope = old('story_scope', $p && !$p->applies_to_all_stories ? 'specific' : 'all');
        $selectedStoryIds = collect(old('story_ids', $p?->eligibleStories?->pluck('id')->all() ?? []))->map(fn ($id) => (int) $id)->all();
    @endphp
    <div class="mb-5 rounded-2xl border border-indigo-100 bg-white p-4" data-package-story-scope>
        <div>
            <h4 class="text-sm font-black text-gray-900">القصص المتاحة داخل الباقة</h4>
            <p class="mt-1 text-xs text-gray-500">اختر جميع القصص، أو حدد قصصًا بعينها مثل قصص كرة القدم فقط.</p>
        </div>
        <div class="mt-3 grid gap-2 sm:grid-cols-2">
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3 transition has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                <input type="radio" name="story_scope" value="all" @checked($storyScope === 'all') class="mt-1 border-gray-300 text-indigo-600" data-story-scope-option>
                <span><strong class="block text-sm text-gray-900">كل القصص</strong><span class="text-xs text-gray-500">يختار العميل من جميع القصص النشطة الحالية والمستقبلية.</span></span>
            </label>
            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3 transition has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50">
                <input type="radio" name="story_scope" value="specific" @checked($storyScope === 'specific') class="mt-1 border-gray-300 text-indigo-600" data-story-scope-option>
                <span><strong class="block text-sm text-gray-900">قصص محددة فقط</strong><span class="text-xs text-gray-500">يختار العميل فقط من القصص التي تحددها هنا.</span></span>
            </label>
        </div>

        <div class="mt-4 {{ $storyScope === 'specific' ? '' : 'hidden' }}" data-specific-stories>
            <label class="block">
                <span class="sr-only">ابحث باسم القصة</span>
                <input type="search" placeholder="ابحث باسم القصة..." class="w-full rounded-xl border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" data-admin-story-search>
            </label>
            <div class="mt-3 grid max-h-80 gap-2 overflow-y-auto rounded-xl bg-gray-50 p-2 sm:grid-cols-2" data-admin-story-list>
                @foreach($stories as $story)
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-gray-200 bg-white p-2 transition has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50" data-admin-story-option data-title="{{ $story->title }}">
                        <input type="checkbox" name="story_ids[]" value="{{ $story->id }}" @checked(in_array($story->id, $selectedStoryIds, true)) class="rounded border-gray-300 text-indigo-600">
                        <span class="h-16 w-12 shrink-0 overflow-hidden rounded-lg bg-gray-100"><x-story-cover-image :src="$story->cover_url" alt="" class="h-full w-full object-cover" /></span>
                        <span class="min-w-0 text-sm font-bold text-gray-900">{{ $story->title }}</span>
                    </label>
                @endforeach
            </div>
            <p class="mt-2 hidden rounded-xl bg-amber-50 p-3 text-xs font-bold text-amber-800" data-admin-story-empty>لا توجد قصة بهذا الاسم.</p>
            @error('story_ids')<p class="mt-2 text-xs font-bold text-red-600">{{ $message }}</p>@enderror
            @error('story_ids.*')<p class="mt-2 text-xs font-bold text-red-600">{{ $message }}</p>@enderror
        </div>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach($products as $product)
            @php
                $savedItem = $p?->items?->firstWhere('product_id', $product->id);
                $quantity = old("products.{$product->id}.quantity", $savedItem?->quantity ?? 0);
                $variantId = old("products.{$product->id}.variant_id", $savedItem?->product_variant_id);
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-3" data-package-product data-base-price-cents="{{ $product->effectivePriceCents() }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 text-right">
                        <p class="truncate text-sm font-black text-gray-900">{{ $product->name_ar }}</p>
                        <p class="mt-1 text-xs font-bold text-indigo-600">{{ format_money($product->effectivePrice()) }}</p>
                    </div>
                    <div class="w-24">
                        <label class="sr-only" for="product-qty-{{ $product->id }}">الكمية</label>
                        <input id="product-qty-{{ $product->id }}" type="number" name="products[{{ $product->id }}][quantity]" value="{{ $quantity }}" min="0" max="50"
                            class="w-full rounded-lg border-gray-300 text-sm" data-package-product-quantity aria-label="كمية {{ $product->name_ar }}">
                    </div>
                </div>
                @if($product->activeVariants->isNotEmpty())
                    <select name="products[{{ $product->id }}][variant_id]" class="mt-2 w-full rounded-lg border-gray-300 text-xs" data-package-product-variant>
                        <option value="">السعر الأساسي</option>
                        @foreach($product->activeVariants as $variant)
                            <option value="{{ $variant->id }}" data-price-cents="{{ $product->effectivePriceCents($variant) }}" @selected((string) $variantId === (string) $variant->id)>
                                {{ $variant->name_ar }} — {{ format_money($product->effectivePriceCents($variant) / 100) }}
                            </option>
                        @endforeach
                    </select>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-3"><p class="text-xs text-gray-500">إجمالي الأسعار الحالية</p><p class="mt-1 font-black text-gray-900" data-package-regular-total>—</p></div>
        <div class="rounded-xl bg-white p-3"><p class="text-xs text-gray-500">سعر الباقة</p><p class="mt-1 font-black text-indigo-700" data-package-entered-price>—</p></div>
        <div class="rounded-xl bg-white p-3"><p class="text-xs text-gray-500">توفير العميل</p><p class="mt-1 font-black text-emerald-700" data-package-saving>—</p></div>
    </div>
</section>

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

{{-- Marketing image --}}
<div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
    <label class="block text-sm font-bold text-gray-700 mb-2">صورة الباقة</label>
    @if($p?->image_url)
        <div class="mb-3 flex items-center gap-4">
            <img src="{{ $p->image_url }}" alt="صورة {{ $p->name }}" class="h-24 w-24 rounded-xl object-cover shadow-sm">
            <label class="flex items-center gap-2 text-sm font-bold text-red-600">
                <input type="checkbox" name="remove_image" value="1" class="rounded border-gray-300 text-red-600">
                حذف الصورة الحالية
            </label>
        </div>
    @endif
    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm">
    <p class="mt-1 text-xs text-gray-500">JPG أو PNG أو WebP حتى ٥ ميجابايت. يفضل صورة مربعة.</p>
</div>

{{-- Price + Currency --}}
<div class="grid gap-4 sm:grid-cols-3">
    <div>
        <label class="block text-sm font-bold text-gray-700 mb-1">السعر الأصلي للمكونات</label>
        <input type="number" name="regular_price" value="{{ old('regular_price', $p?->regular_price) }}" min="0" step="0.01"
               class="w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 text-sm">
        <p class="mt-1 text-xs text-gray-400">يظهر مشطوبًا عند وجود خصم.</p>
    </div>
    <div>
        <label class="block text-sm font-bold text-gray-700 mb-1">سعر الباقة بعد الخصم <span class="text-red-500">*</span></label>
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
<div class="flex flex-wrap gap-6 pt-1">
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
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="show_in_store" value="0">
        <input type="checkbox" name="show_in_store" value="1" {{ old('show_in_store', $p?->show_in_store ?? true) ? 'checked' : '' }} class="h-4 w-4 text-indigo-600 rounded border-gray-300">
        <span class="text-sm font-bold text-gray-700">تظهر في المتجر</span>
    </label>
    <label class="flex items-center gap-2 cursor-pointer">
        <input type="hidden" name="show_on_homepage" value="0">
        <input type="checkbox" name="show_on_homepage" value="1" {{ old('show_on_homepage', $p?->show_on_homepage ?? true) ? 'checked' : '' }} class="h-4 w-4 text-indigo-600 rounded border-gray-300">
        <span class="text-sm font-bold text-gray-700">تظهر في الصفحة الرئيسية</span>
    </label>
</div>

@once
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const builder = document.querySelector('[data-package-builder]');
    if (!builder) return;
    const priceInput = document.querySelector('input[name="price"]');
    const storyScopeInputs = [...builder.querySelectorAll('[data-story-scope-option]')];
    const specificStories = builder.querySelector('[data-specific-stories]');
    const storySearch = builder.querySelector('[data-admin-story-search]');
    const storyOptions = [...builder.querySelectorAll('[data-admin-story-option]')];
    const storyEmpty = builder.querySelector('[data-admin-story-empty]');
    const updateStoryScope = () => {
        const isSpecific = storyScopeInputs.find(input => input.checked)?.value === 'specific';
        specificStories?.classList.toggle('hidden', !isSpecific);
    };
    const money = cents => `${new Intl.NumberFormat('ar-EG').format(cents / 100)} ج.م`;
    const update = () => {
        const storyCount = Number(builder.querySelector('[data-package-story-count]')?.value || 0);
        let total = storyCount * Number(builder.dataset.storyPriceCents || 0);
        builder.querySelectorAll('[data-package-product]').forEach(card => {
            const quantity = Number(card.querySelector('[data-package-product-quantity]')?.value || 0);
            const variant = card.querySelector('[data-package-product-variant] option:checked');
            const unit = Number(variant?.dataset.priceCents || card.dataset.basePriceCents || 0);
            total += quantity * unit;
        });
        const entered = Math.round(Number(priceInput?.value || 0) * 100);
        builder.querySelector('[data-package-regular-total]').textContent = money(total);
        builder.querySelector('[data-package-entered-price]').textContent = money(entered);
        builder.querySelector('[data-package-saving]').textContent = money(Math.max(0, total - entered));
    };
    builder.addEventListener('input', update);
    builder.addEventListener('change', update);
    storyScopeInputs.forEach(input => input.addEventListener('change', updateStoryScope));
    storySearch?.addEventListener('input', () => {
        const term = storySearch.value.trim().toLocaleLowerCase('ar');
        let visible = 0;
        storyOptions.forEach(option => {
            const matches = !term || (option.dataset.title || '').toLocaleLowerCase('ar').includes(term);
            option.classList.toggle('hidden', !matches);
            if (matches) visible += 1;
        });
        storyEmpty?.classList.toggle('hidden', visible !== 0);
    });
    priceInput?.addEventListener('input', update);
    updateStoryScope();
    update();
});
</script>
@endpush
@endonce
