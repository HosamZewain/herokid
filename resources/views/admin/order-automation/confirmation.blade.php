<section class="space-y-5 rounded-2xl border border-indigo-100 bg-white p-6" aria-labelledby="confirmation-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="confirmation-heading" class="text-xl font-bold">تأكيد الطلب عبر واتساب</h2>
        <span class="rounded-full px-4 py-2 {{ $confirmationReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
            {{ $confirmationReady ? 'مفعّل للطلبات الجديدة فقط' : 'الإرسال غير مفعّل — الربط قيد التجهيز' }}
        </span>
    </div>
    <ol class="grid gap-3 md:grid-cols-3">
        <li class="rounded-xl bg-blue-50 p-4"><strong>١. طلب جديد غير مؤكد</strong><p class="mt-2 text-sm">يصل الطلب وتُجهّز له رسالة واحدة لكل عملية شراء، حتى لو تضم أكثر من قصة أو منتج.</p></li>
        <li class="rounded-xl bg-violet-50 p-4"><strong>٢. رسالة تأكيد على واتساب</strong><p class="mt-2 text-sm">يرسل RoboDesk قالب التأكيد المعتمد، بتفاصيل الطلب وقيمته بعد الخصم وشاملة الشحن.</p></li>
        <li class="rounded-xl bg-emerald-50 p-4"><strong>٣. طلب جديد مؤكد</strong><p class="mt-2 text-sm">ضغط العميل على «تأكيد الطلب» يحدّث حالة الطلب ويسجل التأكيد. لا يبدأ توليدًا أو شحنًا أو دفعًا.</p></li>
    </ol>
    <p class="text-sm text-gray-600">القالب: <bdi>herokid_order_confirmation</bdi>. القالب معتمد؛ تفعيل الإرسال ينتظر اكتمال الربط مع RoboDesk. الرسائل القديمة لا تُرسل عند التفعيل، والتأكيد المتأخر لا يعيد الطلب إلى مرحلة سابقة.</p>
    @can('orders.view')
        @php($confirmationLabels = ['held' => 'محجوزة دون إرسال', 'pending' => 'في انتظار الإرسال', 'processing' => 'جارٍ التسليم إلى RoboDesk', 'succeeded' => 'قبلها RoboDesk', 'failed' => 'تحتاج مراجعة', 'unknown' => 'نتيجة الإرسال غير مؤكدة', 'blocked' => 'الإرسال متوقف ويحتاج تدخل'])
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
            <div class="rounded-xl bg-emerald-50 p-4"><span class="text-sm">أكدها العميل</span><strong class="mt-1 block text-2xl">{{ number_format($confirmedCount) }}</strong></div>
            @foreach($confirmationLabels as $key => $label)
                <div class="rounded-xl bg-slate-50 p-4"><span class="text-sm">{{ $label }}</span><strong class="mt-1 block text-2xl">{{ number_format($confirmationCounts[$key] ?? 0) }}</strong></div>
            @endforeach
        </div>
        <h3 class="font-bold">آخر طلبات إرسال التأكيد</h3>
        <p class="text-sm text-gray-500">قبول RoboDesk للرسالة ليس إثبات وصولها للعميل؛ حالة واتساب النهائية تُراجع في RoboDesk. السجل هنا يعرض المسار الجديد فقط.</p>
        <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead><tr><th class="p-3">الطلب</th><th>حالة الإرسال</th><th>تأكيد العميل</th><th>المحاولات</th><th>وقت الإنشاء</th></tr></thead><tbody>
        @forelse($confirmationRequests as $confirmationRequest)
            <tr class="border-t"><td class="p-3">{{ $confirmationRequest->order?->order_number ?? '—' }}</td><td>{{ $confirmationLabels[$confirmationRequest->status] ?? $confirmationRequest->status }}</td><td>{{ $confirmationRequest->confirmationWorkflow?->confirmation_status === 'confirmed' ? 'مؤكد' : 'لم يؤكد بعد' }}</td><td>{{ $confirmationRequest->attempts }}</td><td>{{ \App\Support\AppDateTime::format($confirmationRequest->created_at, 'd/m/Y H:i') }}</td></tr>
        @empty
            <tr><td colspan="5" class="p-4 text-gray-500">لا توجد رسائل في مسار التأكيد الجديد بعد.</td></tr>
        @endforelse
        </tbody></table></div>
    @endcan
</section>
