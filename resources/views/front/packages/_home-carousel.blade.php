@php
    $initialPackageIndex = $packages->search(fn ($package) => $package->is_featured);
    $initialPackageIndex = $initialPackageIndex === false ? (int) floor(($packages->count() - 1) / 2) : $initialPackageIndex;
@endphp

<div data-home-package-carousel data-initial-index="{{ $initialPackageIndex }}" class="home-package-carousel" dir="rtl" aria-roledescription="carousel" aria-label="باقات HeroKid">
    <div class="relative">
        <div data-home-package-track class="home-package-track" tabindex="0">
            @foreach($packages as $pkg)
                <article data-home-package-slide data-index="{{ $loop->index }}" class="home-package-slide {{ $loop->index === $initialPackageIndex ? 'is-active' : '' }}" aria-label="{{ $pkg->name }}، {{ $loop->iteration }} من {{ $packages->count() }}">
                    <a href="{{ route('shop.package.show', $pkg) }}" class="home-package-card group" aria-label="عرض باقة {{ $pkg->name }}">
                        @if($pkg->badge || $pkg->discountPercentage())
                            <div class="flex min-h-7 flex-wrap items-center justify-between gap-2">
                                @if($pkg->badge)
                                    <span class="rounded-full bg-amber-300 px-3 py-1 text-[11px] font-black text-amber-950">⭐ {{ $pkg->badge }}</span>
                                @else
                                    <span></span>
                                @endif
                                @if($discount = $pkg->discountPercentage())
                                    <span class="rounded-full bg-emerald-400/15 px-3 py-1 text-[11px] font-black text-emerald-200" dir="rtl">خصم {{ arabic_number($discount) }}٪</span>
                                @endif
                            </div>
                        @endif

                        @if($pkg->image_url)
                            <span class="mt-3 block aspect-[4/3] overflow-hidden rounded-2xl bg-white/10">
                                <img src="{{ $pkg->image_url }}" alt="{{ $pkg->name }}" width="720" height="540" loading="lazy" class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
                            </span>
                        @endif

                        <span class="mt-4 block text-xl font-black leading-8 text-white">{{ $pkg->name }}</span>
                        @if($pkg->subtitle)
                            <span class="mt-1 block min-h-10 text-sm leading-6 text-indigo-200">{{ $pkg->subtitle }}</span>
                        @endif

                        <span class="mt-4 flex items-baseline justify-start gap-2" dir="rtl">
                            <strong class="text-4xl font-black text-white">{{ format_money((float) $pkg->price) }}</strong>
                            @if((float) $pkg->regular_price > (float) $pkg->price)
                                <span class="text-sm font-bold text-indigo-300 line-through">{{ format_money((float) $pkg->regular_price) }}</span>
                            @endif
                        </span>

                        @if($pkg->features && count($pkg->features))
                            <ul class="mt-5 space-y-2 text-sm leading-6 text-indigo-100">
                                @foreach(array_slice($pkg->features, 0, 4) as $feature)
                                    <li class="flex items-start gap-2">
                                        <span class="mt-1 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-400/20 text-xs text-emerald-200">✓</span>
                                        <span>{{ $feature }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <span class="mt-auto block pt-5">
                            <span class="block rounded-xl border border-white/20 bg-white/10 px-4 py-3 text-center text-sm font-black text-white transition group-hover:bg-white group-hover:text-violet-700">{{ $pkg->button_text ?: 'اختر الباقة' }}</span>
                        </span>
                    </a>
                </article>
            @endforeach
        </div>

        @if($packages->count() > 1)
            <button type="button" data-home-package-previous class="home-package-arrow home-package-arrow-previous" aria-label="عرض الباقة السابقة">→</button>
            <button type="button" data-home-package-next class="home-package-arrow home-package-arrow-next" aria-label="عرض الباقة التالية">←</button>
        @endif
    </div>

    @if($packages->count() > 1)
        <div class="mt-6 flex items-center justify-center gap-2" role="tablist" aria-label="اختيار الباقة">
            @foreach($packages as $pkg)
                <button type="button" data-home-package-dot data-index="{{ $loop->index }}" class="home-package-dot {{ $loop->index === $initialPackageIndex ? 'is-active' : '' }}" aria-label="عرض {{ $pkg->name }}" aria-selected="{{ $loop->index === $initialPackageIndex ? 'true' : 'false' }}"></button>
            @endforeach
        </div>
    @endif

    <p data-home-package-status class="sr-only" aria-live="polite"></p>
</div>
