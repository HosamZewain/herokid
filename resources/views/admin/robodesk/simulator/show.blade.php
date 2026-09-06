<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-gray-900">محاكاة المحادثة</h1>
                <p class="mt-1 text-sm text-gray-500" dir="ltr">{{ $checkoutReference }}</p>
            </div>
            <a class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-bold text-gray-600" href="{{ route('admin.robodesk.simulator.index') }}">رجوع</a>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">
                <ul class="list-disc space-y-1 pr-5">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        @unless($simulating)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                وضع المحاكاة غير مفعّل — الرسائل الصادرة ستُرسل فعليًا.
            </div>
        @endunless

        {{-- ── Current state ──────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">حالة الطلبات الآن</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-gray-500">
                            <th class="p-3 text-right">الطلب</th>
                            <th class="p-3 text-right">حالة الطلب</th>
                            <th class="p-3 text-right">الدفع</th>
                            <th class="p-3 text-right">الشحن</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($orders as $order)
                            <tr class="border-b">
                                <td class="p-3 font-bold" dir="ltr">{{ $order->order_number }}</td>
                                <td class="p-3">
                                    <span class="rounded-lg px-3 py-1 text-xs font-black {{ \App\Support\OrderStatusRegistry::color('order', $order->status) }}">
                                        {{ $statusLabels[$order->status] ?? $order->status }}
                                    </span>
                                    <span class="ms-2 text-xs text-gray-400" dir="ltr">{{ $order->status }}</span>
                                </td>
                                <td class="p-3 text-gray-600">{{ $order->payment_status }}</td>
                                <td class="p-3 text-gray-600">{{ $order->shipping_status ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ── Conversation ───────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">المحادثة</h2>
            <p class="mt-1 text-sm text-gray-500">الرسائل الصادرة على اليمين، وردود العميل على اليسار.</p>

            <div class="mt-5 space-y-4">
                @forelse ($events as $event)
                    @php($outbound = $event->direction === 'outbound')
                    <div class="flex {{ $outbound ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-2xl rounded-2xl border p-4 {{ $outbound ? 'border-emerald-200 bg-emerald-50' : 'border-sky-200 bg-sky-50' }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs font-black {{ $outbound ? 'text-emerald-800' : 'text-sky-800' }}">
                                    {{ $outbound ? 'HeroKid ← RoboDesk' : 'العميل → HeroKid' }}
                                </span>
                                <span class="rounded-lg bg-white px-2 py-0.5 text-xs font-bold text-gray-600" dir="ltr">{{ $event->event_type }}</span>
                                <span class="rounded-lg px-2 py-0.5 text-xs font-bold {{ $event->status === 'succeeded' ? 'bg-emerald-100 text-emerald-800' : ($event->status === 'held' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-600') }}">{{ $event->status }}</span>
                                @if (data_get($event->response_payload, 'simulated') || data_get($event->payload, 'simulated'))
                                    <span class="rounded-lg bg-violet-100 px-2 py-0.5 text-xs font-bold text-violet-800">محاكاة</span>
                                @endif
                                <span class="text-xs text-gray-400">{{ $event->created_at?->format('d/m H:i') }}</span>
                            </div>

                            @if ($event->last_error)
                                <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs font-bold text-red-700">{{ $event->last_error }}</p>
                            @endif

                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs font-bold text-gray-500">عرض البيانات المرسلة</summary>
                                <pre class="mt-2 max-h-80 overflow-auto rounded-xl bg-white p-3 text-xs" dir="ltr">{{ json_encode(data_get($event->response_payload, 'would_have_sent.body') ?? $event->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                @if ($url = data_get($event->response_payload, 'would_have_sent.url'))
                                    <p class="mt-2 text-xs text-gray-500" dir="ltr">
                                        {{ data_get($event->response_payload, 'would_have_sent.method') }} {{ $url }}
                                    </p>
                                @endif
                            </details>
                        </div>
                    </div>
                @empty
                    <p class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-6 text-center text-sm text-gray-500">
                        لا توجد رسائل بعد لعملية الشراء هذه.
                    </p>
                @endforelse
            </div>
        </section>

        {{-- ── Customer replies ───────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">محاكاة رد العميل</h2>
            <p class="mt-1 text-sm text-gray-500">تستدعي نفس المعالج الذي يستخدمه الويبهوك الحقيقي، بلا توقيع ولا شبكة.</p>

            <div class="mt-5 grid gap-3 md:grid-cols-2">
                @foreach ($replies as $type => $reply)
                    <form method="POST" action="{{ route('admin.robodesk.simulator.reply', $checkoutReference) }}" class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                        @csrf
                        <input type="hidden" name="type" value="{{ $type }}">

                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-black text-gray-800">{{ $reply['label'] }}</span>
                            <span class="text-xs text-gray-400" dir="ltr">{{ $type }}</span>
                        </div>

                        @if ($orders->count() > 1)
                            <select name="order_id" class="mt-3 w-full rounded-xl border-gray-200 text-sm">
                                @foreach ($orders as $order)
                                    <option value="{{ $order->id }}">{{ $order->order_number }}</option>
                                @endforeach
                            </select>
                        @endif

                        @if (! empty($reply['score']))
                            <input type="number" name="score" min="0" max="10" value="5" class="mt-3 w-full rounded-xl border-gray-200 text-sm" placeholder="التقييم">
                        @endif

                        @if (! empty($reply['comment']))
                            <textarea name="comment" rows="2" class="mt-3 w-full rounded-xl border-gray-200 text-sm"
                                      placeholder="{{ ! empty($reply['requires_comment']) ? 'ملاحظات العميل (مطلوبة)' : 'ملاحظات العميل (اختياري)' }}"></textarea>
                        @endif

                        <button class="mt-3 w-full rounded-xl px-4 py-2.5 text-sm font-black text-white {{ $reply['tone'] === 'negative' ? 'bg-red-600' : ($reply['tone'] === 'warning' ? 'bg-amber-600' : 'bg-emerald-600') }}">
                            إرسال
                        </button>
                    </form>
                @endforeach
            </div>
        </section>

        @if ($proofs->isNotEmpty())
            <section class="rounded-2xl border border-gray-200 bg-white p-6">
                <h2 class="text-lg font-black text-gray-900">إثباتات الدفع المستلمة</h2>
                <div class="mt-4 space-y-2">
                    @foreach ($proofs as $proof)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm">
                            <span class="font-bold" dir="ltr">{{ $proof->original_filename }}</span>
                            <span class="rounded-lg bg-white px-2 py-1 text-xs font-bold text-gray-600">{{ $proof->status }}</span>
                            <span class="text-xs text-gray-400">{{ $proof->created_at?->format('d/m H:i') }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">تُراجع الإثباتات يدويًا من صفحة تكامل RoboDesk — لا يتغير حالة الدفع تلقائيًا.</p>
            </section>
        @endif
    </div>
</x-admin-layout>
