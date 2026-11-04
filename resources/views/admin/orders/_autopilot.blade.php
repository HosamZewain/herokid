@php($pilot = app(\App\Services\Agentic\OrderActivityProjection::class)->forOrder($order))
<section id="order-autopilot" class="space-y-3 rounded-xl border bg-white p-5" dir="rtl">
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-bold">HeroKid Auto Pilot — قرارات وخطوات هذا الطلب</h2>
        @if(auth()->user()->hasPermission('order_automation.view') && !app(\App\Services\Orders\OrderAccess::class)->restricted())<a class="text-indigo-700 underline" href="{{ route('admin.agentic.show',$order) }}">سجل القرارات والذاكرة</a>@endif</div>
    <p>آخر قراءة: {{ $pilot['state']?->observed_at?->timezone('Africa/Cairo')->format('Y-m-d H:i:s') ?: 'لم يُقرأ بعد' }} بتوقيت القاهرة · {{ __('agentic.states.'.($pilot['state']?->status ?? 'unobserved')) }}</p>
    @if(!config('agentic.evaluate'))<p>مراجعة الذكاء الاصطناعي متوقفة؛ بيانات الإنتاج وسجل التنفيذ متاحان.</p>
    @elseif($pilot['runtime']?->provider_error)<p>مراجعة القرارات تنتظر استعادة اتصال المساعد أو تأكيد المصادقة والعزل.</p>
    @elseif($pilot['runtime']?->quota_day === now('Africa/Cairo')->toDateString() && $pilot['runtime']->attempts >= config('agentic.daily_limit'))<p>التقييم التالي ينتظر تجدد حصة القرارات اليومية؛ عرض الإنتاج مستمر.</p>
    @elseif($pilot['runtime']?->activity_state_id === $pilot['state']?->id && $pilot['runtime']?->lease_until && \Carbon\Carbon::parse($pilot['runtime']->lease_until)->isFuture())<p>Auto Pilot يراجع هذا الطلب الآن: {{ __('agentic.activity.'.$pilot['runtime']->activity_stage) }}</p>@endif
    @if($pilot['state']?->dirty_at)<p class="font-bold text-amber-700">تغيّرت بيانات الطلب؛ التقييم التالي سيستخدم النسخة الجديدة. الأسباب التالية تخص آخر قراءة محفوظة.</p>@endif
    <ul class="list-inside list-disc">@foreach($pilot['targets'] as $target)<li><bdi>{{ $target['target_id'] }}</bdi>: {{ __('agentic.decisions.'.$target['decision']) }} — {{ __('agentic.reasons.'.$target['reason_code']) }}</li>@endforeach</ul>
    <details><summary class="cursor-pointer font-bold">آخر القرارات ({{ $pilot['total'] }} قرارًا محفوظًا)</summary>
        @forelse($pilot['history'] as $run)
            <article class="my-2 rounded border p-3"><p>{{ $run->started_at->timezone('Africa/Cairo')->format('Y-m-d H:i:s') }} · نسخة {{ $run->context_version }}</p>
                @if($run->decision)<p>{{ __('agentic.decisions.'.$run->decision->decision['decision']) }} · <bdi>{{ $run->decision->decision['target_id'] }}</bdi> · {{ __('agentic.reasons.'.$run->decision->decision['reason_code']) }}</p><p>نتيجة فحص القرار: {{ $run->decision->policy['allowed_proposal'] ? 'اجتاز فحص القواعد' : 'لم يجتز فحص القواعد ويحتاج مراجعة' }}. سجل الاقتراح وحده لا يثبت التنفيذ.</p>@else<p>تعذر الحصول على قرار صالح؛ لم يُطلب تنفيذ.</p>@endif
                <p class="text-xs">مرجع القرار: <bdi>{{ $run->id }}</bdi></p></article>
        @empty<p>لم يصدر قرار بعد؛ متابعة الإنتاج الحالية مستمرة.</p>@endforelse
        @if($pilot['total'] > 10)<p>معروض أحدث 10 قرارات؛ افتح سجل القرارات للاطلاع على الباقي.</p>@endif
    </details>
    @include('admin.agentic._execution', ['execution' => $pilot['execution']])
    <p class="text-sm">كل قرار جديد وتسليم خطوة وتغير في نتيجة التنفيذ يُحفظ باسم HeroKid Auto Pilot في سجل نشاط الطلب.</p>
</section>
