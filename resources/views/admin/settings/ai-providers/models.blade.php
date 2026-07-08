<x-admin-layout>
    <x-slot name="header">
        <div class="text-right">
            <h2 class="text-xl font-bold text-gray-800">نماذج {{ $provider->public_name }}</h2>
            <p class="text-sm text-gray-500">Model Management & Defaults</p>
        </div>
    </x-slot>

    <div class="space-y-6" dir="rtl">
        @if(session('success'))
            <div class="rounded-xl border border-green-200 bg-green-50 px-5 py-4 text-right font-bold text-green-700">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-right text-sm font-bold text-red-700">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('admin.settings.ai-providers.models.update', $provider) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm text-right">
                <div class="mb-5 border-b border-gray-100 pb-4">
                    <h3 class="text-lg font-black text-gray-950">النماذج المدعومة</h3>
                    <p class="mt-1 text-sm text-gray-500">يمكن تعديل النماذج الموجودة في registry فقط. لا يمكن إدخال model ID عشوائي.</p>
                </div>

                <div class="space-y-4">
                    @foreach($provider->models as $model)
                        @php $supported = $definition['models'][$model->code] ?? null; @endphp
                        @if($supported)
                            <article class="rounded-2xl border border-gray-100 bg-slate-50 p-4">
                                <input type="hidden" name="models[{{ $loop->index }}][code]" value="{{ $model->code }}">
                                <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                                    <label class="block md:col-span-2">
                                        <span class="text-xs font-black text-gray-500">Display name</span>
                                        <input name="models[{{ $loop->index }}][display_name]" value="{{ old("models.{$loop->index}.display_name", $model->display_name) }}" @cannot('settings.ai_providers.manage_models') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                                    </label>
                                    <div class="md:col-span-2">
                                        <p class="text-xs font-black text-gray-500">Model code</p>
                                        <p class="mt-2 rounded-xl bg-white px-3 py-2 text-left font-mono text-xs text-gray-600" dir="ltr">{{ $model->code }}</p>
                                    </div>
                                    <label class="block">
                                        <span class="text-xs font-black text-gray-500">Active</span>
                                        <select name="models[{{ $loop->index }}][is_active]" @cannot('settings.ai_providers.enable_disable') disabled @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                                            <option value="0" @selected(!$model->is_active)>Disabled</option>
                                            <option value="1" @selected($model->is_active)>Enabled</option>
                                        </select>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-black text-gray-500">Sort</span>
                                        <input type="number" name="models[{{ $loop->index }}][sort_order]" value="{{ old("models.{$loop->index}.sort_order", $model->sort_order) }}" @cannot('settings.ai_providers.manage_models') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                                    </label>
                                </div>

                                <div class="mt-3 grid grid-cols-1 md:grid-cols-4 gap-3">
                                    <label class="block">
                                        <span class="text-xs font-black text-gray-500">Estimated cost</span>
                                        <input type="number" step="0.0001" name="models[{{ $loop->index }}][estimated_cost_amount]" value="{{ old("models.{$loop->index}.estimated_cost_amount", $model->estimated_cost_amount ?? $model->estimated_cost_per_output) }}" @cannot('settings.ai_providers.view_costs') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-black text-gray-500">Currency</span>
                                        <input name="models[{{ $loop->index }}][estimated_cost_currency]" value="{{ old("models.{$loop->index}.estimated_cost_currency", $model->estimated_cost_currency ?? 'USD') }}" maxlength="3" @cannot('settings.ai_providers.view_costs') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-black text-gray-500">Cost unit</span>
                                        <select name="models[{{ $loop->index }}][cost_unit]" @cannot('settings.ai_providers.view_costs') disabled @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                                            @foreach(['per_image', 'per_megapixel', 'per_request'] as $unit)
                                                <option value="{{ $unit }}" @selected(($model->cost_unit ?? 'per_image') === $unit)>{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <div>
                                        <p class="text-xs font-black text-gray-500">Capabilities</p>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach($supported['capabilities'] as $capability)
                                                <span class="rounded-full bg-white px-2 py-1 text-[11px] font-bold text-indigo-700">{{ $capability }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <label class="mt-3 block">
                                    <span class="text-xs font-black text-gray-500">Notes / intended use</span>
                                    <textarea name="models[{{ $loop->index }}][notes]" rows="2" @cannot('settings.ai_providers.manage_models') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">{{ old("models.{$loop->index}.notes", $model->notes) }}</textarea>
                                </label>
                            </article>
                        @endif
                    @endforeach
                </div>
            </section>

            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm text-right">
                <h3 class="text-lg font-black text-gray-950">النماذج الافتراضية حسب الاستخدام</h3>
                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                    @foreach($capabilities as $capability)
                        <label class="block">
                            <span class="text-sm font-black text-gray-700">{{ $capability }}</span>
                            <select name="default_models[{{ $capability }}]" @cannot('settings.ai_providers.manage_models') disabled @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                <option value="">بدون افتراضي</option>
                                @foreach($provider->models as $model)
                                    @if(in_array($capability, $model->generation_capabilities_json ?? [], true))
                                        <option value="{{ $model->code }}" @selected(data_get($provider->settings_json, "default_models.{$capability}") === $model->code)>{{ $model->display_name }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
            </section>

            @can('settings.ai_providers.manage_models')
                <button class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ النماذج والافتراضيات</button>
            @endcan
        </form>
    </div>
</x-admin-layout>
