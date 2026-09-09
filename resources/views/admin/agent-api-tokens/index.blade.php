<x-admin-layout>
<x-slot name="header">
    <div class="text-right">
        <h1 class="text-2xl font-black text-slate-900">Agent API Tokens</h1>
        <p class="mt-1 text-sm text-slate-500">إنشاء توكنات إنتاج محدودة وتحديد ما إذا كان الوكيل يعمل على القصص أو المنتجات أو كليهما.</p>
    </div>
</x-slot>

<div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6" dir="rtl">
    @if(session('new_agent_token'))
        <section class="rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-5" role="alert">
            <h2 class="font-black text-emerald-900">انسخ التوكن الآن — لن يظهر مرة أخرى</h2>
            <div class="mt-3 flex flex-col gap-3 sm:flex-row">
                <input id="new-agent-token" type="text" readonly value="{{ session('new_agent_token') }}"
                    class="min-w-0 flex-1 rounded-xl border-emerald-300 bg-white p-3 font-mono text-sm" dir="ltr">
                <button type="button" data-copy-agent-token
                    class="min-h-11 rounded-xl bg-emerald-700 px-5 font-bold text-white hover:bg-emerald-800">نسخ التوكن</button>
            </div>
            <p class="mt-2 text-xs font-semibold text-emerald-800">بعد مغادرة أو تحديث الصفحة لا يمكن استرجاع السر؛ يمكنك فقط إلغاء التوكن وإنشاء آخر.</p>
        </section>
    @endif

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-black text-slate-900">إنشاء توكن جديد</h2>
        <p class="mt-1 text-sm text-amber-700">يفضل اختيار حساب Admin منفصل للوكيل. إنشاء التوكن يفعّل Agent API للحساب ويمنحه صلاحيات تشغيل الطلبات المطلوبة فقط.</p>

        <form method="POST" action="{{ route('admin.agent-api-tokens.store') }}" class="mt-5 grid gap-4 lg:grid-cols-2">
            @csrf
            <label class="block">
                <span class="mb-1 block text-sm font-bold text-slate-700">حساب الـAgent</span>
                <select name="agent_user_id" required class="w-full rounded-xl border-slate-300">
                    <option value="">اختر حسابًا</option>
                    @foreach($agents as $agent)
                        <option value="{{ $agent->id }}" @selected(old('agent_user_id') == $agent->id)>{{ $agent->name }} — {{ $agent->email }}</option>
                    @endforeach
                </select>
                @error('agent_user_id')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>

            <label class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 lg:col-span-2">
                <input type="hidden" name="allow_rework" value="0">
                <input type="checkbox" name="allow_rework" value="1" @checked(old('allow_rework')) class="mt-1 rounded border-amber-300 text-amber-600">
                <span>
                    <strong class="block text-amber-950">السماح بتعديل وإعادة إنتاج الطلبات السابقة</strong>
                    <small class="mt-1 block text-amber-800">يمنح الوكيل صلاحية اختيار رقم طلب محدد، تعديل بيانات التخصيص فقط، وإعادته إلى مرحلة الإنتاج. لا يمنحه صلاحية تغيير الأسعار أو الدفع أو الشحن.</small>
                </span>
            </label>

            <label class="flex items-start gap-3 rounded-xl border border-violet-200 bg-violet-50 p-4 lg:col-span-2">
                <input type="hidden" name="identity_only" value="0">
                <input type="checkbox" name="identity_only" value="1" @checked(old('identity_only')) class="mt-1 rounded border-violet-300 text-violet-600">
                <span>
                    <strong class="block text-violet-950">هويات القصص فقط</strong>
                    <small class="mt-1 block text-violet-800">توكن مقيد لا يستطيع تشغيل إنتاج القصص أو المنتجات. اختر معه نطاق «القصص فقط». يستحوذ على عملية الشراء كاملة، لكنه يعرض برومبتات الهوية فقط ويؤجل أي منتجات موجودة.</small>
                </span>
            </label>

            <label class="block">
                <span class="mb-1 block text-sm font-bold text-slate-700">اسم التوكن</span>
                <input name="name" required maxlength="255" value="{{ old('name', 'production-agent') }}" class="w-full rounded-xl border-slate-300" dir="ltr">
                @error('name')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>

            <fieldset class="lg:col-span-2">
                <legend class="mb-2 text-sm font-bold text-slate-700">نطاق المنتجات</legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach(['all' => ['الكل', 'قصص ومنتجات مخصصة'], 'stories' => ['القصص فقط', 'لا يستحوذ على طلبات إنتاج المنتجات'], 'products' => ['المنتجات فقط', 'لا يستحوذ على طلبات القصص']] as $value => [$label, $help])
                        <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-200 p-4 hover:border-indigo-400">
                            <input type="radio" name="catalog_scope" value="{{ $value }}" required @checked(old('catalog_scope', 'all') === $value) class="mt-1">
                            <span><strong class="block text-slate-900">{{ $label }}</strong><small class="text-slate-500">{{ $help }}</small></span>
                        </label>
                    @endforeach
                </div>
                @error('catalog_scope')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </fieldset>

            <fieldset class="rounded-2xl border border-cyan-200 bg-cyan-50 p-4 lg:col-span-2" data-product-restriction>
                <label class="flex items-start gap-3">
                    <input type="hidden" name="restrict_products" value="0">
                    <input type="checkbox" name="restrict_products" value="1" @checked(old('restrict_products'))
                        class="mt-1 rounded border-cyan-300 text-cyan-700" data-restrict-products>
                    <span>
                        <strong class="block text-cyan-950">تقييد هذا الـAgent بمنتجات محددة</strong>
                        <small class="mt-1 block text-cyan-800">يعمل فقط مع نطاق «المنتجات فقط». إذا احتوت عملية الشراء منتج إنتاج آخر غير محدد فلن يستحوذ عليها الـAgent.</small>
                    </span>
                </label>

                <div class="mt-4 grid max-h-72 gap-2 overflow-y-auto rounded-xl border border-cyan-200 bg-white p-3 sm:grid-cols-2 lg:grid-cols-3" data-product-options>
                    @forelse($products as $product)
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-slate-200 p-3 hover:border-cyan-400">
                            <input type="checkbox" name="product_ids[]" value="{{ $product->id }}"
                                @checked(in_array($product->id, old('product_ids', []))) class="mt-1 rounded border-slate-300 text-cyan-700">
                            <span class="min-w-0">
                                <strong class="block text-sm text-slate-900">{{ $product->name_ar ?: $product->name_en ?: $product->slug }}</strong>
                                <small class="block truncate text-slate-500" dir="ltr">#{{ $product->id }}{{ $product->sku ? ' · '.$product->sku : '' }}</small>
                            </span>
                        </label>
                    @empty
                        <p class="text-sm text-slate-500 sm:col-span-2 lg:col-span-3">لا توجد منتجات نشطة لها Production Prompt حاليًا.</p>
                    @endforelse
                </div>
                @error('product_ids')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror
                @error('product_ids.*')<span class="mt-2 block text-sm text-red-600">{{ $message }}</span>@enderror
            </fieldset>

            <label class="block">
                <span class="mb-1 block text-sm font-bold text-slate-700">الصلاحية بالأيام</span>
                <input name="expires_in_days" type="number" min="1" max="365" required value="{{ old('expires_in_days', 90) }}" class="w-full rounded-xl border-slate-300" dir="ltr">
                @error('expires_in_days')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
            </label>

            <div class="flex items-end">
                <button class="min-h-11 w-full rounded-xl bg-indigo-600 px-5 font-bold text-white hover:bg-indigo-700">إنشاء التوكن</button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 p-5"><h2 class="text-lg font-black">التوكنات الحالية</h2></div>
        @if($tokens->isEmpty())
            <p class="p-8 text-center text-slate-500">لا توجد Agent Tokens حاليًا.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-3 text-right">الاسم</th><th class="p-3 text-right">الحساب</th><th class="p-3 text-right">النطاق</th><th class="p-3 text-right">المنتجات المحددة</th><th class="p-3 text-right">نوع العمل</th><th class="p-3 text-right">إعادة العمل</th><th class="p-3 text-right">آخر استخدام</th><th class="p-3 text-right">الانتهاء</th><th class="p-3"></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($tokens as $token)
                            <tr>
                                <td class="p-3 font-mono" dir="ltr">{{ $token['name'] }}</td>
                                <td class="p-3"><strong>{{ $token['agent']->name }}</strong><span class="block text-xs text-slate-500">{{ $token['agent']->email }}</span></td>
                                <td class="p-3"><span class="rounded-full bg-indigo-50 px-3 py-1 font-bold text-indigo-700">{{ \App\Services\AgentApi\AgentCatalogScope::label($token['scope']) }}</span></td>
                                <td class="p-3 text-slate-600">
                                    @if($token['scope'] !== \App\Services\AgentApi\AgentCatalogScope::PRODUCTS)
                                        <span class="text-xs">—</span>
                                    @elseif($token['allowed_products'] === [])
                                        <span class="text-xs">كل منتجات النطاق</span>
                                    @else
                                        <span class="text-xs font-bold text-cyan-800">{{ implode('، ', $token['allowed_products']) }}</span>
                                    @endif
                                </td>
                                <td class="p-3"><span class="rounded-full px-3 py-1 font-bold {{ $token['identity_only'] ? 'bg-violet-100 text-violet-800' : 'bg-slate-100 text-slate-600' }}">{{ $token['identity_only'] ? 'هويات فقط' : 'إنتاج' }}</span></td>
                                <td class="p-3"><span class="rounded-full px-3 py-1 font-bold {{ $token['can_rework'] ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500' }}">{{ $token['can_rework'] ? 'مسموح' : 'غير مسموح' }}</span></td>
                                <td class="p-3 text-slate-600">{{ $token['last_used_at'] ? app_datetime($token['last_used_at']) : 'لم يُستخدم' }}</td>
                                <td class="p-3 text-slate-600">{{ $token['expires_at'] ? app_datetime($token['expires_at']) : 'بدون تاريخ' }}</td>
                                <td class="p-3">
                                    <form method="POST" action="{{ route('admin.agent-api-tokens.destroy', $token['id']) }}" onsubmit="return confirm('إلغاء هذا التوكن فورًا؟');">
                                        @csrf @method('DELETE')
                                        <button class="min-h-10 rounded-lg bg-red-50 px-4 font-bold text-red-700 hover:bg-red-100">إلغاء</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

@if(session('new_agent_token'))
<script>
document.querySelector('[data-copy-agent-token]')?.addEventListener('click', async (event) => {
    const input = document.getElementById('new-agent-token');
    try {
        await navigator.clipboard.writeText(input.value);
    } catch (_) {
        input.select();
        document.execCommand('copy');
    }
    event.currentTarget.textContent = 'تم النسخ';
});
</script>
@endif
<script>
(() => {
    const restrict = document.querySelector('[data-restrict-products]');
    const options = document.querySelector('[data-product-options]');
    const productScope = document.querySelector('input[name="catalog_scope"][value="products"]');
    if (!restrict || !options || !productScope) return;

    const sync = () => {
        const enabled = productScope.checked && restrict.checked;
        options.classList.toggle('opacity-50', !enabled);
        options.querySelectorAll('input[type="checkbox"]').forEach((input) => input.disabled = !enabled);
    };

    document.querySelectorAll('input[name="catalog_scope"]').forEach((input) => input.addEventListener('change', sync));
    restrict.addEventListener('change', sync);
    sync();
})();
</script>
</x-admin-layout>
