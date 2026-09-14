<x-admin-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-black text-gray-900">تكاملات RoboDesk</h1>
            <p class="mt-1 text-sm text-gray-500">تفعيل التكامل، التحكم في مسار الطلب، وضبط كل تكامل على حدة.</p>
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

        <form method="POST" action="{{ route('admin.robodesk.settings.general') }}" class="space-y-6">
            @csrf

            {{-- ── 1. Enable / disable ─────────────────────────────────── --}}
            <section class="rounded-2xl border border-gray-200 bg-white p-6">
                <h2 class="text-lg font-black text-gray-900">حالة التكامل</h2>

                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-4 {{ $general['enabled'] ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200 bg-gray-50' }}">
                        <input type="checkbox" name="enabled" value="1" @checked($general['enabled']) class="h-5 w-5 rounded border-gray-300">
                        <span>
                            <span class="block text-sm font-black text-gray-900">تفعيل RoboDesk</span>
                            <span class="block text-xs text-gray-500">عند الإيقاف لا يُرسل ولا يُستقبل أي شيء.</span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-4 {{ $general['simulation_mode'] ? 'border-violet-300 bg-violet-50' : 'border-gray-200 bg-gray-50' }}">
                        <input type="checkbox" name="simulation_mode" value="1" @checked($general['simulation_mode']) class="h-5 w-5 rounded border-gray-300">
                        <span>
                            <span class="block text-sm font-black text-gray-900">وضع المحاكاة</span>
                            <span class="block text-xs text-gray-500">تُسجَّل الرسائل بدل إرسالها — <a class="underline" href="{{ route('admin.robodesk.simulator.index') }}">شاشة المحاكاة</a>.</span>
                        </span>
                    </label>
                </div>

            </section>

            {{-- ── 2. Flow control ─────────────────────────────────────── --}}
            <section class="rounded-2xl border border-gray-200 bg-white p-6">
                <h2 class="text-lg font-black text-gray-900">التحكم في مسار الطلب</h2>
                <p class="mt-1 text-sm text-gray-500">قواعد عمل تؤثر على حالات الطلب، وليست إعدادات اتصال.</p>

                <div class="mt-4 space-y-3">
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4">
                        <input type="checkbox" name="gate_order_confirmation" value="1" @checked($general['gate_order_confirmation']) class="mt-0.5 h-5 w-5 rounded border-gray-300">
                        <span>
                            <span class="block text-sm font-black text-gray-800">إيقاف الإنتاج حتى يؤكد العميل الطلب</span>
                            <span class="block text-xs text-gray-500">يبدأ الطلب بحالة «بانتظار تأكيد العميل» ولا يلتقطه الـ Agent قبل التأكيد.</span>
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4">
                        <input type="checkbox" name="gate_identity_confirmation" value="1" @checked($general['gate_identity_confirmation']) class="mt-0.5 h-5 w-5 rounded border-gray-300">
                        <span>
                            <span class="block text-sm font-black text-gray-800">إيقاف الاعتماد التلقائي لهوية الطفل</span>
                            <span class="block text-xs text-gray-500">تبقى الهوية بانتظار موافقة العميل بدل اعتمادها من أول محاولة ناجحة.</span>
                        </span>
                    </label>
                </div>
            </section>

            <button class="rounded-xl bg-gray-900 px-6 py-3 text-sm font-black text-white">حفظ</button>
        </form>

        {{-- ── 3. Integrations ─────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">التكاملات</h2>
            <p class="mt-1 text-sm text-gray-500">كل تكامل يُضبط برابط API وتوكن وقالب JSON.</p>

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
    </div>
</x-admin-layout>
