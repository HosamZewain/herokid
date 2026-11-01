@if(isset($messageHistoryOrder) && ($orderPaymentReviews ?? collect())->isNotEmpty())
<section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 space-y-4" data-payment-review>
    <h3 class="font-black text-amber-900">مراجعة الدفع — مرفقات العميل</h3>
    <p class="text-sm text-amber-900">المرفق لا يثبت حدوث دفع. راجع رسالة العميل في RoboDesk، ثم سجّل المبلغ الفعلي يدويًا من المدفوعات بعد التحقق.</p>
    <a href="https://hero-kid.robodesk.ai/v2/" target="_blank" rel="noopener noreferrer" class="font-bold underline text-indigo-700">فتح RoboDesk — ابحث برقم العميل</a>
    @foreach($orderPaymentReviews as $review)
        <div class="rounded-xl border border-amber-200 bg-white p-4 space-y-3">
            <p class="font-bold">{{ $review->status === 'pending' ? 'مرفق من العميل — بانتظار مراجعة الدفع' : 'تم إنهاء مراجعة المرفقات' }}</p>
            <p class="text-sm">عدد رسائل المرفقات: {{ $review->signals_count }} · أول رصد: {{ app_datetime($review->created_at, 'd/m/Y h:i A') }}</p>
            @if($review->status === 'pending')
                @can('robodesk.review_payments')
                <form action="{{ route('admin.orders.payment-review.close', $messageHistoryOrder) }}" method="POST" class="space-y-3">
                    @csrf
                    <input type="hidden" name="review_id" value="{{ $review->id }}">
                    <input type="hidden" name="seen_signal_id" value="{{ $review->signals_max_id }}">
                    <label class="block text-sm font-bold">نتيجة المراجعة (مثلاً: ليس إثباتًا، أو تم التحقق وتسجيل المبلغ يدويًا)
                        <textarea name="note" required minlength="3" maxlength="1000" class="mt-2 block w-full rounded-xl border-gray-300" rows="2"></textarea>
                    </label>
                    <label class="block text-sm"><input type="checkbox" name="confirm" value="1" required> راجعت المرفقات؛ إنهاء المراجعة يوقف متابعة مرفقات هذا المسار ولا يسجّل دفعة أو يستكمل الإنتاج.</label>
                    <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white">إنهاء المراجعة فقط</button>
                </form>
                @endcan
            @else
                <p class="text-sm">بواسطة {{ $review->reviewer?->name }} · {{ app_datetime($review->reviewed_at, 'd/m/Y h:i A') }}</p>
                @can('robodesk.review_payments')<p class="text-sm whitespace-pre-line">{{ $review->review_note }}</p>@endcan
            @endif
        </div>
    @endforeach
</section>
@endif
