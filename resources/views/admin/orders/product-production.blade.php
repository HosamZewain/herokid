<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-right">
                <p class="text-xs font-black text-fuchsia-600">إنتاج منتج مخصص</p>
                <h2 class="mt-1 text-xl font-black text-gray-900">إنتاج {{ $item->title }}</h2>
            </div>
            <p class="font-mono text-sm font-black text-gray-500" dir="ltr">#{{ $order->order_number }}</p>
        </div>
    </x-slot>

    @php
        $delivery = $order->delivery_details ?? [];
        $displayValues = $item->personalizationDisplayValues();
    @endphp

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('admin.orders.groups.show', $order) }}" class="rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-black text-gray-700 hover:bg-gray-50">
                    العودة إلى عملية الشراء
                </a>
                <div class="flex flex-wrap gap-2">
                    @can('orders.update')
                        <a href="{{ route('admin.orders.groups.edit', $order) }}" class="rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-black text-white hover:bg-violet-700">تعديل بيانات الطلب</a>
                    @endcan
                    @if($item->product)
                        @can('store.products.update')
                            <a href="{{ route('admin.products.edit', $item->product) }}" class="rounded-xl border border-fuchsia-200 bg-fuchsia-50 px-4 py-2.5 text-sm font-black text-fuchsia-700 hover:bg-fuchsia-100">تعديل قالب البرومبت</a>
                        @endcan
                    @endif
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="rounded-3xl border border-emerald-100 bg-white p-5 shadow-sm lg:col-span-2 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-emerald-100 pb-4">
                        <div>
                            <p class="text-xs font-black text-emerald-600">بيانات إنتاج الاستيكر</p>
                            <h3 class="mt-1 text-xl font-black text-gray-900">{{ $item->title }}</h3>
                        </div>
                        <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-black text-emerald-700">{{ $item->quantity }} قطعة</span>
                    </div>

                    <dl class="mt-5 grid gap-4 sm:grid-cols-2">
                        @forelse($displayValues as $value)
                            <div class="rounded-2xl bg-emerald-50/70 p-4">
                                <dt class="text-xs font-black text-emerald-700">{{ $value['label'] }}</dt>
                                <dd class="mt-1 break-words text-base font-black text-gray-900">{{ $value['value'] }}</dd>
                            </div>
                        @empty
                            <div class="rounded-2xl border border-dashed border-amber-200 bg-amber-50 p-4 text-sm font-bold text-amber-800 sm:col-span-2">
                                لا توجد بيانات تخصيص محفوظة لهذا المنتج. استخدم زر «تعديل بيانات الطلب» لإضافتها قبل الإنتاج.
                            </div>
                        @endforelse
                    </dl>
                </section>

                <aside class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5 sm:p-6">
                    <h3 class="text-lg font-black text-indigo-950">بيانات العميل</h3>
                    <dl class="mt-4 space-y-4 text-sm">
                        <div><dt class="text-xs font-bold text-indigo-500">ولي الأمر</dt><dd class="mt-1 font-black text-gray-900">{{ $order->parent_name ?: $order->user?->name ?: '—' }}</dd></div>
                        <div><dt class="text-xs font-bold text-indigo-500">الهاتف</dt><dd class="mt-1 font-black text-gray-900" dir="ltr">{{ data_get($delivery, 'phone', '—') }}</dd></div>
                        <div><dt class="text-xs font-bold text-indigo-500">رقم الطلب</dt><dd class="mt-1 font-mono font-black text-gray-900" dir="ltr">{{ $order->order_number }}</dd></div>
                        <div><dt class="text-xs font-bold text-indigo-500">تاريخ الطلب</dt><dd class="mt-1 font-black text-gray-900">{{ $order->created_at?->format('d/m/Y h:i A') }}</dd></div>
                    </dl>
                </aside>
            </div>

            <section class="rounded-3xl border border-sky-100 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-xl font-black text-gray-900">صور الطفل</h3>
                    <span class="rounded-full bg-sky-50 px-3 py-1.5 text-xs font-black text-sky-700">{{ count($photos) }} صورة</span>
                </div>

                @if($photos !== [])
                    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5">
                        @foreach($photos as $photo)
                            <a href="{{ route('admin.orders.photo', [$order, $loop->index]) }}" target="_blank" rel="noopener" class="group overflow-hidden rounded-2xl border border-sky-100 bg-sky-50 p-2 focus:outline-none focus:ring-2 focus:ring-sky-500">
                                <img src="{{ route('admin.orders.photo', [$order, $loop->index]) }}" alt="صورة الطفل {{ $loop->iteration }}" class="aspect-square w-full rounded-xl object-cover transition group-hover:scale-[1.02]" loading="lazy">
                                <span class="mt-2 block text-center text-xs font-black text-sky-700">فتح الصورة {{ $loop->iteration }}</span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="mt-5 rounded-2xl border border-dashed border-red-200 bg-red-50 p-5 text-sm font-bold text-red-700">لا توجد صور طفل مرفقة. أضف الصور من تعديل بيانات الطلب قبل بدء الإنتاج.</p>
                @endif
            </section>

            @include('admin.orders._product-production-prompts', [
                'productProductionPrompts' => collect([$productPrompt]),
            ])
        </div>
    </div>
</x-admin-layout>
