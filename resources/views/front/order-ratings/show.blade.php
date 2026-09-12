<x-front-layout>
    <x-slot name="pageTitle">تقييم طلب HeroKid</x-slot>
    <x-slot name="robots">noindex, nofollow, noarchive</x-slot>

    @php
        $ratingItems = $group['active_orders']
            ->flatMap->items
            ->groupBy(fn ($item) => implode('|', [$item->item_type, $item->story_id, $item->product_id, $item->title]))
            ->map(function ($items) {
                $item = $items->first();

                return [
                    'title' => $item->title ?: 'منتج من HeroKid',
                    'quantity' => (int) $items->sum(fn ($current) => (int) $current->quantity),
                ];
            })
            ->values();
        $selectedRating = (int) old('quality_rating', $rating?->qualityRating() ?? 0);
    @endphp

    <main class="min-h-[70vh] bg-slate-50 py-8 sm:py-12">
        <div class="mx-auto max-w-3xl space-y-5 px-4 sm:px-6">
            @if(session('success'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-black text-emerald-800" role="status">{{ session('success') }}</div>
            @endif

            <section class="overflow-hidden rounded-3xl bg-gradient-to-l from-indigo-700 to-violet-600 p-6 text-white shadow-xl shadow-indigo-100 sm:p-8">
                <div class="flex items-center justify-between gap-5">
                    <div class="text-right">
                        <p class="text-sm font-bold text-indigo-100">أهلاً {{ $group['customer_name'] }}</p>
                        <h1 class="mt-2 text-2xl font-black sm:text-3xl">رأيك يهمنا</h1>
                        <p class="mt-2 text-sm font-bold leading-6 text-indigo-100">شاركنا تقييمك لتجربة طلبك من HeroKid.</p>
                    </div>
                    <img src="{{ asset('images/logo-192.png') }}" alt="HeroKid" class="h-16 w-16 shrink-0 rounded-2xl bg-white object-contain p-1.5 shadow-lg">
                </div>
            </section>

            <section class="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span class="rounded-full bg-indigo-50 px-3 py-1.5 font-mono text-xs font-black text-indigo-700" dir="ltr">{{ $group['short_reference'] ?: $group['key'] }}</span>
                    <div class="text-right">
                        <p class="text-xs font-bold text-slate-400">تفاصيل الطلب</p>
                        <h2 class="mt-1 text-lg font-black text-slate-950">{{ $group['status_label'] }}</h2>
                    </div>
                </div>
                <div class="mt-5 space-y-2">
                    @foreach($ratingItems as $item)
                        <div class="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 px-4 py-3">
                            <span class="shrink-0 rounded-full bg-white px-2.5 py-1 text-xs font-black text-indigo-700">{{ $item['quantity'] }} ×</span>
                            <p class="text-sm font-black text-slate-800">{{ $item['title'] }}</p>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 font-black text-slate-900">
                    <span>{{ format_money($group['total_cents'] / 100) }}</span>
                    <span>إجمالي الطلب</span>
                </div>
            </section>

            @if($rating)
                <section class="rounded-3xl border border-amber-200 bg-white p-6 text-center shadow-sm sm:p-8" data-customer-rating-submitted>
                    <div class="text-4xl" aria-label="{{ $rating->qualityRating() }} من 5 نجوم" dir="ltr">
                        @for($star = 1; $star <= 5; $star++)
                            <span class="{{ $star <= $rating->qualityRating() ? 'text-amber-400' : 'text-slate-200' }}">★</span>
                        @endfor
                    </div>
                    <h2 class="mt-4 text-xl font-black text-slate-950">شكراً لتقييمك</h2>
                    <p class="mt-2 text-sm font-bold text-slate-500">تم استلام تقييم هذا الطلب بنجاح.</p>
                    @if($rating->customer_comment)
                        <blockquote class="mt-5 rounded-2xl bg-slate-50 px-5 py-4 text-sm font-bold leading-7 text-slate-700">{{ $rating->customer_comment }}</blockquote>
                    @endif
                </section>
            @else
                <section class="rounded-3xl border border-slate-100 bg-white p-6 shadow-sm sm:p-8">
                    @if($errors->any())
                        <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-black text-red-700" role="alert">{{ $errors->first() }}</div>
                    @endif

                    <form method="POST" action="{{ $submitUrl }}" class="space-y-7" data-order-rating-form>
                        @csrf
                        <fieldset class="text-center">
                            <legend class="w-full text-lg font-black text-slate-950">مدي رضاك عن جودة المنتج؟</legend>
                            <p class="mt-1 text-xs font-bold text-slate-400">اختر من نجمة إلى خمس نجوم</p>
                            <div class="mt-4 flex justify-center gap-1" dir="ltr" data-star-rating>
                                @for($star = 1; $star <= 5; $star++)
                                    <input class="sr-only" type="radio" name="quality_rating" value="{{ $star }}" id="quality-rating-{{ $star }}" @checked($selectedRating === $star) required>
                                    <label for="quality-rating-{{ $star }}" class="cursor-pointer select-none text-5xl leading-none text-slate-200 transition hover:scale-110" data-star-value="{{ $star }}" aria-label="{{ $star }} نجوم">★</label>
                                @endfor
                            </div>
                        </fieldset>

                        <div>
                            <label for="customer-comment" class="block text-right text-base font-black text-slate-950">تعليق/اقتراح علي الخدمة</label>
                            <p class="mt-1 text-right text-xs font-bold text-slate-400">اختياري — نقرأ كل تعليق بعناية.</p>
                            <textarea id="customer-comment" name="customer_comment" rows="5" maxlength="2000" class="mt-3 w-full rounded-2xl border-slate-200 text-right text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="اكتب تعليقك أو اقتراحك هنا...">{{ old('customer_comment') }}</textarea>
                        </div>

                        <button class="min-h-12 w-full rounded-2xl bg-indigo-600 px-6 py-3 text-base font-black text-white shadow-lg shadow-indigo-100 transition hover:bg-indigo-700">إرسال التقييم</button>
                    </form>
                </section>
            @endif
        </div>
    </main>

    @unless($rating)
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const rating = document.querySelector('[data-star-rating]');
                if (!rating) return;

                const inputs = [...rating.querySelectorAll('input[type="radio"]')];
                const stars = [...rating.querySelectorAll('[data-star-value]')];
                const paint = (value) => stars.forEach((star) => {
                    star.classList.toggle('text-amber-400', Number(star.dataset.starValue) <= value);
                    star.classList.toggle('text-slate-200', Number(star.dataset.starValue) > value);
                });
                const selected = () => Number(inputs.find((input) => input.checked)?.value || 0);

                inputs.forEach((input) => input.addEventListener('change', () => paint(Number(input.value))));
                stars.forEach((star) => {
                    star.addEventListener('mouseenter', () => paint(Number(star.dataset.starValue)));
                    star.addEventListener('focus', () => paint(Number(star.dataset.starValue)));
                });
                rating.addEventListener('mouseleave', () => paint(selected()));
                paint(selected());
            });
        </script>
    @endunless
</x-front-layout>
