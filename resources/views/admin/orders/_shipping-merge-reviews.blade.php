@foreach($shippingMergeReviews ?? [] as $shippingReview)
    <section class="my-4 rounded-2xl border border-red-200 bg-red-50 p-4" data-shipping-merge-review="{{ $shippingReview->id }}">
        <h3 class="font-black text-red-800">طلب العميل ضم الشحن — بانتظار مراجعة الفريق</h3>
        <p class="mt-2 text-sm">الطلب الجديد مستقل، وطلب ضم الشحن لا يغيّر وضع التوليد. راجع إمكانية الشحن معًا والمبلغ النهائي قبل إنشاء البوليصة؛ لم تُخصم رسوم الشحن تلقائيًا.</p>
        @can('orders.view_all')
            @php
                $shippingOrders = \App\Models\Order::withTrashed()->with('checkoutReference')->whereIn('id', [$shippingReview->source_order_id, $shippingReview->target_order_id])->get();
            @endphp
            <div class="my-2 flex gap-4">
                @foreach($shippingOrders as $shippingOrder)
                    <a class="font-bold underline" href="{{ route('admin.orders.groups.show', $shippingOrder) }}">{{ $shippingOrder->checkoutReference?->short_reference ?: $shippingOrder->order_number }}</a>
                @endforeach
            </div>
            @can('orders.update')
                <details><summary class="cursor-pointer font-bold">تسجيل نتيجة مراجعة ضم الشحن</summary>
                    <form method="POST" action="{{ route('admin.orders.groups.shipping-merge.resolve', [$attachmentTarget, $shippingReview]) }}" class="mt-2">
                        @csrf
                        <p class="text-xs">يسجل قرار الفريق فقط. نفّذ الدمج أو تعديل الشحن والمبلغ من أدوات الطلب المناسبة أولًا.</p>
                        <textarea name="resolution" required minlength="5" maxlength="2000" class="my-2 w-full rounded-lg border-gray-300" placeholder="سجل ما تم الاتفاق عليه مع العميل ونتيجة مراجعة الشحن"></textarea>
                        <button class="rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white">إنهاء المراجعة</button>
                    </form>
                </details>
            @endcan
        @else
            <p class="mt-2 text-sm">اطلب من مدير لديه صلاحية مشاهدة جميع الطلبات مراجعة الطلبين وتسجيل القرار.</p>
        @endcan
    </section>
@endforeach
