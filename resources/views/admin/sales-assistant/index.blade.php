<x-admin-layout>
    <x-slot name="header"><h1 class="text-xl font-black">مساعد المبيعات — الاستخدام والنتائج</h1></x-slot>
    <div dir="rtl" class="space-y-6">
        @foreach(['success','error'] as $notice) @if(session($notice)) <p role="status" class="rounded-xl bg-indigo-50 p-4">{{ session($notice) }}</p> @endif @endforeach
        @if($errors->any()) <p role="alert" class="rounded-xl bg-red-50 p-4 text-red-800">{{ $errors->first() }}</p> @endif
        <form class="flex flex-wrap items-end gap-3" method="GET">
            <label>من<input name="from" type="date" value="{{ $from->toDateString() }}" class="block rounded-lg"></label>
            <label>إلى<input name="to" type="date" value="{{ $to->toDateString() }}" class="block rounded-lg"></label>
            <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">عرض</button>
        </form>
        <p class="text-sm text-slate-600">الفترة بتوقيت القاهرة. الزائر المميز يعني متصفحًا له معرّف خاص بالمساعد؛ مش عدد أشخاص مؤكد. الإحصائيات لا تحتوي صورًا أو أرقام تواصل.</p>
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach(['زوار مميزون' => $visitors, 'محادثات' => $conversations, 'رسائل العملاء' => ($counts['message_received'] ?? 0), 'ردود ناجحة' => ($counts['reply_completed'] ?? 0), 'ترشيحات' => ($counts['recommended'] ?? 0), 'ترشيحات إضافية' => ($counts['upsell_recommended'] ?? 0), 'اختيارات' => ($counts['selected'] ?? 0), 'إضافات للسلة' => ($counts['cart_added'] ?? 0), 'مراجعات نهائية' => ($counts['checkout_review'] ?? 0), 'طلبات مكتملة' => ($counts['order_created'] ?? 0), 'فتح واتساب' => ($counts['handoff'] ?? 0), 'رفض إضافات' => ($counts['upsell_declined'] ?? 0), 'رسائل رفضتها الحدود' => (($counts['message_rejected'] ?? 0)+($counts['rate_limited'] ?? 0))] as $label => $value)
                <div class="rounded-2xl border bg-white p-4"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-black text-indigo-700">{{ number_format($value) }}</p></div>
            @endforeach
        </div>
        <section class="rounded-2xl border bg-white p-5">
            <h2 class="font-black">السرعة والأخطاء</h2><p class="mt-2 font-bold">عامل المحادثة: {{ $assistantSettings->workerReady() ? 'متاح' : 'غير متاح — المحادثة الذكية تتوقف مؤقتًا والسلة مستمرة' }}</p>
            <p class="mt-2">متوسط المعالجة: {{ number_format(($performance->avg_ms ?? 0)/1000,1) }} ثانية · أطول معالجة: {{ number_format(($performance->max_ms ?? 0)/1000,1) }} ثانية · أدوات استُدعيت: {{ $performance->tool_calls ?? 0 }}</p>
            <div class="mt-3 flex flex-wrap gap-3">@foreach($counts as $type => $count) @if(str_starts_with($type,'reply_') && $type !== 'reply_completed') <span class="rounded-lg bg-amber-50 p-2"><bdi>{{ $type }}</bdi>: {{ $count }}</span> @endif @endforeach</div>
            <p class="mt-3 text-sm text-slate-500">النتيجة غير المؤكدة تحتفظ بحجز التكلفة ولا تُعاد تلقائيًا. الرسائل المجدولة والمتوقفة تظهر في المحادثات أدناه. فتح واتساب لا يثبت أن العميل أرسل الرسالة.</p>
        </section>
        @if($canFinance)
            <section class="rounded-2xl border bg-white p-5">
                <h2 class="font-black">التوكنز والتكلفة والتحويلات</h2>
                <p class="mt-2">توكنز الإدخال: {{ number_format($usage->input_tokens ?? 0) }} · الإخراج: {{ number_format($usage->output_tokens ?? 0) }} · من الإدخال كاش: {{ number_format($usage->cached_tokens ?? 0) }} · من الإخراج تفكير: {{ number_format($usage->reasoning_tokens ?? 0) }} · تقدير التكلفة: ${{ number_format(($usage->spent_micros ?? 0)/1000000,4) }}</p>
                <p class="mt-2">قيمة الطلبات المنسوبة للمساعد: {{ number_format(($usage->order_value_cents ?? 0)/100,2) }} ج.م. نسبة المحادثات لطلبات: {{ $conversations ? number_format(($counts['order_created'] ?? 0)/$conversations*100,1) : 0 }}٪</p>
                <p class="mt-2 text-sm text-slate-500">التكلفة تقدير محافظ من الاستهلاك الفعلي؛ يشمل هامش كتابة الكاش. الفاتورة النهائية لدى OpenAI. الطلب المنسوب يحتفظ بمصدر الموقع والإعلان الأصلي.</p>
                <table class="mt-4 w-full text-right"><thead><tr><th>اليوم بالقاهرة</th><th>تكلفة مقدّرة $</th><th>حجز غير محسوم $</th></tr></thead><tbody>
                    @foreach($budgets as $day) <tr class="border-t"><td class="py-2">{{ $day->day }}</td><td>{{ number_format($day->spent_micros/1000000,4) }}</td><td>{{ number_format($day->reserved_micros/1000000,4) }}</td></tr> @endforeach
                </tbody></table>
            </section>
        @endif
        @can('settings.site.update')
            <section class="space-y-4 rounded-2xl border bg-white p-5">
                <h2 class="font-black">تشغيل المساعد · GPT-6 Luna فقط</h2>
                <form method="POST" action="{{ route('admin.sales-assistant.verify') }}">@csrf<button class="rounded-xl border px-4 py-3 font-bold">فحص إتاحة الموديل (بدون توليد مدفوع)</button><span class="mx-2">{{ $assistantSettings->verified() ? 'تم التحقق خلال 7 أيام' : 'الإتاحة غير مؤكدة' }}</span></form>
                <form method="POST" action="{{ route('admin.sales-assistant.update') }}" class="flex flex-wrap items-center gap-4">@csrf
                    <label><input name="enabled" type="checkbox" value="1" @checked($assistantSettings->enabled())> تفعيل</label>
                    <label>حد الدولار اليومي <input name="daily_budget_usd" type="number" min="0" max="100" step="0.01" value="{{ $assistantSettings->budget()/1000000 }}" class="w-24 rounded-lg"></label>
                    <label><input name="experience_approved" type="checkbox" value="1"> راجعت تجربة الشراء وأعتمد تفعيلها</label>
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">حفظ</button>
                </form>
                <p class="text-sm text-slate-500">تعطيل المساعد لا يلغي طلبات مكتملة. حدود افتراضية: 5 رسائل/دقيقة، 40 دورًا مدفوعًا/يوم للجلسة، 4 أدوات/دور، وعامل واحد. بيانات المحادثة مشفرة وتُحذف بعد 30 يومًا؛ إحصائيات الاستخدام تظل محفوظة. store:false لا يلغي سجلات المراقبة لدى OpenAI.</p>
            </section>
            <section class="rounded-2xl border bg-white p-5">
                <h2 class="mb-4 font-black">وسوم ومرادفات الترشيح</h2>
                <form method="POST" action="{{ route('admin.sales-assistant.profile') }}" class="space-y-3">@csrf
                    <label class="block">العنصر<select name="key" class="mt-2 block w-full rounded-lg">@foreach($catalog->all() as $item)<option value="{{ $item['key'] }}">{{ $item['title'] }} · {{ $item['key'] }}</option>@endforeach</select></label>
                    <label class="block">وسوم إضافية (تحل محل الوسوم السابقة لهذا العنصر)<textarea name="keywords" rows="2" class="mt-2 block w-full rounded-lg" maxlength="1500" placeholder="الصلاة، المسجد، حب العبادة"></textarea></label>
                    <button class="rounded-xl border px-5 py-3 font-bold">حفظ الوسوم</button>
                </form>
                <details class="mt-4"><summary>الوسوم الحالية</summary>@foreach($catalog->all() as $item) @if($item['keywords'])<p class="mt-2 text-sm">{{ $item['title'] }}: {{ $item['keywords'] }}</p>@endif @endforeach</details>
            </section>
        @endcan
        <section class="overflow-x-auto rounded-2xl border bg-white p-5">
            <h2 class="mb-4 font-black">أكثر العناصر تفاعلًا</h2><table class="w-full"><thead><tr><th>العنصر</th><th>التفاعل</th><th>العدد</th></tr></thead><tbody>@foreach($top as $row)<tr class="border-t"><td class="py-2">{{ $catalog->all()[$row->catalog_key]['title'] ?? $row->catalog_key }}</td><td>{{ $row->type }}</td><td>{{ $row->total }}</td></tr>@endforeach</tbody></table>
        </section>
        @can('settings.site.update')
            <section class="rounded-2xl border bg-white p-5"><h2 class="mb-4 font-black">المحادثات خلال مدة الاحتفاظ</h2>
                <p class="mb-3 text-sm text-slate-500">الرسائل منقحة ومشفرة؛ رابط القراءة للمصرّح لهم فقط، ولا يتيح تعديل الطلبات أو إرسال رسائل للعملاء.</p>
                @foreach($recent as $conversation)<p class="my-2"><a class="text-indigo-700 underline" href="{{ route('admin.sales-assistant.conversation',$conversation) }}" target="_blank" rel="noopener">محادثة {{ substr($conversation->id,0,8) }}</a> · {{ $conversation->updated_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</p>@endforeach
                {{ $recent->links() }}
            </section>
        @endcan
    </div>
</x-admin-layout>
