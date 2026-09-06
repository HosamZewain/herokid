<x-admin-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-black text-gray-900">محاكاة رحلة RoboDesk</h1>
            <p class="mt-1 text-sm text-gray-500">تتبّع رحلة العميل كاملة على واتساب دون الاتصال بـ RoboDesk فعليًا.</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @unless($simulating)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                وضع المحاكاة غير مفعّل. الرسائل ستُرسل فعليًا إلى RoboDesk.
                <a class="underline" href="{{ route('admin.robodesk.settings.index') }}">فعّله من إعدادات RoboDesk</a>.
            </div>
        @else
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                وضع المحاكاة مفعّل — لا شيء يغادر الخادم. كل رسالة تُسجَّل كما كانت سترسل تمامًا.
            </div>
        @endunless

        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">المحادثات النشطة</h2>
            <p class="mt-1 text-sm text-gray-500">عمليات شراء أرسل لها التكامل رسالة واحدة على الأقل.</p>

            @if ($checkouts->isEmpty())
                <p class="mt-5 rounded-xl border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm text-gray-500">
                    لا توجد رسائل بعد. فعّل الإجراءات المطلوبة ثم أنشئ طلبًا من المتجر.
                </p>
            @else
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-gray-500">
                                <th class="p-3 text-right">عملية الشراء</th>
                                <th class="p-3 text-right">عدد الرسائل</th>
                                <th class="p-3 text-right">آخر نشاط</th>
                                <th class="p-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($checkouts as $checkout)
                                <tr class="border-b">
                                    <td class="p-3 font-bold" dir="ltr">{{ $checkout->checkout_group_key }}</td>
                                    <td class="p-3">{{ $checkout->message_count }}</td>
                                    <td class="p-3 text-gray-500">{{ \Illuminate\Support\Carbon::parse($checkout->last_activity_at)->diffForHumans() }}</td>
                                    <td class="p-3 text-left">
                                        <a class="rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white" href="{{ route('admin.robodesk.simulator.show', $checkout->checkout_group_key) }}">فتح المحادثة</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">أحدث الطلبات</h2>
            <p class="mt-1 text-sm text-gray-500">افتح أي عملية شراء حتى لو لم تُرسل لها رسائل بعد.</p>

            <div class="mt-5 flex flex-wrap gap-2">
                @foreach ($recentOrders as $order)
                    <a class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-50"
                       href="{{ route('admin.robodesk.simulator.show', $order->checkoutGroupKey()) }}">
                        <span dir="ltr">{{ $order->order_number }}</span>
                        <span class="ms-2 text-xs text-gray-400">{{ $order->status }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</x-admin-layout>
