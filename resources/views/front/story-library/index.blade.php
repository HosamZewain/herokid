<x-front-layout>
<x-slot name="pageTitle">مكتبة القصص المجانية — HeroKid</x-slot>
<x-slot name="pageDescription">حكايات كاملة يقرأها طفلك مجانًا. تصفح مكتبة HeroKid واقرأ القصص في قارئ PDF التفاعلي بدون تسجيل دخول.</x-slot>
<div class="bg-slate-50 py-8 sm:py-12" dir="rtl">
    <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6">
        <header class="rounded-3xl bg-indigo-950 p-6 text-white sm:p-10"><p class="font-bold text-indigo-200">حكاية جديدة… كل مرة</p><h1 class="mt-3 text-3xl font-black sm:text-4xl">مكتبة القصص</h1><p class="mt-4 max-w-2xl text-base leading-8 text-indigo-100">اختاروا حكاية واقرأوها سوا. قصص كاملة ومجانية، بدون تسجيل دخول أو شراء.</p></header>
        <form method="GET" class="grid gap-4 rounded-2xl bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
            <label class="font-bold">ابحث عن قصة<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="عنوان أو كلمة من الوصف" class="mt-2 w-full rounded-xl border-slate-300"></label>
            <label class="font-bold">التصنيف<select name="category" class="mt-2 w-full rounded-xl border-slate-300"><option value="">كل التصنيفات</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>@endforeach</select></label>
            <label class="font-bold">اللغة<select name="language" class="mt-2 w-full rounded-xl border-slate-300"><option value="">كل اللغات</option><option value="ar" @selected(($filters['language'] ?? '') === 'ar')>عربي</option><option value="en" @selected(($filters['language'] ?? '') === 'en')>إنجليزي</option></select></label>
            <label class="font-bold">عمر الطفل<input name="age" type="number" min="0" max="18" value="{{ $filters['age'] ?? '' }}" placeholder="اختياري" class="mt-2 w-full rounded-xl border-slate-300"></label>
            <div class="flex items-end gap-3"><button class="flex-1 rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">عرض القصص</button><a href="{{ route('story-library.index') }}" class="py-3 text-sm font-bold text-indigo-700 underline">مسح</a></div>
        </form>
        <p class="font-bold text-slate-600">{{ $stories->total() }} قصة متاحة</p>
        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @forelse($stories as $story)
            <article class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                <a href="{{ route('story-library.show', $story) }}" class="block"><img src="{{ $story->cover_path ? route('story-library.cover', $story) : \App\Support\SiteBranding::url() }}" alt="غلاف {{ $story->title }}" width="400" height="500" loading="lazy" class="aspect-[4/5] w-full bg-indigo-50 object-contain"></a>
                <div class="space-y-3 p-5"><p class="text-xs font-bold text-indigo-700">{{ $story->category ?: 'حكايات HeroKid' }} · {{ $story->language === 'ar' ? 'عربي' : 'إنجليزي' }} · {{ $story->page_count }} صفحة</p><h2 class="text-xl font-black text-slate-950"><a href="{{ route('story-library.show', $story) }}">{{ $story->title }}</a></h2><p class="line-clamp-2 text-sm leading-7 text-slate-600">{{ $story->description }}</p><a href="{{ route('story-library.read', $story) }}" class="inline-block rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">اقرأ القصة</a></div>
            </article>
            @empty<div class="rounded-3xl bg-white p-10 text-center sm:col-span-2 lg:col-span-3 xl:col-span-4"><h2 class="text-xl font-black text-slate-900">{{ $filters ? 'لا توجد قصص تطابق اختيارك' : 'حكاياتنا الأولى جاية قريب' }}</h2><p class="mt-3 text-slate-600">{{ $filters ? 'جرّب تغيير البحث أو مسح الفلاتر.' : 'ارجع لنا قريبًا علشان تقرأ حكايات جديدة مع طفلك.' }}</p></div>@endforelse
        </div>{{ $stories->links() }}
    </div>
</div>
</x-front-layout>
