<x-front-layout>
    <x-slot name="pageTitle">مراجعة وتأكيد طلبك — HeroKid</x-slot>
    <div class="mx-auto max-w-3xl p-4 sm:p-6" dir="rtl" data-sales-review>
        <p class="text-sm font-bold text-indigo-600">الخطوة الأخيرة · مراجعة وتأكيد</p>
        <h1 class="my-3 text-2xl font-black text-slate-900">كله تمام؟ راجع طلبك قبل التأكيد</h1>
        <p class="mb-5 text-slate-600">الطلب هيتسجل بعد ضغط «تأكيد الطلب» فقط. الدفع وموافقة التصميم بيتموا في خطواتهم المعتادة.</p>
        <div class="space-y-3">
            @foreach($items as $item)
                <article class="rounded-2xl border border-slate-200 bg-white p-4">
                    <h2 class="font-black">{{ $item['story_title'] ?? $item['product_title'] ?? 'منتج' }}</h2>
                    <p class="mt-2">{{ $item['child_name'] ?? data_get($item, 'linked_story_snapshot.child_name') ?? 'بدون تخصيص' }}
                        @if(!empty($item['child_age'])) · العمر: {{ $item['child_age'] }} @endif
                        @if(!empty($item['child_gender'])) · {{ $item['child_gender'] === 'girl' ? 'بنت' : 'ولد' }} @endif
                    </p>
                    <p class="text-sm text-slate-600">الكمية: {{ $item['quantity'] ?? 1 }} · صور الطفل: {{ count($item['uploaded_photos'] ?? []) }}
                        @if(!empty($item['variant_name'])) · {{ $item['variant_name'] }} @endif
                        @if(!empty($item['story_language'])) · اللغة: {{ $item['story_language'] === 'en' ? 'الإنجليزية' : 'العربية' }} @endif
                    </p>
                    @foreach(['interests' => 'الاهتمامات', 'gift_note' => 'الإهداء', 'parent_notes' => 'ملاحظات'] as $key => $label)
                        @if(!empty($item[$key])) <p class="mt-1 text-sm">{{ $label }}: {{ $item[$key] }}</p> @endif
                    @endforeach
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach($item['uploaded_photos'] ?? [] as $path)
                            <img src="{{ route('sales.photo', hash('sha256',$path)) }}" alt="صورة {{ $item['child_name'] ?? 'الطفل' }}" class="h-20 w-16 rounded-lg object-cover" loading="lazy" width="64" height="80">
                        @endforeach
                    </div>
                    <p class="mt-2 font-bold text-indigo-700">{{ number_format(($item['story_price'] ?? (($item['line_total_cents'] ?? 0)/100)), 2) }} ج.م</p>
                </article>
            @endforeach
        </div>
        <section class="my-5 rounded-2xl bg-indigo-50 p-4">
            <h2 class="font-black">بيانات الاستلام</h2>
            <p class="mt-2">{{ $fields['parent_name'] }} · <bdi>{{ $fields['phone'] }}</bdi></p>
            @if(!empty($fields['alternate_phone'])) <p><bdi>{{ $fields['alternate_phone'] }}</bdi></p> @endif
            <p>{{ $country }}، {{ $governorate }}، {{ $fields['city'] ?? '' }}، {{ $fields['street'] }}</p>
            <p>{{ $fields['address_details'] ?? '' }}</p>
            @if(($fields['previous_order_action'] ?? '') === 'merge') <p class="mt-2">طلب ضم الشحن مع {{ $fields['previous_order_reference'] }}؛ يخضع لفحص الطلب السابق وقد يحتاج مراجعة الفريق.</p> @endif
        </section>
        <dl class="space-y-2 rounded-2xl border border-slate-200 p-4">
            @foreach(['قيمة العناصر' => $subtotalCents, 'الخصم' => $discountCents, 'الشحن' => $feeCents, 'الإجمالي' => $totalCents] as $label => $amount)
                <div class="flex justify-between gap-4"><dt>{{ $label }}</dt><dd class="font-black">{{ number_format($amount/100, 2) }} ج.م</dd></div>
            @endforeach
        </dl>
        <form method="POST" action="{{ route('checkout.store') }}" class="mt-5 space-y-3" data-sales-confirm>
            @csrf
            @foreach($fields as $key => $value)
                @if(is_scalar($value)) <input type="hidden" name="{{ $key }}" value="{{ $value }}"> @endif
            @endforeach
            <input type="hidden" name="_sales_assistant_embed" value="1">
            <input type="hidden" name="_sales_assistant_quote" value="{{ $quote }}">
            <button type="submit" class="min-h-12 w-full rounded-xl bg-indigo-600 p-3 font-black text-white">تأكيد الطلب</button>
            <a href="{{ route('cart.index', ['_sales_assistant_embed' => 1]) }}" class="block py-3 text-center font-bold text-indigo-700">رجوع لتعديل السلة والعنوان</a>
        </form>
    </div>
</x-front-layout>
