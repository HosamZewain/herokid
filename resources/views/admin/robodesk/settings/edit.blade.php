<x-admin-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-black text-gray-900">{{ $integration->nameAr() }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $integration->descriptionAr() }}</p>
            </div>
            <a class="rounded-xl border border-gray-200 px-4 py-2 text-sm font-bold text-gray-600" href="{{ route('admin.robodesk.settings.index') }}">رجوع</a>
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

        <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
            <span class="font-black">المُشغّل:</span> {{ $integration->triggerAr() }}
        </div>

        <form method="POST" action="{{ route('admin.robodesk.settings.update', $integration->key) }}" class="space-y-6">
            @csrf

            <section class="rounded-2xl border border-gray-200 bg-white p-6 space-y-5">
                <label class="flex items-center gap-3">
                    <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $setting->is_enabled)) class="h-5 w-5 rounded border-gray-300">
                    <span class="text-sm font-black text-gray-800">تفعيل هذا التكامل</span>
                </label>

                <div>
                    <label class="text-sm font-bold text-gray-700">رابط الـ API</label>
                    <input dir="ltr" name="api_url" value="{{ old('api_url', $setting->api_url) }}"
                           placeholder="https://herokid.robodesk.ai/conversation/start/sendMsg"
                           class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    <p class="mt-1 text-xs text-gray-500">الرابط الكامل الذي يُرسل إليه الطلب بطريقة POST.</p>
                </div>

                <div>
                    <label class="text-sm font-bold text-gray-700">
                        التوكن
                        @if ($masked = $integration->maskedToken())
                            <span class="text-xs font-bold text-emerald-600" dir="ltr">({{ $masked }})</span>
                        @endif
                    </label>
                    <input dir="ltr" type="password" name="token" autocomplete="new-password"
                           placeholder="{{ $integration->maskedToken() ? 'اتركه فارغًا للإبقاء على الحالي' : 'التوكن الثابت' }}"
                           class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    <p class="mt-1 text-xs text-gray-500">يُرسل كما هو في ترويسة <code dir="ltr">Authorization</code> ويُخزَّن مشفرًا.</p>
                </div>

                <div>
                    <label class="text-sm font-bold text-gray-700">قالب البيانات (JSON)</label>
                    <textarea dir="ltr" name="payload_template" rows="14"
                              class="mt-1 w-full rounded-xl border-gray-200 font-mono text-xs"
                              placeholder='{&#10;  "to": "@{{ customer_phone }}",&#10;  "templateName": "herokid_order_confirm",&#10;  "data": ["@{{ customer_name }}", "@{{ total }}"]&#10;}'>{{ old('payload_template', $setting->payload_template) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">هذا هو جسم الطلب بالكامل. اتركه فارغًا لإرسال كل المتغيرات كما هي.</p>
                    <p class="mt-1 text-xs text-amber-700">
                        يجب أن يكون JSON صالحًا، لذلك ضع المتغيّر بين علامتي تنصيص دائمًا — حتى للأرقام:
                        <code dir="ltr">"total": "@{{ total }}"</code> تُرسل رقمًا وليس نصًا.
                    </p>
                </div>
            </section>

            <section class="rounded-2xl border border-gray-200 bg-white p-6">
                <h2 class="text-sm font-black text-gray-900">المتغيرات المتاحة</h2>
                <p class="mt-1 text-xs text-gray-500">استخدمها داخل القالب بالشكل <code dir="ltr">@{{ variable }}</code>.</p>

                <div class="mt-4 grid gap-2 md:grid-cols-2">
                    @foreach ($integration->variables() as $name => $description)
                        <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                            <code class="text-xs font-bold text-indigo-700" dir="ltr">{{ $integration->placeholder($name) }}</code>
                            <span class="text-xs text-gray-500">{{ $description }}</span>
                        </div>
                    @endforeach
                </div>
            </section>

            <button class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white">حفظ</button>
        </form>

        {{-- ── Inbound: what RoboDesk calls back ───────────────────────── --}}
        @if ($integration->inboundEvents())
            <section class="rounded-2xl border border-sky-200 bg-sky-50/40 p-6">
                <h2 class="text-sm font-black text-gray-900">الويبهوك — رد RoboDesk علينا</h2>
                <p class="mt-1 text-xs text-gray-600">اضبط هذا الرابط في RoboDesk ليُرسل نتيجة هذا التكامل.</p>

                <div class="mt-4 space-y-3">
                    <div>
                        <span class="text-xs font-bold text-gray-500">الرابط</span>
                        <div class="mt-1 flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2">
                            <span class="rounded-md bg-gray-900 px-2 py-0.5 text-[10px] font-black text-white">POST</span>
                            <code class="break-all text-xs text-gray-800" dir="ltr">{{ $integration->webhookUrl() }}</code>
                        </div>
                    </div>

                    <div>
                        <span class="text-xs font-bold text-gray-500">التوثيق</span>
                        <div class="mt-1 rounded-xl border border-gray-200 bg-white px-3 py-2">
                            <code class="text-xs text-gray-800" dir="ltr">Authorization: &lt;نفس التوكن أعلاه&gt;</code>
                            <p class="mt-1 text-xs text-gray-500">أو الترويسة <code dir="ltr">X-RoboDesk-Token</code>. نفس توكن هذا التكامل يعمل في الاتجاهين.</p>
                        </div>
                    </div>

                    <div>
                        <span class="text-xs font-bold text-gray-500">الأحداث المقبولة</span>
                        <div class="mt-1 space-y-2">
                            @foreach ($integration->inboundEvents() as $event => $description)
                                <div class="flex flex-wrap items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2">
                                    <code class="text-xs font-bold text-sky-800" dir="ltr">{{ $event }}</code>
                                    <span class="text-xs text-gray-600">{{ $description }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <span class="text-xs font-bold text-gray-500">مثال على جسم الطلب</span>
                        <pre class="mt-1 overflow-x-auto rounded-xl border border-gray-200 bg-white p-3 font-mono text-xs" dir="ltr">{{ $integration->inboundExample() }}</pre>
                        <p class="mt-1 text-xs text-gray-500">
                            <code dir="ltr">checkout_reference</code> هو المتغيّر <code dir="ltr">{{ $integration->placeholder('checkout_reference') }}</code> الذي أرسلناه في القالب أعلاه.
                        </p>
                    </div>
                </div>
            </section>
        @endif
    </div>
</x-admin-layout>
