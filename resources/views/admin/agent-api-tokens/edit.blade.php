<x-admin-layout>
<x-slot name="header">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-right">
            <h1 class="text-2xl font-black text-slate-900">تعديل Agent API Token</h1>
            <p class="mt-1 text-sm text-slate-500">تتغير صلاحيات التوكن الحالي فورًا دون إنشاء أو عرض قيمة سرية جديدة.</p>
        </div>
        <a href="{{ route('admin.agent-api-tokens.index') }}" class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-center font-bold text-slate-700 hover:bg-slate-50">العودة للتوكنات</a>
    </div>
</x-slot>

@php
    $selectedAbilities = old('abilities', $configuration['abilities']);
    $selectedScope = old('catalog_scope', $configuration['catalog_scope']);
    $selectedProductIds = array_map('intval', old('product_ids', $configuration['product_ids']));
    $restrictProducts = (bool) old('restrict_products', $configuration['restrict_products']);
    $allowRework = (bool) old('allow_rework', $configuration['allow_rework']);
    $identityOnly = (bool) old('identity_only', $configuration['identity_only']);
    $expiryValue = old('expires_at', $token['expires_at']?->format('Y-m-d\TH:i'));
    $abilityLabelsForJs = collect($abilityDefinitions)->mapWithKeys(fn ($definition, $ability) => [$ability => $definition['label']]);
    $originalSecurityForJs = [
        'abilities' => array_values(array_intersect($operationAbilities, $configuration['abilities'])),
        'catalog_scope' => $configuration['catalog_scope'],
        'restrict_products' => $configuration['restrict_products'],
        'product_ids' => array_map('strval', $configuration['product_ids']),
        'allow_rework' => $configuration['allow_rework'],
        'identity_only' => $configuration['identity_only'],
    ];
@endphp

