<x-front-layout>
    <x-slot name="pageTitle">متابعة الطلب {{ $group['short_reference'] }}</x-slot>
    <x-slot name="robots">noindex, nofollow</x-slot>

    <main class="min-h-[70vh] bg-slate-50 py-8 sm:py-12">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-black text-emerald-800" role="status">{{ session('success') }}</div>
            @endif
            @if($errors->any())
                <div class="rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-black text-red-700" role="alert">{{ $errors->first() }}</div>
            @endif

            <section class="overflow-hidden rounded-3xl bg-gradient-to-l from-indigo-700 to-violet-600 p-6 text-white shadow-xl shadow-indigo-100 sm:p-8">
                <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-sm font-bold text-indigo-200">رقم الطلب</p>
                        <h1 class="mt-1 font-mono text-3xl font-black" dir="ltr">{{ $group['short_reference'] }}</h1>
                        <p class="mt-3 text-sm font-bold text-indigo-100">تم الإنشاء {{ app_datetime($group['created_at'], 'd/m/Y h:i A') }}</p>
                    </div>
                    <span class="w-fit rounded-full bg-white px-4 py-2 text-sm font-black text-indigo-700">{{ $group['status_label'] }}</span>
                </div>
            </section>

            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach([
                    ['حالة الطلب', $group['status_label'], 'text-indigo-700'],
                    ['حالة الدفع', $group['payment_status_label'], 'text-amber-700'],
                    ['حالة الطباعة', $group['printing_status_label'], 'text-violet-700'],
                    ['حالة الشحن', $group['shipping_status_label'], 'text-emerald-700'],
                ] as [$label, $value, $color])
                    <article class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                        <p class="text-xs font-bold text-slate-500">{{ $label }}</p>
                        <p class="mt-2 text-sm font-black {{ $color }}">{{ $value }}</p>
                    </article>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm sm:p-7">
                <div class="flex items-center justify-between gap-4">
                    <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">{{ $group['story_count'] + $group['product_quantity'] + $group['add_on_quantity'] }} عنصر</span>
                    <h2 class="text-xl font-black text-slate-950">محتويات الطلب</h2>
                </div>
                <div class="mt-5 grid gap-3 md:grid-cols-2">
                    @foreach($group['active_orders'] as $order)
                        @foreach($order->items as $item)
                            <article class="rounded-2xl border border-slate-100 bg-slate-50 p-4 text-right">
                                <p class="font-black text-slate-900">{{ $item->title }}</p>
                                <p class="mt-1 text-xs font-bold text-slate-500">الكمية: {{ $item->quantity }}</p>
                                @if($order->child_name)<p class="mt-2 text-sm font-bold text-indigo-700">الطفل: {{ $order->child_name }}</p>@endif
                            </article>
                        @endforeach
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm sm:p-7">
                <h2 class="text-xl font-black text-slate-950">بيانات الاستلام</h2>
                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="font-bold text-slate-500">اسم ولي الأمر</dt><dd class="mt-1 font-black text-slate-900">{{ $group['customer_name'] }}</dd></div>
                    <div><dt class="font-bold text-slate-500">رقم الموبايل</dt><dd class="mt-1 font-black text-slate-900" dir="ltr">{{ $group['phone'] }}</dd></div>
                    <div><dt class="font-bold text-slate-500">المحافظة والمدينة</dt><dd class="mt-1 font-black text-slate-900">{{ data_get($group['delivery'], 'governorate') }} — {{ data_get($group['delivery'], 'city') }}</dd></div>
                    <div><dt class="font-bold text-slate-500">العنوان</dt><dd class="mt-1 font-black text-slate-900">{{ data_get($group['delivery'], 'address') }}</dd></div>
                </dl>
            </section>

            <section class="rounded-3xl border border-slate-100 bg-white p-5 shadow-sm sm:p-7">
                <h2 class="text-xl font-black text-slate-950">آخر تحديثات الطلب</h2>
                <div class="mt-5 space-y-3">
                    @foreach($group['active_orders']->flatMap->statusLogs->sortByDesc('created_at')->take(12) as $log)
                        <div class="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 px-4 py-3">
                            <time class="shrink-0 text-xs font-bold text-slate-400">{{ app_datetime_human($log->created_at) }}</time>
                            <p class="text-sm font-black text-slate-800">{{ \App\Support\OrderStatusRegistry::label($log->status_type ?: 'order', $log->status) }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5 sm:p-7">
                <div class="flex flex-col gap-4 sm:flex-row-reverse sm:items-center sm:justify-between">
                    <div class="text-right">
                        <h2 class="text-lg font-black text-indigo-950">إدارة الطلب</h2>
                        <p class="mt-1 text-sm font-bold leading-6 text-indigo-700">{{ $group['can_edit'] ? 'يمكنك تعديل البيانات أو إلغاء الطلب قبل بدء التنفيذ.' : ($group['can_update_parent_notes'] ? 'بدأ تنفيذ الطلب؛ ما زال بإمكانك تحديث ملاحظات ولي الأمر.' : 'اكتمل الطلب، وتبقى تفاصيله متاحة للمتابعة هنا.') }}</p>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        @if($group['can_edit'] || $group['can_update_parent_notes'])<a href="{{ route('track.edit', $group['short_reference']) }}" class="inline-flex min-h-12 items-center justify-center rounded-2xl bg-indigo-600 px-6 py-3 text-sm font-black text-white">{{ $group['can_edit'] ? 'تعديل الطلب' : 'تحديث ملاحظات ولي الأمر' }}</a>@endif
                        @if($group['can_cancel'])
                            <form method="POST" action="{{ route('track.cancel', $group['short_reference']) }}" onsubmit="return confirm('هل أنت متأكد من إلغاء الطلب بالكامل؟')">
                                @csrf
                                <input type="hidden" name="confirm_cancel" value="1">
                                <button class="min-h-12 w-full rounded-2xl border border-red-200 bg-white px-6 py-3 text-sm font-black text-red-700">إلغاء الطلب</button>
                            </form>
                        @endif
                        <a href="{{ route('track.index') }}" class="inline-flex min-h-12 items-center justify-center rounded-2xl border border-indigo-200 bg-white px-6 py-3 text-sm font-black text-indigo-700">طلب آخر</a>
                    </div>
                </div>
            </section>
        </div>
    </main>
</x-front-layout>
