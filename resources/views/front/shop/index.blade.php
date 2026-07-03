<x-front-layout>
    <x-slot name="pageTitle">متجر HeroKid للأطفال</x-slot>
    <x-slot name="pageDescription">تسوق كتب أنشطة، قصص جاهزة، وهدايا مخصصة تكمل تجربة قصة طفلك من HeroKid.</x-slot>
    <x-slot name="canonical">{{ $currentCategory ? '/shop/' . $currentCategory->slug : '/shop' }}</x-slot>

    <div class="bg-slate-50 py-10 lg:py-14">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mb-8 text-right">
                <p class="mb-3 inline-flex rounded-full bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-700">المتجر</p>
                <h1 class="text-3xl font-black text-slate-950 sm:text-4xl">{{ $currentCategory?->name_ar ?? 'متجر HeroKid' }}</h1>
                <p class="mt-3 max-w-3xl text-lg leading-8 text-slate-500">منتجات مطبوعة وأنشطة وهدايا يمكن شراؤها مباشرة أو إضافتها مع قصة طفلك المخصصة.</p>
            </div>

            <div class="mb-6 flex flex-wrap justify-end gap-3">
                <a href="{{ route('shop.index') }}" class="rounded-2xl border px-4 py-2 text-sm font-black {{ request()->routeIs('shop.index') && !request('category') ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-700' }}">كل المنتجات</a>
                @foreach($categories as $category)
                    <a href="{{ route('shop.category', $category) }}" class="rounded-2xl border px-4 py-2 text-sm font-black {{ ($currentCategory?->id === $category->id || request('category') === $category->slug) ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-indigo-50' }}">{{ $category->name_ar }}</a>
                @endforeach
            </div>

            <form method="GET" class="mb-8 grid grid-cols-1 gap-3 rounded-3xl border border-slate-100 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
                <select name="category" class="rounded-2xl border-slate-200 text-right">
                    <option value="">كل التصنيفات</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->slug }}" @selected(request('category') === $category->slug)>{{ $category->name_ar }}</option>
                    @endforeach
                </select>
                <select name="age" class="rounded-2xl border-slate-200 text-right">
                    <option value="">كل الأعمار</option>
                    @foreach(['1-3','3-6','6-9','9-12','12+'] as $age)
                        <option value="{{ $age }}" @selected(request('age') === $age)>{{ $age }}</option>
                    @endforeach
                </select>
                <select name="personalization" class="rounded-2xl border-slate-200 text-right">
                    <option value="">كل أنواع التخصيص</option>
                    <option value="none" @selected(request('personalization') === 'none')>بدون تخصيص</option>
                    <option value="inherit_from_linked_story" @selected(request('personalization') === 'inherit_from_linked_story')>يستخدم قصة الطفل</option>
                </select>
                <select name="sort" class="rounded-2xl border-slate-200 text-right">
                    <option value="featured" @selected(request('sort') === 'featured')>المميزة</option>
                    <option value="newest" @selected(request('sort') === 'newest')>الأحدث</option>
                    <option value="price_asc" @selected(request('sort') === 'price_asc')>السعر من الأقل</option>
                    <option value="price_desc" @selected(request('sort') === 'price_desc')>السعر من الأعلى</option>
                </select>
                <button class="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">تطبيق</button>
            </form>

            @if($products->count())
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach($products as $product)
                        @include('front.shop._product-card', ['product' => $product])
                    @endforeach
                </div>
                <div class="mt-8">{{ $products->links() }}</div>
            @else
                <div class="rounded-3xl border border-dashed border-slate-200 bg-white p-10 text-center">
                    <h2 class="text-xl font-black text-slate-950">لا توجد منتجات متاحة حالياً</h2>
                    <p class="mt-2 text-slate-500">سيتم عرض المنتجات هنا بعد تفعيلها من لوحة الإدارة.</p>
                </div>
            @endif
        </div>
    </div>
</x-front-layout>
