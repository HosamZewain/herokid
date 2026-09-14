@if($customerRating)
    <section class="rounded-2xl border border-amber-200 bg-gradient-to-l from-amber-50 to-white p-4 shadow-sm" data-order-customer-rating>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex items-center gap-3" dir="ltr" aria-label="{{ $customerRating->qualityRating() }} من 5 نجوم">
                <div class="flex text-xl leading-none">
                    @for($star = 1; $star <= 5; $star++)
                        <span class="{{ $star <= $customerRating->qualityRating() ? 'text-amber-400' : 'text-slate-200' }}">★</span>
                    @endfor
                </div>
                <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-black text-amber-800">{{ $customerRating->qualityRating() }}/5</span>
            </div>
            <div class="text-right">
                <p class="text-sm font-black text-slate-950">تقييم العميل</p>
                <p class="mt-1 text-[10px] font-bold text-slate-400">تم الإرسال {{ app_datetime($customerRating->decided_at, 'd/m/Y h:i A') }}</p>
            </div>
        </div>
        @if($customerRating->customer_comment)
            <p class="mt-3 rounded-xl border border-amber-100 bg-white/80 px-4 py-3 text-sm font-bold leading-6 text-slate-700">{{ $customerRating->customer_comment }}</p>
        @endif
    </section>
@endif
