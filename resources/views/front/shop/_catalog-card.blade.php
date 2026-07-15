<article data-catalog-type="{{ $item->type }}" class="group flex h-full flex-col overflow-hidden rounded-3xl border bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl {{ $item->type === 'story' ? 'border-pink-100' : 'border-indigo-100' }}">
    <a href="{{ $item->detailUrl }}" class="block">
        <div class="relative aspect-[4/3] overflow-hidden {{ $item->type === 'story' ? 'bg-gradient-to-br from-orange-50 to-pink-50' : 'bg-gradient-to-br from-indigo-50 to-cyan-50' }}">
            @if($item->imageUrl)
                <img src="{{ $item->imageUrl }}" alt="{{ $item->title }}" loading="lazy"
                     onerror="this.classList.add('hidden'); this.nextElementSibling.classList.remove('hidden');"
                     class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                <div class="hidden h-full w-full"><x-product-image-placeholder /></div>
            @else
                <x-product-image-placeholder />
            @endif
            <span class="absolute right-3 top-3 rounded-full px-3 py-1.5 text-xs font-black shadow-sm {{ $item->type === 'story' ? 'bg-pink-600 text-white' : 'bg-indigo-700 text-white' }}">{{ $item->badgeLabel }}</span>
        </div>
    </a>

    <div class="flex flex-1 flex-col p-5 text-right">
        <div class="mb-3 flex flex-wrap gap-2">
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ $item->ageRange }}</span>
            <span class="rounded-full px-3 py-1 text-xs font-bold {{ $item->personalizationType === 'none' ? 'bg-emerald-50 text-emerald-700' : ($item->personalizationType === 'story_context' ? 'bg-amber-50 text-amber-700' : 'bg-violet-50 text-violet-700') }}">{{ $item->personalizationLabel }}</span>
        </div>

        @if($item->category)
            <p class="mb-1 text-xs font-black {{ $item->type === 'story' ? 'text-pink-600' : 'text-indigo-600' }}">{{ $item->category }}</p>
        @endif
        <h3 class="text-lg font-black leading-7 text-slate-950 transition group-hover:text-indigo-700">
            <a href="{{ $item->detailUrl }}">{{ $item->title }}</a>
        </h3>
        @if($item->shortDescription)
            <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">{{ $item->shortDescription }}</p>
        @endif

        <div class="mt-auto flex items-center justify-between gap-3 border-t border-slate-100 pt-5 {{ $item->shortDescription ? 'mt-5' : 'mt-8' }}">
            <span class="text-lg font-black {{ $item->type === 'story' ? 'text-pink-700' : 'text-indigo-700' }}">{{ $item->priceLabel }}</span>
            <a href="{{ $item->detailUrl }}" class="rounded-xl px-4 py-2.5 text-xs font-black text-white transition hover:scale-105 {{ $item->type === 'story' ? 'bg-gradient-to-l from-pink-600 to-orange-500' : 'bg-indigo-600 hover:bg-indigo-700' }}">{{ $item->ctaLabel }}</a>
        </div>
    </div>
</article>