<div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6" dir="rtl">
    <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <div><p class="text-xs font-bold text-slate-500">اسم التوكن</p><p class="mt-1 font-black text-slate-900">{{ $token['name'] }}</p></div>
        <div><p class="text-xs font-bold text-slate-500">حساب الـAgent</p><p class="mt-1 font-black text-slate-900">{{ $agent->name }}</p><p class="text-xs text-slate-500" dir="ltr">{{ $agent->email }}</p></div>
        <div><p class="text-xs font-bold text-slate-500">تاريخ الإنشاء</p><p class="mt-1 font-bold text-slate-700">{{ app_datetime($token['created_at']) }}</p></div>
        <div>
            <p class="text-xs font-bold text-slate-500">الحالة الحالية</p>
            <span class="mt-1 inline-flex rounded-full px-3 py-1 text-xs font-black {{ $token['status'] === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                {{ $token['status'] === 'active' ? 'نشط' : 'منتهي الصلاحية' }}
            </span>
        </div>
        <div><p class="text-xs font-bold text-slate-500">آخر استخدام</p><p class="mt-1 font-bold text-slate-700">{{ $token['last_used_at'] ? app_datetime($token['last_used_at']) : 'لم يُستخدم' }}</p></div>
        <div><p class="text-xs font-bold text-slate-500">انتهاء الصلاحية</p><p class="mt-1 font-bold text-slate-700">{{ $token['expires_at'] ? app_datetime($token['expires_at']) : 'بدون تاريخ' }}</p></div>
        <div class="rounded-xl bg-amber-50 p-3 sm:col-span-2">
            <p class="text-sm font-black text-amber-900">قيمة التوكن السرية غير قابلة للعرض أو الاسترجاع.</p>
            <p class="mt-1 text-xs font-bold text-amber-700">التعديل يحدث metadata وabilities فقط، ولا يغير التوكن الموجود لدى Studio.</p>
        </div>
    </section>

    <form method="POST" action="{{ route('admin.agent-api-tokens.update', $token['id']) }}" class="space-y-6" data-token-edit-form>
        @csrf
        @method('PATCH')

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-black text-slate-900">معلومات التوكن</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="block">
                    <span class="mb-1 block text-sm font-bold text-slate-700">اسم التوكن</span>
                    <input name="name" required maxlength="255" value="{{ old('name', $token['name']) }}" class="w-full rounded-xl border-slate-300" dir="ltr">
                    @error('name')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
                </label>
                <label class="block">
                    <span class="mb-1 block text-sm font-bold text-slate-700">انتهاء الصلاحية</span>
                    <input name="expires_at" type="datetime-local" required value="{{ $expiryValue }}" class="w-full rounded-xl border-slate-300" dir="ltr">
                    <small class="mt-1 block text-slate-500">يمكن تمديده أو تقصيره إلى وقت مستقبلي، بحد أقصى سنة من الآن.</small>
                    @error('expires_at')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
                </label>
            </div>
            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-bold text-slate-500">مالك التوكن — غير قابل للتغيير</p>
                <p class="mt-1 font-black text-slate-900">{{ $agent->name }} <span class="font-normal text-slate-500" dir="ltr">({{ $agent->email }})</span></p>
                <p class="mt-1 text-xs text-slate-500">لن يتم تعديل صلاحيات الحساب العامة تلقائيًا من هذه الصفحة.</p>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
                <h2 class="text-lg font-black text-slate-900">Token Abilities</h2>
                <p class="mt-1 text-sm text-slate-500">هذه الصلاحيات تخص هذا التوكن فقط. صلاحيات حساب الـAgent موضحة بصورة مستقلة بجانب كل ability.</p>
            </div>

            <div class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                <label class="flex items-start gap-3">
                    <input type="checkbox" checked disabled class="mt-1 rounded border-indigo-300 text-indigo-600">
                    <span><strong class="block text-indigo-950">Agent API الأساسي</strong><code class="text-xs text-indigo-700">agent</code><small class="mt-1 block text-indigo-800">إلزامي ولا يمكن حذفه من Agent API token.</small></span>
                </label>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-2" data-operation-abilities>
                @foreach($operationAbilities as $ability)
                    @php
                        $definition = $abilityDefinitions[$ability];
                        $missingPermissions = array_values(array_diff($definition['permissions'], $accountPermissionKeys));
                    @endphp
                    <label class="rounded-xl border border-slate-200 p-4 hover:border-indigo-300">
                        <span class="flex items-start gap-3">
                            <input type="checkbox" name="abilities[]" value="{{ $ability }}" @checked(in_array($ability, $selectedAbilities, true)) class="mt-1 rounded border-slate-300 text-indigo-600" data-operation-ability>
                            <span class="min-w-0">
                                <strong class="block text-slate-900">{{ $definition['label'] }}</strong>
                                <code class="break-all text-xs text-slate-500">{{ $ability }}</code>
                                <small class="mt-1 block text-slate-600">{{ $definition['description'] }}</small>
                            </span>
                        </span>
                        <span class="mt-3 block border-t border-slate-100 pt-3">
                            <span class="text-xs font-black text-slate-600">Account Permissions</span>
                            <span class="mt-1 flex flex-wrap gap-1.5">
                                @foreach($definition['permissions'] as $permission)
                                    @if(in_array($permission, $accountPermissionKeys, true))
                                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-800">✓ {{ $permission }}</span>
                                    @else
                                        <span class="rounded-full bg-red-100 px-2 py-1 text-xs font-bold text-red-800">✕ {{ $permission }}</span>
                                    @endif
                                @endforeach
                            </span>
                            @if($missingPermissions !== [])
                                <span class="mt-2 block text-xs font-bold text-red-700">تحذير: ability التوكن قد تكون مفعلة، لكن الحساب يفتقد {{ implode('، ', $missingPermissions) }}.</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
            @error('abilities')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror
            @error('abilities.*')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror

            <label class="mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                <input type="hidden" name="allow_rework" value="0">
                <input type="checkbox" name="allow_rework" value="1" @checked($allowRework) class="mt-1 rounded border-amber-300 text-amber-600" data-rework>
                <span>
                    <strong class="block text-amber-950">السماح بتعديل وإعادة إنتاج الطلبات السابقة</strong>
                    <small class="mt-1 block text-amber-800">يحافظ على نفس الدلالة الحالية ويضيف أو يحذف معًا: <code>agent:orders.rework</code> و<code>agent:orders.edit-personalization</code>.</small>
                    @php
                        $reworkPermissions = collect($reworkAbilities)->flatMap(fn ($ability) => $abilityDefinitions[$ability]['permissions'])->unique()->values();
                    @endphp
                    <span class="mt-2 flex flex-wrap gap-1.5">
                        @foreach($reworkPermissions as $permission)
                            <span class="rounded-full px-2 py-1 text-xs font-bold {{ in_array($permission, $accountPermissionKeys, true) ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ in_array($permission, $accountPermissionKeys, true) ? '✓' : '✕' }} {{ $permission }}</span>
                        @endforeach
                    </span>
                </span>
            </label>
            @error('allow_rework')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror

            <label class="mt-4 flex items-start gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4">
                <input type="hidden" name="identity_only" value="0">
                <input type="checkbox" name="identity_only" value="1" @checked($identityOnly) class="mt-1 rounded border-violet-300 text-violet-600" data-identity-only>
                <span>
                    <strong class="block text-violet-950">هويات القصص فقط</strong>
                    <small class="mt-1 block text-violet-800">يحوّل التوكن إلى نفس وضع الهوية المقيد المستخدم عند الإنشاء: <code>agent:orders.identity</code> مع نطاق القصص فقط، دون abilities تشغيل الإنتاج.</small>
                    @php
                        $identityPermissions = collect(\App\Services\AgentApi\AgentTokenService::identityAbilities())->flatMap(fn ($ability) => $abilityDefinitions[$ability]['permissions'])->unique()->values();
                        $missingIdentityPermissions = $identityPermissions->diff($accountPermissionKeys)->values();
                    @endphp
                    <span class="mt-2 block text-xs font-black text-violet-800">Account Permissions</span>
                    <span class="mt-1 flex flex-wrap gap-1.5">
                        @foreach($identityPermissions as $permission)
                            <span class="rounded-full px-2 py-1 text-xs font-bold {{ in_array($permission, $accountPermissionKeys, true) ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800' }}">{{ in_array($permission, $accountPermissionKeys, true) ? '✓' : '✕' }} {{ $permission }}</span>
                        @endforeach
                    </span>
                    @if($missingIdentityPermissions->isNotEmpty())
                        <small class="mt-2 block font-bold text-red-700">تحذير: الحساب يفتقد {{ $missingIdentityPermissions->implode('، ') }}.</small>
                    @endif
                </span>
            </label>
            @error('identity_only')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-black text-slate-900">Catalog Scope</h2>
            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach(['all' => ['القصص والمنتجات', 'كل وحدات الإنتاج'], 'stories' => ['القصص فقط', 'Story production units'], 'products' => ['المنتجات فقط', 'Product production units']] as $value => [$label, $help])
                    <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-200 p-4 hover:border-indigo-400">
                        <input type="radio" name="catalog_scope" value="{{ $value }}" required @checked($selectedScope === $value) class="mt-1" data-catalog-scope>
                        <span><strong class="block text-slate-900">{{ $label }}</strong><small class="text-slate-500">{{ $help }}</small></span>
                    </label>
                @endforeach
            </div>
            @error('catalog_scope')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror

            <fieldset class="mt-4 rounded-xl border border-cyan-200 bg-cyan-50 p-4" data-product-restriction>
                <label class="flex items-start gap-3">
                    <input type="hidden" name="restrict_products" value="0">
                    <input type="checkbox" name="restrict_products" value="1" @checked($restrictProducts) class="mt-1 rounded border-cyan-300 text-cyan-700" data-restrict-products>
                    <span><strong class="block text-cyan-950">تقييد التوكن بمنتجات محددة</strong><small class="mt-1 block text-cyan-800">متاح فقط مع نطاق المنتجات. الاختيارات الحالية محملة ويمكن إضافتها أو حذفها.</small></span>
                </label>
                <div class="mt-4 grid max-h-72 gap-2 overflow-y-auto rounded-xl border border-cyan-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-3" data-product-options>
                    @forelse($products as $product)
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 p-3 hover:border-cyan-400">
                            <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" @checked(in_array($product->id, $selectedProductIds, true)) class="mt-1 rounded border-slate-300 text-cyan-700">
                            <span class="min-w-0"><strong class="block text-sm text-slate-900">{{ $product->name_ar ?: $product->name_en ?: $product->slug }}</strong><small class="block truncate text-slate-500" dir="ltr">#{{ $product->id }}{{ $product->sku ? ' · '.$product->sku : '' }}</small></span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-500 sm:col-span-2 lg:col-span-3">لا توجد منتجات نشطة لها Production Prompt حاليًا.</p>
                    @endforelse
                </div>
                @error('product_ids')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror
                @error('product_ids.*')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror
            </fieldset>
        </section>

        <section class="flex flex-col-reverse gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:justify-between">
            <a href="{{ route('admin.agent-api-tokens.index') }}" class="rounded-xl border border-slate-300 px-5 py-3 text-center font-bold text-slate-700 hover:bg-slate-50">إلغاء</a>
            <button class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white hover:bg-indigo-700">حفظ التغييرات</button>
        </section>
    </form>
