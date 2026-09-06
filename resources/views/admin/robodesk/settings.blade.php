<x-admin-layout>
    <x-slot name="header">
        <div>
            <h1 class="text-2xl font-black text-gray-900">إعدادات تكامل RoboDesk</h1>
            <p class="mt-1 text-sm text-gray-500">اضبط الاتصال والمفاتيح السرية وكل إجراء على حدة قبل تفعيل التكامل.</p>
        </div>
    </x-slot>

    <div class="space-y-6">

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">
                <ul class="list-disc space-y-1 pr-5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($warnings as $warning)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-800">
                {{ $warning }}
            </div>
        @endforeach

        {{-- ── Connection ─────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-black text-gray-900">الاتصال بـ RoboDesk</h2>
                    <p class="mt-1 text-sm text-gray-500">القيم المشتركة بين كل الإجراءات. تُستخدم ما لم يحددها الإجراء بنفسه.</p>
                </div>
                <span class="rounded-lg px-3 py-1 text-xs font-black {{ $connection['enabled'] ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                    {{ $connection['enabled'] ? 'مفعّل' : 'غير مفعّل' }}
                </span>
            </div>

            <form method="POST" action="{{ route('admin.robodesk.settings.connection') }}" class="mt-5 grid gap-4 md:grid-cols-2">
                @csrf

                <label class="flex items-center gap-3 md:col-span-2">
                    <input type="checkbox" name="enabled" value="1" @checked($connection['enabled']) class="h-5 w-5 rounded border-gray-300">
                    <span class="text-sm font-bold text-gray-800">تفعيل التكامل مع RoboDesk</span>
                </label>

                <div>
                    <label class="text-xs font-bold text-gray-500">الرابط الأساسي</label>
                    <input dir="ltr" name="base_url" value="{{ old('base_url', $connection['base_url']) }}" placeholder="https://herokid.robodesk.ai" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">المسار الافتراضي للأحداث</label>
                    <input dir="ltr" name="events_path" value="{{ old('events_path', $connection['events_path']) }}" placeholder="/api/integrations/herokid/v1/events" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                    <p class="mt-1 text-xs text-gray-400">يمكن لكل إجراء تجاوزه بمسار خاص به.</p>
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">اسم ترويسة المصادقة</label>
                    <input dir="ltr" name="auth_header" value="{{ old('auth_header', $connection['auth_header']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">نوع المصادقة</label>
                    <input dir="ltr" name="auth_scheme" value="{{ old('auth_scheme', $connection['auth_scheme']) }}" placeholder="Bearer" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">القناة الافتراضية</label>
                    <input dir="ltr" name="default_channel" value="{{ old('default_channel', $connection['default_channel']) }}" placeholder="whatsapp-meta-herokid" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">اللغة الافتراضية</label>
                    <input dir="ltr" name="default_language" value="{{ old('default_language', $connection['default_language']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">مهلة الطلب (ثانية)</label>
                    <input type="number" min="5" max="120" name="timeout_seconds" value="{{ old('timeout_seconds', $connection['timeout_seconds']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">سماحية التوقيع (ثانية)</label>
                    <input type="number" min="30" max="3600" name="signature_tolerance_seconds" value="{{ old('signature_tolerance_seconds', $connection['signature_tolerance_seconds']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">رقم واتساب الشركة</label>
                    <input dir="ltr" name="whatsapp_number" value="{{ old('whatsapp_number', $connection['whatsapp_number']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">رابط انستاباي</label>
                    <input dir="ltr" name="instapay_url" value="{{ old('instapay_url', $connection['instapay_url']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <div>
                    <label class="text-xs font-bold text-gray-500">أقصى حجم لإثبات الدفع (ميجابايت)</label>
                    <input type="number" min="1" max="50" name="payment_proof_max_mb" value="{{ old('payment_proof_max_mb', $connection['payment_proof_max_mb']) }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                </div>

                <label class="flex items-center gap-3 md:col-span-2">
                    <input type="checkbox" name="sign_outbound" value="1" @checked($connection['sign_outbound']) class="h-5 w-5 rounded border-gray-300">
                    <span class="text-sm font-bold text-gray-800">توقيع الأحداث الصادرة بـ HMAC</span>
                </label>

                <div class="md:col-span-2">
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white">حفظ إعدادات الاتصال</button>
                </div>
            </form>
        </section>

        {{-- ── Credentials ────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">المفاتيح السرية</h2>
            <p class="mt-1 text-sm text-gray-500">تُحفظ مشفّرة في قاعدة البيانات ولا تُعرض مرة أخرى.</p>

            <div class="mt-5 space-y-4">
                @foreach ($credentials as $type => $credential)
                    <form method="POST" action="{{ route('admin.robodesk.settings.credentials') }}" class="flex flex-wrap items-end gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4">
                        @csrf
                        <input type="hidden" name="credential_type" value="{{ $type }}">

                        <div class="min-w-56 flex-1">
                            <label class="text-xs font-bold text-gray-500">{{ $credential['label_ar'] }}</label>
                            <input dir="ltr" type="password" name="value" autocomplete="new-password" placeholder="{{ $credential['masked'] ?? 'غير محفوظ' }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                        </div>

                        <button class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-black text-white">حفظ</button>

                        @if ($credential['configured'])
                            <button name="forget" value="1" class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-black text-red-700">حذف</button>
                        @endif
                    </form>
                @endforeach
            </div>
        </section>

        {{-- ── Actions ────────────────────────────────────────────────── --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-6">
            <h2 class="text-lg font-black text-gray-900">الإجراءات</h2>
            <p class="mt-1 text-sm text-gray-500">اختر الإجراء ثم اضبط بياناته. كل إجراء يعمل فقط عند تفعيله وتفعيل التكامل.</p>

            <div class="mt-5 flex flex-wrap gap-2">
                @foreach ($actions as $key => $action)
                    <a href="{{ route('admin.robodesk.settings.index', ['action' => $key]) }}"
                       class="rounded-xl border px-4 py-2 text-sm font-bold {{ $key === $selected ? 'border-indigo-300 bg-indigo-50 text-indigo-800' : 'border-gray-200 text-gray-600 hover:bg-gray-50' }}">
                        {{ $action->labelAr() }}
                        <span class="ms-2 inline-block h-2 w-2 rounded-full {{ $action->setting()->is_enabled ? 'bg-emerald-500' : 'bg-gray-300' }}"></span>
                    </a>
                @endforeach
            </div>

            @php($current = $actions[$selected] ?? null)

            @if ($current)
                @php($params = $current->params())

                <form method="POST" action="{{ route('admin.robodesk.settings.actions.update', $current->key()) }}" class="mt-6 space-y-5 border-t border-gray-100 pt-6">
                    @csrf

                    <div>
                        <h3 class="text-base font-black text-gray-900">{{ $current->labelAr() }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ $current->descriptionAr() }}</p>
                        <p class="mt-1 text-xs text-gray-400" dir="ltr">{{ $current->key() }}</p>
                    </div>

                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="is_enabled" value="1" @checked($current->setting()->is_enabled) class="h-5 w-5 rounded border-gray-300">
                        <span class="text-sm font-bold text-gray-800">تفعيل هذا الإجراء</span>
                    </label>

                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($current->paramSchema() as $field)
                            @php($value = old('params.'.$field['key'], $params[$field['key']] ?? ($field['default'] ?? '')))

                            <div class="{{ in_array($field['type'], ['textarea', 'json'], true) ? 'md:col-span-2' : '' }}">
                                <label class="text-xs font-bold text-gray-500">{{ $field['label_ar'] }}</label>

                                @if ($field['type'] === 'boolean')
                                    <div class="mt-1">
                                        <input type="hidden" name="params[{{ $field['key'] }}]" value="0">
                                        <label class="flex items-center gap-3">
                                            <input type="checkbox" name="params[{{ $field['key'] }}]" value="1" @checked(filter_var($value, FILTER_VALIDATE_BOOLEAN)) class="h-5 w-5 rounded border-gray-300">
                                            <span class="text-sm text-gray-700">مفعّل</span>
                                        </label>
                                    </div>
                                @elseif ($field['type'] === 'select')
                                    <select name="params[{{ $field['key'] }}]" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                        @foreach ($field['options'] as $optionValue => $optionLabel)
                                            <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                @elseif ($field['type'] === 'number')
                                    <input type="number" name="params[{{ $field['key'] }}]" value="{{ $value }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                @elseif (in_array($field['type'], ['textarea', 'json'], true))
                                    <textarea name="params[{{ $field['key'] }}]" rows="{{ $field['type'] === 'json' ? 10 : 4 }}" dir="{{ $field['type'] === 'json' ? 'ltr' : 'rtl' }}" class="mt-1 w-full rounded-xl border-gray-200 font-mono text-xs">{{ $value }}</textarea>
                                @else
                                    <input dir="ltr" name="params[{{ $field['key'] }}]" value="{{ $value }}" placeholder="{{ $field['placeholder'] ?? '' }}" class="mt-1 w-full rounded-xl border-gray-200 text-sm">
                                @endif

                                @if (! empty($field['help_ar']))
                                    <p class="mt-1 text-xs text-gray-400">{{ $field['help_ar'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                        <p class="text-xs font-black text-gray-600">المتغيرات المتاحة لهذا الإجراء</p>
                        @php($open = str_repeat('{', 2))
                        @php($close = str_repeat('}', 2))
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($current->variables() as $variable => $description)
                                <span class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-xs text-gray-700" title="{{ $description }}" dir="ltr">{{ $open.' '.$variable.' '.$close }}</span>
                            @endforeach
                        </div>
                    </div>

                    <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white">حفظ إعدادات الإجراء</button>
                </form>
            @endif
        </section>
    </div>
</x-admin-layout>
