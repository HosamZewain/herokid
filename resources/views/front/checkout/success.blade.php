<x-front-layout>

{{-- ══ SEO ══ --}}
<x-slot name="pageTitle">تم استلام طلبك بنجاح</x-slot>
<x-slot name="robots">noindex, nofollow</x-slot>

@php
    $subtotal = (float) ($order->delivery_details['subtotal'] ?? $orders->sum(fn($item) => (float) ($item->story->price ?? 0)));
    $deliveryFee = (float) ($order->delivery_details['delivery_fee'] ?? 0);
    $total = (float) ($order->delivery_details['total'] ?? ($subtotal + $deliveryFee));
@endphp

@if(!empty($facebookPurchaseEvent['data']))
    @push('scripts')
        <script>
            if (typeof fbq === 'function') {
                fbq('track', 'Purchase', @json($facebookPurchaseEvent['data']), {
                    eventID: @json($facebookPurchaseEvent['event_id'])
                });
            }
        </script>
    @endpush
@endif

    <div class="bg-gray-50 py-20 min-h-[70vh] flex flex-col justify-center">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center bg-white p-10 rounded-3xl shadow-xl border border-gray-100">
            
            <div class="w-24 h-24 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            </div>

            <h1 class="text-4xl font-extrabold text-gray-900 mb-4">تم استلام طلبك بنجاح!</h1>
            <p class="text-xl text-gray-600 mb-8">
                شكراً لك! استلمنا طلبك وسيبدأ فريق HeroKid في المراجعة.
            </p>
            
            <div class="bg-indigo-50 rounded-xl p-6 mb-8 text-right">
                <h2 class="font-bold text-indigo-900 mb-4 text-lg border-b border-indigo-100 pb-2">تفاصيل الطلب:</h2>
                <div class="space-y-4">
                    @foreach($orders as $createdOrder)
                        <div class="bg-white rounded-xl border border-indigo-100 p-4">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                <span class="bg-indigo-50 px-2 py-1 rounded border dir-ltr font-mono text-indigo-900 font-bold">#{{ $createdOrder->order_number }}</span>
                                <div class="text-right">
                                    <p class="font-bold text-indigo-900">{{ $createdOrder->story->title ?? ($createdOrder->items->first()?->title ?? 'طلب متجر') }}</p>
                                    <p class="text-sm text-indigo-700">
                                        @if($createdOrder->child_name)
                                            الطفل: {{ $createdOrder->child_name }}
                                        @else
                                            {{ $createdOrder->items->count() }} عنصر في الطلب
                                        @endif
                                    </p>
                                    @if($createdOrder->items->where('item_type', '!=', 'story')->count())
                                        <div class="mt-3 space-y-1 text-sm text-indigo-700">
                                            @foreach($createdOrder->items->where('item_type', '!=', 'story') as $orderItem)
                                                <p>• {{ $orderItem->title }} × {{ $orderItem->quantity }}</p>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-5 pt-4 border-t border-indigo-100 space-y-2 text-indigo-900">
                    <div class="flex justify-between"><span class="font-bold">{{ number_format($subtotal, 0) }} ج.م</span><span>إجمالي العناصر</span></div>
                    <div class="flex justify-between"><span class="font-bold">{{ number_format($deliveryFee, 0) }} ج.م</span><span>مصاريف التوصيل</span></div>
                    <div class="flex justify-between text-lg"><span class="font-black">{{ number_format($total, 0) }} ج.م</span><span class="font-black">الإجمالي</span></div>
                </div>
            </div>

            <div class="text-gray-500 mb-10 text-sm bg-yellow-50 p-4 rounded-lg">
                <p>سنقوم بمراجعة بيانات الطلب وتجهيز المنتجات المطلوبة.</p>
                <p class="mt-2">سيتم التواصل معك عبر الواتساب على الرقم <strong>{{ $order->delivery_details['phone'] }}</strong> بخصوص الدفع وتأكيد التصميم النهائي.</p>
            </div>

            <div class="flex flex-col sm:flex-row justify-center gap-4">
                <a href="{{ route('stories.index') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-8 rounded-full shadow transition">
                    استكشاف المزيد من القصص
                </a>
                <a href="{{ url('/') }}" class="bg-white border-2 border-indigo-600 text-indigo-600 hover:bg-indigo-50 font-bold py-3 px-8 rounded-full transition">
                    العودة للرئيسية
                </a>
            </div>

        </div>
    </div>
</x-front-layout>
