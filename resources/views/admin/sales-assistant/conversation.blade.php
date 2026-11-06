<x-admin-layout>
    <x-slot name="header"><h1 class="text-xl font-black">تفاصيل محادثة مساعد المبيعات</h1></x-slot>
    <div dir="rtl" class="space-y-5">
        <a href="{{ route('admin.sales-assistant.index') }}" class="font-bold text-indigo-700 underline">رجوع لتقرير الاستخدام</a>
        <p class="text-sm text-slate-600">المحادثة {{ substr($conversation->id,0,8) }} · النص منقّح ومشفّر في التخزين، ومتاح لمدة 30 يومًا. العميل يرسل واتساب ويؤكد الطلب بنفسه.</p>
        @foreach($turns as $turn)
            <article class="space-y-3 rounded-2xl border bg-white p-5">
                <p class="text-xs text-slate-500">{{ $turn->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i:s') }} القاهرة · {{ ['queued'=>'بانتظار العامل','processing'=>'جاري الرد','completed'=>'اكتمل الرد','unavailable'=>'الخدمة غير متاحة','uncertain'=>'النتيجة غير مؤكدة — لا إعادة تلقائية','invalid_response'=>'رد غير صالح للعرض','expired'=>'انتهى وقت الانتظار دون توليد'][$turn->status] ?? $turn->status }}</p>
                <p class="rounded-xl bg-indigo-50 p-3 whitespace-pre-wrap"><strong>العميل: </strong>{{ $turn->request_text }}</p>
                @if($turn->result)<p class="rounded-xl bg-slate-50 p-3 whitespace-pre-wrap"><strong>المساعد: </strong>{{ $turn->result['message'] ?? '' }}</p>
                    @foreach($turn->result['recommendations'] ?? [] as $choice)<p class="text-sm">{{ $choice['key'] }} · {{ $choice['reason'] }}</p>@endforeach
                @endif
                <p class="text-xs text-slate-500">أدوات: {{ $turn->tool_calls }} @if($turn->started_at) · زمن الرد: {{ number_format($turn->started_at->diffInMilliseconds($turn->finished_at ?? now())/1000,1) }} ثانية @endif</p>
                @if($canFinance)<p class="text-xs">إدخال: {{ $turn->input_tokens }} · إخراج: {{ $turn->output_tokens }} · كاش: {{ $turn->cached_tokens }} · تفكير ضمن الإخراج: {{ $turn->reasoning_tokens }} · تكلفة مقدّرة: ${{ number_format($turn->spent_micros/1000000,5) }} · حجز: ${{ number_format($turn->reserved_micros/1000000,4) }}</p>@endif
            </article>
        @endforeach
        {{ $turns->links() }}
        <section class="rounded-2xl border bg-white p-5"><h2 class="font-black">آخر 100 تفاعل</h2>
            @foreach($events as $event)<p class="mt-2 text-sm"><bdi>{{ $event->type }}</bdi> · {{ $event->catalog_key }} · {{ \Carbon\Carbon::parse($event->created_at)->timezone('Africa/Cairo')->format('d/m H:i:s') }}</p>@endforeach
        </section>
    </div>
</x-admin-layout>
