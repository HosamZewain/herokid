<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-bold text-gray-800">مزودو الذكاء الاصطناعي</h2>
            <p class="text-sm text-gray-500">AI Providers & Models</p>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl">
        @if(session('success'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-right font-bold text-green-700">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-right font-bold text-red-700">{{ session('error') }}</div>
        @endif

        <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            <div class="mb-5 text-right">
                <h3 class="text-lg font-black text-gray-950">المزودون المدعومون بالكود</h3>
                <p class="mt-1 text-sm text-gray-500">لا يمكن إضافة مزودين أو endpoints عشوائية من الواجهة. تظهر هنا المزودات التي لها adapter داخل الكود فقط.</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                @foreach($providers as $provider)
                    @php
                        $definition = $registry->provider($provider->driver);
                        $available = $availability->providerAvailable($provider);
                        $credential = $provider->credential;
                        $defaults = data_get($provider->settings_json, 'default_models', []);
                    @endphp
                    <article class="rounded-2xl border border-gray-100 bg-slate-50 p-5 text-right">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h4 class="text-lg font-black text-gray-950">{{ $provider->public_name }}</h4>
                                <p class="mt-1 text-xs font-mono text-gray-400" dir="ltr">{{ $provider->driver }}</p>
                            </div>
                            <span class="rounded-full px-3 py-1 text-xs font-black {{ $available ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                {{ $available ? 'متاح للتوليد' : 'غير متاح' }}
                            </span>
                        </div>

                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                            <div class="rounded-xl bg-white p-3">
                                <p class="text-xs font-bold text-gray-400">الحالة</p>
                                <p class="mt-1 font-black text-gray-900">
                                    @if(!config('production_studio.enabled'))
                                        Studio disabled
                                    @elseif($provider->last_health_check_status === 'failed')
                                        Connection failed
                                    @elseif($provider->is_active && $credential)
                                        Enabled
                                    @elseif($credential)
                                        Configured but disabled
                                    @else
                                        Not configured
                                    @endif
                                </p>
                            </div>
                            <div class="rounded-xl bg-white p-3">
                                <p class="text-xs font-bold text-gray-400">Credential</p>
                                <p class="mt-1 font-black text-gray-900">{{ $credential?->last_four ? 'Configured ending in '.$credential->last_four : 'Not configured' }}</p>
                            </div>
                            <div class="rounded-xl bg-white p-3">
                                <p class="text-xs font-bold text-gray-400">Active models</p>
                                <p class="mt-1 font-black text-gray-900">{{ $provider->models->where('is_active', true)->count() }}</p>
                            </div>
                            <div class="rounded-xl bg-white p-3">
                                <p class="text-xs font-bold text-gray-400">Last test</p>
                                <p class="mt-1 font-black text-gray-900">{{ $provider->last_health_check_status ?? '-' }}</p>
                            </div>
                        </div>

                        <div class="mt-4">
                            <p class="mb-2 text-xs font-black text-gray-500">Capabilities</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach($definition['capabilities'] ?? [] as $capability)
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-indigo-700">{{ $capability }}</span>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4 rounded-xl bg-white p-3 text-xs text-gray-600">
                            <p class="font-black text-gray-900">Default models</p>
                            @forelse($defaults as $capability => $code)
                                <p class="mt-1"><span class="font-bold">{{ $capability }}:</span> <span dir="ltr">{{ $code }}</span></p>
                            @empty
                                <p class="mt-1">لم يتم تحديد افتراضيات بعد.</p>
                            @endforelse
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <a href="{{ route('admin.settings.ai-providers.edit', $provider) }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white">Configure</a>
                            <a href="{{ route('admin.settings.ai-providers.models', $provider) }}" class="rounded-xl border border-indigo-200 bg-white px-4 py-2 text-sm font-black text-indigo-700">Manage Models</a>
                            @can('settings.ai_providers.test_connection')
                                <form method="POST" action="{{ route('admin.settings.ai-providers.test', $provider) }}">
                                    @csrf
                                    <button class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-black text-gray-700">Test Connection</button>
                                </form>
                            @endcan
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-admin-layout>
