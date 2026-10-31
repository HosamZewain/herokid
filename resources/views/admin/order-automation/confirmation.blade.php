<section class="space-y-5 rounded-2xl border border-indigo-100 bg-white p-6" aria-labelledby="confirmation-heading">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="confirmation-heading" class="text-xl font-bold">تأكيد الطلب عبر واتساب</h2>
        <span class="rounded-full px-4 py-2 {{ $confirmationReady ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
            {{ $confirmationReady ? (config('robodesk.confirmation.audience') === 'trial' ? 'تجربة محدودة للأرقام المعتمدة فقط' : 'مفعّل للطلبات الجديدة فقط') : 'الإرسال غير مفعّل — الربط قيد التجهيز' }}
        </span>
    </div>
    <ol class="grid gap-3 md:grid-cols-3">
        <li class="rounded-xl bg-blue-50 p-4"><strong>١. طلب جديد غير مؤكد</strong><p class="mt-2 text-sm">يصل الطلب وتُجهّز له رسالة واحدة لكل عملية شراء، حتى لو تضم أكثر من قصة أو منتج.</p></li>
        <li class="rounded-xl bg-violet-50 p-4"><strong>٢. رسالة تأكيد على واتساب</strong><p class="mt-2 text-sm">تُغلق محادثة العميل المفتوحة أولًا، ثم يرسل RoboDesk قالب التأكيد المعتمد، بتفاصيل الطلب وقيمته بعد الخصم وشاملة الشحن.</p></li>
        <li class="rounded-xl bg-emerald-50 p-4"><strong>٣. طلب جديد مؤكد</strong><p class="mt-2 text-sm">ضغط العميل على «تأكيد الطلب» أو كتابة نفس العبارة يسجل التأكيد عند تحديث المحادثة. لا يبدأ توليدًا أو شحنًا أو دفعًا.</p></li>
    </ol>
    <p class="text-sm text-gray-600">القالب: <bdi>herokid_order_confirmation</bdi>. القالب معتمد. {{ $confirmationReady ? 'الربط مفعّل حسب نطاق الإرسال الموضّح أعلاه.' : 'الإرسال متوقف حاليًا.' }} الرسائل القديمة لا تُرسل عند التفعيل، والتأكيد المتأخر لا يعيد الطلب إلى مرحلة سابقة.</p>
    <p class="text-sm text-gray-600">قراءة التأكيدات دورية وليست فورية: المحادثات النشطة تُحدّث عادة كل ١٠ دقائق، وقد تتأخر مع ضغط الطابور. لا تُعاد الرسالة تلقائيًا عند الشك في نتيجة الإرسال.</p>
    @can('orders.view')
        @php($confirmationLabels = ['held' => 'محجوزة دون إرسال', 'pending' => 'في انتظار الإرسال', 'processing' => 'جارٍ التسليم إلى RoboDesk', 'succeeded' => 'قبلها RoboDesk', 'failed' => 'تحتاج مراجعة', 'unknown' => 'نتيجة الإرسال غير مؤكدة', 'blocked' => 'الإرسال متوقف ويحتاج تدخل'])
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
            <div class="rounded-xl bg-emerald-50 p-4"><span class="text-sm">أكدها العميل</span><strong class="mt-1 block text-2xl">{{ number_format($confirmedCount) }}</strong></div>
            @foreach($confirmationLabels as $key => $label)
                <div class="rounded-xl bg-slate-50 p-4"><span class="text-sm">{{ $label }}</span><strong class="mt-1 block text-2xl">{{ number_format($confirmationCounts[$key] ?? 0) }}</strong></div>
            @endforeach
        </div>
        <h3 class="font-bold">آخر طلبات إرسال التأكيد</h3>
        <p class="text-sm text-gray-500">التسليم حسب آخر قراءة كاملة من RoboDesk. قبول الإرسال لا يثبت الوصول، والحالة المجهولة ليست نجاحًا. فشل التسليم يحتاج متابعة بقناة أخرى دون إعادة إرسال تلقائية.</p>
        <div class="overflow-x-auto"><table class="w-full text-right text-sm"><thead><tr><th class="p-3">الطلب</th><th>حالة الإرسال</th><th>التسليم للعميل</th><th>تأكيد العميل</th><th>المحاولات</th><th>وقت الإنشاء</th></tr></thead><tbody>
        @forelse($confirmationRequests as $confirmationRequest)
            <tr class="border-t"><td class="p-3">{{ $confirmationRequest->order?->order_number ?? '—' }}</td><td>{{ $confirmationLabels[$confirmationRequest->status] ?? $confirmationRequest->status }}</td><td class="{{ data_get($confirmationRequest->response_payload, 'delivery.status') === 'failed' ? 'text-red-700 font-bold' : '' }}">{{ \App\Services\RoboDesk\TemplateDelivery::label(data_get($confirmationRequest->response_payload, 'delivery.status')) }}</td><td>{{ $confirmationRequest->confirmationWorkflow?->confirmation_status === 'confirmed' ? 'مؤكد' : (str_starts_with($confirmationRequest->last_error ?? '', 'confirmation_review:') ? 'تأكيد يحتاج مراجعة' : 'لم يؤكد بعد') }}</td><td>{{ $confirmationRequest->attempts }}</td><td>{{ \App\Support\AppDateTime::format($confirmationRequest->created_at, 'd/m/Y H:i') }}</td></tr>
        @empty
            <tr><td colspan="6" class="p-4 text-gray-500">لا توجد رسائل في مسار التأكيد الجديد بعد.</td></tr>
        @endforelse
        </tbody></table></div>
    @endcan
</section>
