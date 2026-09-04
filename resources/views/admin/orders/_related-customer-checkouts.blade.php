@if(($relatedCustomerCheckouts['total'] ?? 0) > 0)
    <section class="rounded-2xl border-2 border-amber-300 bg-amber-50 p-4 shadow-sm" data-related-customer-checkouts>
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <span class="inline-flex w-fit shrink-0 rounded-full bg-amber-200 px-3 py-1.5 text-xs font-black text-amber-950">
                {{ $relatedCustomerCheckouts['total'] }} طلب مرتبط
            </span>
            <div class="text-right">
                <h3 class="text-base font-black text-amber-950">تنبيه: توجد طلبات أخرى لنفس رقم الهاتف</h3>
                <p class="mt-1 text-xs font-bold leading-5 text-amber-800">راجع الطلبات المرتبطة قبل بدء الإنتاج لتجنب تنفيذ نفس طلب العميل أكثر من مرة.</p>
            </div>
        </div>

        <div class="mt-4 grid gap-2 md:grid-cols-2 xl:grid-cols-4">
            @foreach($relatedCustomerCheckouts['checkouts'] as $relatedCheckout)
                <a href="{{ route('admin.orders.groups.show', $relatedCheckout['representative_id']) }}" class="rounded-xl border border-amber-200 bg-white p-3 text-right transition hover:border-amber-400 hover:bg-amber-100/40">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-[10px] font-black text-amber-700">{{ $relatedCheckout['status_label'] }}</span>
                        <span class="font-mono text-sm font-black text-indigo-700" dir="ltr">{{ $relatedCheckout['reference'] }}</span>
                    </div>
                    <p class="mt-2 line-clamp-2 text-xs font-bold leading-5 text-gray-700">{{ implode('، ', $relatedCheckout['titles']) ?: 'طلب بدون تفاصيل عناصر' }}</p>
                    <p class="mt-2 text-[10px] font-bold text-gray-400" dir="ltr">{{ app_datetime($relatedCheckout['created_at'], 'd/m/Y h:i A') }}</p>
                </a>
            @endforeach
        </div>
    </section>
@endif
