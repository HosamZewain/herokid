<x-front-layout>
<x-slot name="pageTitle">{{ $story->title }} — مكتبة HeroKid</x-slot>
<x-slot name="pageDescription">{{ \Illuminate\Support\Str::limit($story->description ?: 'اقرأ القصة كاملة مجانًا في مكتبة HeroKid.', 160) }}</x-slot>
<div class="bg-slate-50 py-8 sm:py-12" dir="rtl"><div class="mx-auto max-w-5xl px-4 sm:px-6">
    <a href="{{ route('story-library.index') }}" class="font-bold text-indigo-700 underline">العودة لمكتبة القصص</a>
    <article class="mt-6 grid gap-8 rounded-3xl bg-white p-6 shadow-sm md:grid-cols-2 sm:p-8">
        <img src="{{ $story->cover_path ? route('story-library.cover', $story) : asset('images/logo-320.png') }}" alt="غلاف {{ $story->title }}" width="480" height="600" class="aspect-[4/5] w-full rounded-2xl bg-indigo-50 object-contain">
        <div class="space-y-5 self-center"><p class="font-bold text-indigo-700">{{ $story->category ?: 'حكايات HeroKid' }} · قراءة مجانية</p><h1 class="text-3xl font-black text-slate-950">{{ $story->title }}</h1><p class="text-sm font-bold text-slate-500">{{ $story->language === 'ar' ? 'باللغة العربية' : 'باللغة الإنجليزية' }} · {{ $story->page_count }} صفحة @if($story->age_min !== null || $story->age_max !== null) · عمر {{ $story->age_min ?? 0 }} إلى {{ $story->age_max ?? 18 }} سنة @endif</p><p class="whitespace-pre-wrap leading-8 text-slate-700">{{ $story->description }}</p><a href="{{ route('story-library.read', $story) }}" class="inline-block rounded-2xl bg-indigo-600 px-8 py-4 font-black text-white">ابدأ قراءة القصة</a><p class="text-sm text-slate-500">القصة كاملة في القارئ التفاعلي، بدون تسجيل دخول.</p></div>
    </article>
</div></div>
</x-front-layout>
