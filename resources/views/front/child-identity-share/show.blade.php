<x-front-layout>
    <x-slot name="pageTitle">{{ $public['title'] }}</x-slot>
    <x-slot name="pageDescription">{{ $public['description'] }}</x-slot>
    <x-slot name="pageImage">{{ $public['og_image'] }}</x-slot>
    <x-slot name="ogImageWidth">1200</x-slot>
    <x-slot name="ogImageHeight">900</x-slot>
    <x-slot name="canonical">{{ $public['url'] }}</x-slot>
    <x-slot name="robots">noindex, follow</x-slot>
    <x-slot name="ogType">website</x-slot>

    <main class="min-h-screen bg-gradient-to-b from-violet-950 via-indigo-950 to-slate-950 py-8 text-white sm:py-14" dir="rtl">
        <div class="mx-auto max-w-6xl px-4">
            <section class="overflow-hidden rounded-[2rem] border border-white/10 bg-white/5 shadow-2xl backdrop-blur">
                <div class="grid items-center gap-8 p-5 sm:p-8 lg:grid-cols-2 lg:p-12">
                    <div class="order-2 text-center lg:order-1 lg:text-right">
                        <img src="/images/logo-320.png" alt="HeroKid" class="mx-auto h-24 w-auto lg:mx-0">
                        <p class="mt-5 text-sm font-black text-fuchsia-300">هوية طفل مميزة من HeroKid</p>
                        <h1 class="mt-3 text-3xl font-black leading-tight sm:text-5xl">{{ $public['title'] }}</h1>
                        <p class="mt-4 text-base leading-8 text-indigo-100">{{ $public['description'] }}</p>

                        <div class="mt-7 grid grid-cols-3 gap-2 text-center text-xs font-black sm:gap-3 sm:text-sm">
                            @foreach(['ارفع الصور', 'شاهد الهوية', 'اختر القصة'] as $index => $step)
                                <div class="rounded-2xl border border-white/10 bg-white/10 p-3 sm:p-4">
                                    <span class="mx-auto flex h-8 w-8 items-center justify-center rounded-full bg-fuchsia-500">{{ arabic_number($index + 1) }}</span>
                                    <span class="mt-2 block">{{ $step }}</span>
                                </div>
                            @endforeach
                        </div>

                        <a href="{{ route('child-identity-shares.cta', [
                                'share' => $share->public_token,
                                'utm_source' => request('utm_source', 'identity_share'),
                                'utm_medium' => 'identity_share',
                                'utm_campaign' => 'free_child_identity',
                            ]) }}"
                           class="mt-7 block w-full rounded-2xl bg-gradient-to-l from-fuchsia-500 to-orange-500 px-6 py-4 text-center text-lg font-black text-white shadow-lg shadow-fuchsia-950/40">
                            {{ $public['cta'] }}
                        </a>
                        <a href="{{ route('shop.index', ['type' => 'stories']) }}" class="mt-4 inline-flex font-black text-indigo-200 underline decoration-indigo-400 underline-offset-4">
                            اكتشف قصص HeroKid
                        </a>
                    </div>

                    <div class="order-1 lg:order-2">
                        <img src="{{ $public['feed_image'] }}" alt="بطاقة هوية طفل من HeroKid"
                             class="mx-auto aspect-[4/3] w-full max-w-2xl rounded-3xl bg-white object-contain shadow-2xl"
                             width="1200" height="900">
                    </div>
                </div>
            </section>
        </div>
    </main>
    @push('scripts')
        <script>
            (() => {
                const properties = {
                    channel: @json(request('utm_source', 'direct')),
                    identity_type: 'child_identity',
                    anonymous_share_identifier: @json($public['anonymous_share_id']),
                    campaign_name: 'free_child_identity',
                };
                if (typeof window.gtag === 'function') window.gtag('event', 'child_identity_share_page_viewed', properties);
                if (typeof window.fbq === 'function') window.fbq('trackCustom', 'child_identity_share_page_viewed', properties);
                document.querySelector('a[href*="/start"]')?.addEventListener('click', () => {
                    if (typeof window.gtag === 'function') window.gtag('event', 'child_identity_share_cta_clicked', properties);
                    if (typeof window.fbq === 'function') window.fbq('trackCustom', 'child_identity_share_cta_clicked', properties);
                });
            })();
        </script>
    @endpush
</x-front-layout>
