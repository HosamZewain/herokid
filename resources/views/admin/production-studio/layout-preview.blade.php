@php
    $bookTitle = data_get($settings, 'book_title') ?: ($project->order?->story?->title ?: 'HeroKid');
    $coverSubtitle = data_get($settings, 'cover_subtitle');
    $coverTitlePosition = data_get($settings, 'cover_title_position', 'top');
    $backCoverText = data_get($settings, 'back_cover_text', '');
    $website = data_get($settings, 'website', 'hero-kid.com');
@endphp

<x-admin-layout>
    <div dir="rtl" class="space-y-6">
        <div class="flex flex-col gap-3 rounded-xl bg-white p-5 shadow-sm md:flex-row md:items-center md:justify-between">
            <div class="text-right">
                <p class="text-sm font-bold text-indigo-600">معاينة ترتيب القارئ</p>
                <h1 class="text-2xl font-black text-gray-950">{{ $bookTitle }}</h1>
                <p class="mt-1 text-sm text-gray-500">28 صفحة A4: غلاف أمامي، 13 سبريد قصة، وغلاف خلفي.</p>
            </div>
            <a href="{{ route('admin.production-studio.show', $project) }}#layout-print" class="rounded-xl bg-gray-100 px-5 py-3 text-center font-black text-gray-700">العودة إلى الإخراج والطباعة</a>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <article class="overflow-hidden rounded-xl bg-white p-3 shadow-sm">
                <div class="mb-2 flex items-center justify-between text-sm font-black"><span>صفحة 1</span><span>الغلاف الأمامي</span></div>
                @if($coverAsset)
                    <div class="relative aspect-[210/297] overflow-hidden">
                        <img src="{{ route('admin.production-studio.layout.assets.preview', [$project, $coverAsset]) }}" alt="الغلاف الأمامي" class="h-full w-full object-cover">
                        <div class="absolute inset-x-5 {{ $coverTitlePosition === 'bottom' ? 'bottom-5' : 'top-5' }} rounded-lg bg-white/90 p-4 text-center text-gray-950">
                            <p class="text-2xl font-black">{{ $bookTitle }}</p>
                            @if($coverSubtitle)<p class="mt-2 font-bold">{{ $coverSubtitle }}</p>@endif
                        </div>
                    </div>
                @else
                    <div class="flex aspect-[210/297] items-center justify-center bg-gray-900 p-8 text-center text-2xl font-black text-white">لا يوجد غلاف أمامي</div>
                @endif
            </article>

            @foreach($project->scenes->sortBy('scene_number') as $scene)
                @php
                    $sceneSettings = data_get($settings, 'scenes.'.$scene->id, []);
                    $textSide = $sceneSettings['text_side'] ?? 'left';
                    $textPosition = $sceneSettings['text_position'] ?? 'bottom';
                    $asset = $scene->approvedFinalImage;
                    $firstPage = $scene->scene_number * 2;
                @endphp
                <article class="lg:col-span-2 rounded-xl bg-white p-3 shadow-sm">
                    <div class="mb-2 flex items-center justify-between text-sm font-black"><span>صفحتا {{ $firstPage }}–{{ $firstPage + 1 }}</span><span>المشهد {{ $scene->scene_number }}: {{ $scene->title }}</span></div>
                    <div class="relative aspect-[420/297] overflow-hidden rounded-lg bg-gray-100">
                        @if($asset)
                            <img src="{{ route('admin.production-studio.layout.assets.preview', [$project, $asset]) }}" alt="المشهد {{ $scene->scene_number }}" class="h-full w-full object-cover">
                            <div class="absolute bottom-0 top-0 left-1/2 w-px bg-white/70"></div>
                            <div class="absolute inset-y-0 {{ $textSide === 'right' ? 'right-0' : 'left-0' }} flex w-1/2 p-5 {{ $textPosition === 'top' ? 'items-start' : ($textPosition === 'center' ? 'items-center' : 'items-end') }}">
                                <p class="w-full rounded-lg bg-white/90 p-4 text-right text-sm font-bold leading-7 text-gray-900">{{ $sceneSettings['text_content'] ?? $scene->story_text }}</p>
                            </div>
                        @else
                            <div class="flex h-full items-center justify-center font-black text-red-600">لا توجد صورة نهائية معتمدة</div>
                        @endif
                    </div>
                </article>
            @endforeach

            <article class="overflow-hidden rounded-xl bg-white p-3 shadow-sm">
                <div class="mb-2 flex items-center justify-between text-sm font-black"><span>صفحة 28</span><span>الغلاف الخلفي</span></div>
                @if($backCoverAsset)
                    <img src="{{ route('admin.production-studio.layout.assets.preview', [$project, $backCoverAsset]) }}" alt="الغلاف الخلفي" class="aspect-[210/297] w-full object-cover">
                @else
                    <div class="flex aspect-[210/297] flex-col items-center justify-center bg-gray-900 p-8 text-center text-xl font-black leading-9 text-white">
                        <span>{{ $backCoverText }}</span>
                        <span class="mt-5 text-indigo-200">{{ $website }}</span>
                    </div>
                @endif
            </article>
        </div>
    </div>
</x-admin-layout>
