<x-admin-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-black text-gray-900">تكاملات RoboDesk</h1>
            <p class="mt-1 text-sm text-gray-500">كل تكامل يُضبط برابط API وتوكن وقالب JSON.</p>
        </div>
    </x-slot>

    <div class="space-y-6">
        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">
                <ul class="list-disc space-y-1 pr-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">التكاملات</h2>

            <div class="mt-5 space-y-3">
                @foreach ($integrations as $integration)
                    <a href="{{ route('admin.robodesk.settings.edit', $integration->key) }}"
                       class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 p-4 hover:bg-gray-50">
                        <span>
                            <span class="block text-sm font-black text-gray-900">{{ $integration->nameAr() }}</span>
                            <span class="block text-xs text-gray-500">{{ $integration->triggerAr() }}</span>
                        </span>
                        <span class="flex items-center gap-2">
                            @if ($integration->enabled())
                                <span class="rounded-lg bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-800">مفعّل</span>
                            @elseif ($integration->configured())
                                <span class="rounded-lg bg-amber-100 px-3 py-1 text-xs font-black text-amber-800">مضبوط — غير مفعّل</span>
                            @else
                                <span class="rounded-lg bg-gray-100 px-3 py-1 text-xs font-black text-gray-600">غير مضبوط</span>
                            @endif
                            <span class="text-xs font-bold text-indigo-600">ضبط ←</span>
                        </span>
                    </a>
                @endforeach
            </div>

            <p class="mt-4 text-xs text-gray-400">تكاملات أخرى (اعتماد الهوية، اعتماد المنتج، التقييم) تُضاف لاحقًا بنفس الشكل.</p>
        </section>

        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">إعدادات عامة</h2>

            <form method="POST" action="{{ route('admin.robodesk.settings.general') }}" class="mt-5 space-y-4">
                @csrf

                <label class="flex items-center gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <input type="checkbox" name="enabled" value="1" @checked($general['enabled']) class="h-5 w-5 rounded border-gray-300">
                    <span class="text-sm font-black text-gray-800">تفعيل تكامل RoboDesk</span>
                </label>

                <label class="flex items-center gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4">
                    <input type="checkbox" name="simulation_mode" value="1" @checked($general['simulation_mode']) class="h-5 w-5 rounded border-gray-300">
                    <span>
                        <span class="block text-sm font-black text-violet-900">وضع المحاكاة</span>
                        <span class="block text-xs text-violet-700">لا تُرسل أي رسالة فعليًا؛ تُسجَّل كما كانت سترسل وتظهر في
                            <a class="underline" href="{{ route('admin.robodesk.simulator.index') }}">شاشة المحاكاة</a>.</span>
                    </span>
                </label>

                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                    <p class="text-sm font-black text-gray-800">مسار رحلة الطلب</p>
                    <label class="mt-3 flex items-center gap-3">
                        <input type="checkbox" name="gate_order_confirmation" value="1" @checked($general['gate_order_confirmation']) class="h-5 w-5 rounded border-gray-300">
                        <span class="text-sm text-gray-700">إيقاف الإنتاج حتى يؤكد العميل الطلب (يبدأ الطلب بحالة «بانتظار تأكيد العميل»)</span>
                    </label>
                    <label class="mt-2 flex items-center gap-3">
                        <input type="checkbox" name="gate_identity_confirmation" value="1" @checked($general['gate_identity_confirmation']) class="h-5 w-5 rounded border-gray-300">
                        <span class="text-sm text-gray-700">إيقاف الاعتماد التلقائي لهوية الطفل حتى يوافق العميل</span>
                    </label>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="text-xs font-bold text-gray-500">ترويسة التوكن الوارد</label>
                        <input dir="ltr" name="inbound_auth_header" value="{{ old('inbound_auth_header', $general['inbound_auth_header']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">التوكن الوارد {!! $inboundToken ? '<span class="text-emerald-600">('.e($inboundToken).')</span>' : '' !!}</label>
                        <input dir="ltr" type="password" name="inbound_token" autocomplete="new-password" placeholder="اتركه فارغًا للإبقاء على الحالي" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">رقم واتساب الشركة</label>
                        <input dir="ltr" name="whatsapp_number" value="{{ old('whatsapp_number', $general['whatsapp_number']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-gray-500">رابط انستاباي</label>
                        <input dir="ltr" name="instapay_url" value="{{ old('instapay_url', $general['instapay_url']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    </div>
                </div>

                <button class="rounded-xl bg-gray-900 px-6 py-3 text-sm font-black text-white">حفظ الإعدادات العامة</button>
            </form>
        </section>
    </div>
</x-admin-layout>