</div>

<script>
(() => {
    const form = document.querySelector('[data-token-edit-form]');
    if (!form) return;

    const identity = form.querySelector('[data-identity-only]');
    const rework = form.querySelector('[data-rework]');
    const restrict = form.querySelector('[data-restrict-products]');
    const productOptions = form.querySelector('[data-product-options]');
    const storyScope = form.querySelector('[data-catalog-scope][value="stories"]');
    const productScope = form.querySelector('[data-catalog-scope][value="products"]');
    const operationAbilities = [...form.querySelectorAll('[data-operation-ability]')];
    const scopeInputs = [...form.querySelectorAll('[data-catalog-scope]')];
    const productInputs = [...productOptions.querySelectorAll('input[type="checkbox"]')];
    const abilityLabels = {{ Illuminate\Support\Js::from($abilityLabelsForJs) }};
    const original = {{ Illuminate\Support\Js::from($originalSecurityForJs) }};

    const syncProductRestriction = () => {
        const enabled = !identity.checked && productScope.checked && restrict.checked;
        restrict.disabled = identity.checked || !productScope.checked;
        productOptions.classList.toggle('opacity-50', !enabled);
        productInputs.forEach((input) => input.disabled = !enabled);
    };

    const syncIdentityMode = () => {
        operationAbilities.forEach((input) => input.disabled = identity.checked);
        rework.disabled = identity.checked;
        scopeInputs.forEach((input) => {
            input.disabled = identity.checked && input.value !== 'stories';
        });
        if (identity.checked) storyScope.checked = true;
        syncProductRestriction();
    };

    identity.addEventListener('change', syncIdentityMode);
    restrict.addEventListener('change', syncProductRestriction);
    scopeInputs.forEach((input) => input.addEventListener('change', syncProductRestriction));
    syncIdentityMode();

    form.addEventListener('submit', (event) => {
        const currentAbilities = operationAbilities.filter((input) => input.checked && !input.disabled).map((input) => input.value).sort();
        const originalAbilities = [...original.abilities].sort();
        const selectedScopeInput = form.querySelector('[data-catalog-scope]:checked');
        const current = {
            abilities: currentAbilities,
            catalog_scope: selectedScopeInput?.value || 'stories',
            restrict_products: !restrict.disabled && restrict.checked,
            product_ids: productInputs.filter((input) => input.checked && !input.disabled).map((input) => input.value).sort(),
            allow_rework: !rework.disabled && rework.checked,
            identity_only: identity.checked,
        };
        const changes = [];
        currentAbilities.filter((ability) => !originalAbilities.includes(ability)).forEach((ability) => changes.push(`إضافة: ${abilityLabels[ability] || ability}`));
        originalAbilities.filter((ability) => !currentAbilities.includes(ability)).forEach((ability) => changes.push(`إزالة: ${abilityLabels[ability] || ability}`));
        if (current.catalog_scope !== original.catalog_scope) changes.push(`تغيير النطاق إلى: ${current.catalog_scope}`);
        if (current.restrict_products !== original.restrict_products || JSON.stringify(current.product_ids) !== JSON.stringify([...original.product_ids].sort())) changes.push('تغيير قيود المنتجات');
        if (current.allow_rework !== original.allow_rework) changes.push(`${current.allow_rework ? 'تفعيل' : 'إلغاء'} إعادة العمل`);
        if (current.identity_only !== original.identity_only) changes.push(`${current.identity_only ? 'تفعيل' : 'إلغاء'} وضع هويات القصص فقط`);

        if (changes.length > 0 && !window.confirm(`أنت على وشك تغيير صلاحيات أمنية:\n\n- ${changes.join('\n- ')}\n\nهل تريد حفظ التغييرات؟`)) {
            event.preventDefault();
        }
    });
})();
</script>
</x-admin-layout>
