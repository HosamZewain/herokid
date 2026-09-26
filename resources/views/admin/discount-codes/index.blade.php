<x-admin-layout>
    <x-slot name="header">
        <div>
            <h2 class="text-xl font-black text-slate-900">أكواد الخصم</h2>
            <p class="mt-1 text-sm text-slate-500">أنشئ أكوادًا بتاريخ وشروط وحدود استخدام، وطبّقها على الموقع أو التطبيق.</p>
        </div>
    </x-slot>

    <div class="space-y-7">
        @if(session('success'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-bold text-emerald-800">{{ session('success') }}</div>
        @endif

        @can('store.discount_codes.manage')
            <section class="rounded-3xl border border-indigo-100 bg-white p-6 shadow-sm lg:p-8">
                <div class="mb-6">
                    <p class="text-xs font-black text-indigo-600">كود جديد</p>
                    <h3 class="mt-1 text-2xl font-black text-slate-950">إنشاء كود خصم</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-500">الخصم يُحسب على قيمة المنتجات فقط ولا يُخصم من مصاريف التوصيل.</p>
                </div>
                <form method="POST" action="{{ route('admin.discount-codes.store') }}">
                    @csrf
                    @include('admin.discount-codes._form')
                </form>
            </section>
        @endcan

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 px-6 py-5">
                <h3 class="text-lg font-black text-slate-950">الأكواد الحالية</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-sm">
                    <thead class="bg-slate-50 text-right text-xs font-black text-slate-500">
                        <tr><th class="px-5 py-4">الكود والحملة</th><th class="px-5 py-4">الخصم</th><th class="px-5 py-4">الشروط</th><th class="px-5 py-4">القنوات</th><th class="px-5 py-4">الاستخدام</th><th class="px-5 py-4">الفترة</th><th class="px-5 py-4">الإجراءات</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($codes as $code)
                            @php
                                $expired = $code->ends_at?->isPast();
                                $scheduled = $code->starts_at?->isFuture();
                                $exhausted = $code->usage_limit !== null && $code->used_count >= $code->usage_limit;
                            @endphp
                            <tr class="align-top">
                                <td class="px-5 py-5"><strong dir="ltr" class="text-base text-indigo-700">{{ $code->code }}</strong><p class="mt-1 text-xs text-slate-500">{{ $code->name ?: 'بدون اسم حملة' }}</p></td>
                                <td class="px-5 py-5 font-black text-slate-900">{{ $code->discount_type === 'percent' ? arabic_number(number_format($code->discount_value / 100, 2)).'%' : format_money($code->discount_value / 100) }}</td>
                                <td class="px-5 py-5 text-xs leading-6 text-slate-600">
                                    <p>حد أدنى: {{ $code->minimum_subtotal_cents ? format_money($code->minimum_subtotal_cents / 100) : 'لا يوجد' }}</p>
                                    <p>أقصى خصم: {{ $code->maximum_discount_cents ? format_money($code->maximum_discount_cents / 100) : 'مفتوح' }}</p>
                                    <p>لكل عميل: {{ $code->per_user_limit ?? 'مفتوح' }}</p>
                                </td>
                                <td class="px-5 py-5"><div class="flex flex-wrap gap-2">@if($code->website_enabled)<span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700">الموقع</span>@endif @if($code->mobile_enabled)<span class="rounded-full bg-sky-50 px-3 py-1 text-xs font-black text-sky-700">التطبيق</span>@endif</div></td>
                                <td class="px-5 py-5"><strong>{{ arabic_number($code->used_count) }}</strong> / {{ $code->usage_limit === null ? 'مفتوح' : arabic_number($code->usage_limit) }}<p class="mt-1 text-xs text-slate-400">الموقع: {{ arabic_number($code->website_redemptions_count) }}</p></td>
                                <td class="px-5 py-5 text-xs leading-6 text-slate-600"><p>من: {{ $code->starts_at ? app_datetime($code->starts_at, 'Y-m-d H:i') : 'الآن' }}</p><p>إلى: {{ $code->ends_at ? app_datetime($code->ends_at, 'Y-m-d H:i') : 'بدون انتهاء' }}</p></td>
                                <td class="px-5 py-5">
                                    <div class="flex flex-wrap gap-2">
                                        <span class="rounded-full px-3 py-1 text-xs font-black {{ $code->is_active && !$expired && !$exhausted ? ($scheduled ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800') : 'bg-slate-100 text-slate-600' }}">{{ !$code->is_active ? 'متوقف' : ($expired ? 'منتهي' : ($exhausted ? 'اكتمل الحد' : ($scheduled ? 'مجدول' : 'نشط'))) }}</span>
                                        @can('store.discount_codes.manage')
                                            <a href="{{ route('admin.discount-codes.edit', $code) }}" class="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700">تعديل</a>
                                            <form method="POST" action="{{ route('admin.discount-codes.toggle', $code) }}">@csrf @method('PATCH')<button class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-black text-slate-700">{{ $code->is_active ? 'إيقاف' : 'تفعيل' }}</button></form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-6 py-12 text-center font-bold text-slate-500">لا توجد أكواد خصم بعد.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($codes->hasPages())<div class="border-t border-slate-100 px-6 py-4">{{ $codes->links() }}</div>@endif
        </section>
    </div>
</x-admin-layout>
