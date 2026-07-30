@php($filterAction = $isStoriesAlias ? route('stories.index') : route('shop.index'))

<form method="GET" action="{{ $filterAction }}" class="{{ $formClasses }}" data-store-filter-form>
    @unless($isStoriesAlias)
        <input type="hidden" name="type" value="{{ $activeType }}">
    @endunless
    <input type="hidden" name="per_page" value="{{ $items->perPage() }}">

    <label class="sr-only" for="{{ $filterId }}-search">ابحث في المتجر</label>
    <input id="{{ $filterId }}-search" type="search" name="q" value="{{ request('q') }}" placeholder="ابحث عن قصة أو منتج"
           class="rounded-2xl border-slate-200 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500">

    <label class="sr-only" for="{{ $filterId }}-age">العمر</label>
    <select id="{{ $filterId }}-age" name="age" class="rounded-2xl border-slate-200 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">كل الأعمار</option>
        @foreach($ageRanges as $age)
            <option value="{{ $age }}" @selected((string) request('age') === (string) $age)>{{ $age }}</option>
        @endforeach
    </select>

    <label class="sr-only" for="{{ $filterId }}-category">التصنيف</label>
    <select id="{{ $filterId }}-category" name="category" class="rounded-2xl border-slate-200 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">كل التصنيفات</option>
        @if($storyCategories->isNotEmpty())
            <optgroup label="تصنيفات القصص">
                @foreach($storyCategories as $category)
                    <option value="story:{{ $category->slug }}" @selected(request('category') === 'story:'.$category->slug || request('category') === $category->slug)>{{ $category->name }}</option>
                @endforeach
            </optgroup>
        @endif
        @if(! $isStoriesAlias && $productCategories->isNotEmpty())
            <optgroup label="تصنيفات المنتجات">
                @foreach($productCategories as $category)
                    <option value="product:{{ $category->slug }}" @selected(request('category') === 'product:'.$category->slug || request('category') === $category->slug)>{{ $category->name_ar }}</option>
                @endforeach
            </optgroup>
        @endif
    </select>

    <label class="sr-only" for="{{ $filterId }}-gender">الجنس</label>
    <select id="{{ $filterId }}-gender" name="gender" class="rounded-2xl border-slate-200 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="">كل الأطفال</option>
        <option value="boy" @selected(request('gender') === 'boy')>ولد</option>
        <option value="girl" @selected(request('gender') === 'girl')>بنت</option>
    </select>

    @unless($isStoriesAlias)
        <label class="sr-only" for="{{ $filterId }}-personalization">نوع التخصيص</label>
        <select id="{{ $filterId }}-personalization" name="personalization" class="rounded-2xl border-slate-200 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">كل أنواع التخصيص</option>
            <option value="requires_child_photos" @selected(request('personalization') === 'requires_child_photos')>يتطلب بيانات أو صورة الطفل</option>
            <option value="story_context" @selected(request('personalization') === 'story_context')>يستخدم قصة الطفل</option>
            <option value="none" @selected(request('personalization') === 'none')>بدون تخصيص</option>
        </select>
    @endunless

    <label class="sr-only" for="{{ $filterId }}-sort">الترتيب</label>
    <select id="{{ $filterId }}-sort" name="sort" class="rounded-2xl border-slate-200 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500">
        <option value="featured" @selected(request('sort', setting('unified_store_default_sort', 'featured')) === 'featured')>المميزة</option>
        <option value="newest" @selected(request('sort') === 'newest')>الأحدث</option>
        <option value="price_asc" @selected(request('sort') === 'price_asc')>السعر من الأقل</option>
        <option value="price_desc" @selected(request('sort') === 'price_desc')>السعر من الأعلى</option>
    </select>

    <div class="flex gap-2">
        <button class="flex-1 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white transition hover:bg-indigo-700">تطبيق</button>
        @if($hasFilters)
            <a href="{{ $filterAction }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 px-4 text-sm font-black text-slate-500 hover:bg-slate-50" aria-label="مسح الفلاتر">×</a>
        @endif
    </div>
</form>
