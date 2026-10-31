@if(isset($messageHistoryOrder) && $orderMessageStages->isNotEmpty())
<section class="bg-white border border-slate-200 rounded-2xl p-5 mb-6" aria-label="سجل رسائل الأوتوميشن">
    <h2 class="font-bold text-lg text-slate-800">سجل رسائل الأوتوميشن</h2>
    <p class="text-sm text-slate-500 mt-1 mb-4">كل مرحلة ومحاولاتها لهذا الطلب بجميع قصصه ومنتجاته · الأوقات بتوقيت القاهرة. قبول RoboDesk مختلف عن التسليم للعميل.</p>
    @foreach($orderMessageStages as $stage)
        @php($latest = $stage['attempts']->last())
        <div class="border border-slate-200 rounded-xl p-4 mb-3">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <h3 class="font-bold text-slate-800">{{ $stage['name'] }}</h3>
                <span class="bg-slate-100 text-slate-700 rounded-full px-3 py-1 text-sm">المحاولات: {{ $stage['count'] }}</span>
                <span class="rounded-full px-3 py-1 text-sm {{ in_array($latest['status'], ['unknown','uncertain','blocked']) ? 'bg-amber-100 text-amber-800' : 'bg-indigo-50 text-indigo-700' }}">{{ $latest['send'] }}</span>
                <span class="rounded-full px-3 py-1 text-sm {{ $latest['delivery_status'] === 'failed' ? 'bg-red-100 text-red-800' : ($latest['delivery_status'] === 'delivered' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-600') }}">{{ $latest['delivery'] }}</span>
            </div>
            <details class="text-sm">
                <summary class="cursor-pointer text-indigo-700 py-2">عرض تفاصيل المحاولات</summary>
                <div class="overflow-x-auto">
                    <table class="w-full text-right text-sm"><thead><tr class="text-slate-500"><th class="p-2">التاريخ</th><th class="p-2">الإرسال</th><th class="p-2">التسليم</th><th class="p-2">بواسطة</th><th class="p-2">سبب إعادة المحاولة</th></tr></thead><tbody>
                    @foreach($stage['attempts'] as $attempt)
                        <tr class="border-t border-slate-100"><td class="p-2 whitespace-nowrap" dir="ltr">{{ $attempt['at'] }}</td><td class="p-2">{{ $attempt['send'] }}@if($attempt['count'] > 1)<div class="text-xs text-slate-500">{{ $attempt['count'] }} محاولات تاريخية مسجلة؛ تفاصيل كل محاولة غير متاحة.</div>@endif</td><td class="p-2">{{ $attempt['delivery'] }}</td><td class="p-2">{{ $attempt['actor'] }}</td><td class="p-2">{{ $attempt['reason'] ?: '—' }}</td></tr>
                    @endforeach
                    </tbody></table>
                </div>
            </details>
            @if($stage['retry'])
                <details class="mt-2 text-sm">
                    <summary class="cursor-pointer font-bold text-indigo-700">إعادة إرسال المحاولة الفاشلة</summary>
                    <form method="POST" action="{{ route('admin.orders.message-retry', $messageHistoryOrder) }}" class="mt-3 flex flex-col gap-3">
                        @csrf
                        <input type="hidden" name="source" value="{{ $stage['source'] }}"><input type="hidden" name="source_id" value="{{ $stage['source_id'] }}"><input type="hidden" name="version" value="{{ $stage['version'] }}">
                        <label>سبب إعادة المحاولة<input name="reason" required minlength="3" maxlength="300" class="block w-full rounded-lg border-slate-300 mt-1" placeholder="اكتب سبب المحاولة الجديدة"></label>
                        <label class="flex items-start gap-2"><input type="checkbox" name="confirm" value="1" required class="mt-1">أوافق على إرسال رسالة واتساب جديدة، وإغلاق محادثة RoboDesk المفتوحة لهذا العميل إن وجدت. ستظل المحاولة السابقة محفوظة.</label>
                        <button class="self-start bg-indigo-600 text-white rounded-lg px-4 py-2" type="submit">تأكيد إعادة الإرسال</button>
                    </form>
                </details>
            @elseif(in_array($latest['status'], ['unknown','uncertain','processing']))
                <p class="text-xs text-amber-800 mt-2">إعادة الإرسال غير متاحة قبل التحقق من النتيجة، منعًا لتكرار الرسالة.</p>
            @elseif($latest['delivery_status'] === 'failed' || $latest['status'] === 'blocked')
                <p class="text-xs text-slate-500 mt-2">إعادة الإرسال تحتاج صلاحية تنفيذ الأوتوميشن، وطلبًا ما زال مؤهلًا لهذه المرحلة.</p>
            @endif
        </div>
    @endforeach
</section>
@endif
