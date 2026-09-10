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
                              placeholder="{{ $integration->defaultPayload() }}">{{ old('payload_template', $setting->payload_template) }}</textarea>
                    <p class="mt-1 text-xs text-gray-500">
                        هذا هو جسم الطلب بالكامل — أي مفتاح يتوقعه RoboDesk (مثل <code dir="ltr">procedureId</code>
                        أو <code dir="ltr">attachments</code>) يُكتب هنا. اتركه فارغًا لإرسال كل المتغيرات كما هي.
                    </p>
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

        {{-- ── Live test ───────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-6"
                 data-robodesk-test
                 data-run-url="{{ route('admin.robodesk.settings.test', $integration->key) }}"
                 data-status-url="{{ url('/admin/robodesk/settings/'.$integration->key.'/test') }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-black text-gray-900">اختبار التكامل</h2>
                    <p class="mt-1 text-xs text-gray-600">
                        يُرسل القالب المحفوظ فعليًا إلى الرابط أعلاه ببيانات تجريبية، ثم ينتظر رد RoboDesk على الويبهوك.
                        لا يُنشئ طلبًا ولا يغيّر أي بيانات حقيقية.
                    </p>
                </div>
                <button type="button" data-test-run @disabled(! $setting->api_url)
                        class="rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-black text-white disabled:opacity-50">
                    تشغيل الاختبار
                </button>
            </div>

            @unless ($setting->api_url)
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">
                    احفظ رابط الـ API أولًا حتى تتمكن من تشغيل الاختبار.
                </p>
            @endunless

            <div class="mt-5 space-y-4" data-test-result hidden>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-black text-gray-800">١ — ما أرسلناه</span>
                        <span class="rounded-lg px-2 py-0.5 text-xs font-bold" data-test-sent-status></span>
                        <code class="text-xs text-gray-500" dir="ltr" data-test-reference></code>
                    </div>
                    <p class="mt-2 break-all text-xs text-gray-500" dir="ltr" data-test-url></p>
                    <p class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs font-bold text-red-700" data-test-error hidden></p>
                    <pre class="mt-2 max-h-56 overflow-auto rounded-lg bg-gray-50 p-3 text-xs" dir="ltr" data-test-body></pre>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <span class="text-xs font-black text-gray-800">٢ — رد RoboDesk على الطلب</span>
                    <pre class="mt-2 max-h-40 overflow-auto rounded-lg bg-gray-50 p-3 text-xs" dir="ltr" data-test-response></pre>
                </div>

                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-black text-gray-800">٣ — ما استقبلناه على الويبهوك</span>
                        <span class="rounded-lg bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800" data-test-waiting hidden>
                            بانتظار RoboDesk…
                        </span>
                    </div>
                    <p class="mt-2 text-xs text-gray-500" data-test-empty>
                        لم يصل شيء بعد. أكمل الإجراء في RoboDesk وأرسل الرد إلى الويبهوك بنفس المرجع أعلاه.
                    </p>
                    <div class="mt-2 space-y-2" data-test-received></div>
                </div>
            </div>
        </section>

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

                    @if ($attachment = $integration->inboundAttachment())
                        <div>
                            <span class="text-xs font-bold text-gray-500">{{ $attachment['title_ar'] }}</span>
                            <div class="mt-1 rounded-xl border border-gray-200 bg-white px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-md bg-gray-900 px-2 py-0.5 text-[10px] font-black text-white">POST</span>
                                    <code class="break-all text-xs text-gray-800" dir="ltr">{{ $attachment['url'] }}</code>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">{{ $attachment['note_ar'] }}</p>
                                <div class="mt-2 space-y-1">
                                    @foreach ($attachment['fields'] as $field => $description)
                                        <div class="flex flex-wrap items-center gap-2">
                                            <code class="text-xs font-bold text-sky-800" dir="ltr">{{ $field }}</code>
                                            <span class="text-xs text-gray-600">{{ $description }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif

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

@push('scripts')
<script>
    // Plain polling in the same vanilla style as resources/js/app.js — Alpine is
    // listed in package.json but never bundled, so x-data would be inert here.
    document.addEventListener('DOMContentLoaded', () => {
        const panel = document.querySelector('[data-robodesk-test]');

        if (!panel) {
            return;
        }

        const runButton = panel.querySelector('[data-test-run]');
        const result = panel.querySelector('[data-test-result]');
        const waiting = panel.querySelector('[data-test-waiting]');
        const empty = panel.querySelector('[data-test-empty]');
        const received = panel.querySelector('[data-test-received]');
        const pretty = (value) => JSON.stringify(value ?? null, null, 2);

        let timer = null;
        let attempts = 0;

        const stopPolling = () => {
            if (timer) {
                window.clearInterval(timer);
                timer = null;
            }
            waiting.hidden = true;
        };

        const render = (data) => {
            result.hidden = false;
            panel.querySelector('[data-test-reference]').textContent = data.reference ?? '';

            const sent = data.sent ?? {};
            const badge = panel.querySelector('[data-test-sent-status]');
            const ok = sent.status === 'succeeded';

            badge.textContent = sent.http_status ?? sent.status ?? '—';
            badge.className = 'rounded-lg px-2 py-0.5 text-xs font-bold '
                + (ok ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800');

            panel.querySelector('[data-test-url]').textContent = sent.url ?? '';
            panel.querySelector('[data-test-body]').textContent = pretty(sent.body);
            panel.querySelector('[data-test-response]').textContent = pretty(sent.response);

            const error = panel.querySelector('[data-test-error]');
            error.hidden = !sent.error;
            error.textContent = sent.error ?? '';

            received.textContent = '';
            empty.hidden = (data.received ?? []).length > 0;

            (data.received ?? []).forEach((item) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'rounded-lg border border-sky-200 bg-sky-50 p-3';

                const type = document.createElement('code');
                type.className = 'text-xs font-bold text-sky-800';
                type.setAttribute('dir', 'ltr');
                type.textContent = item.type;

                const body = document.createElement('pre');
                body.className = 'mt-1 max-h-40 overflow-auto rounded-lg bg-white p-2 text-xs';
                body.setAttribute('dir', 'ltr');
                body.textContent = pretty(item.body);

                wrapper.append(type, body);
                received.append(wrapper);
            });
        };

        const poll = async () => {
            attempts += 1;

            // ~5 minutes is long enough for someone to finish the procedure.
            if (attempts > 100) {
                stopPolling();
                return;
            }

            const reference = panel.querySelector('[data-test-reference]').textContent;
            const response = await fetch(`${panel.dataset.statusUrl}/${reference}`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                stopPolling();
                return;
            }

            const data = await response.json();
            render(data);

            if ((data.received ?? []).length > 0) {
                stopPolling();
            }
        };

        runButton?.addEventListener('click', async () => {
            runButton.disabled = true;
            runButton.textContent = 'جارٍ الإرسال…';
            stopPolling();

            try {
                const response = await fetch(panel.dataset.runUrl, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        Accept: 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                render(await response.json());

                attempts = 0;
                waiting.hidden = false;
                timer = window.setInterval(poll, 3000);
            } catch (error) {
                render({ reference: '—', sent: { status: 'failed', error: String(error) }, received: [] });
            } finally {
                runButton.disabled = false;
                runButton.textContent = 'تشغيل الاختبار';
            }
        });
    });
</script>
@endpush
