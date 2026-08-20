<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold text-gray-800">تشغيل تطبيق الهاتف</h2></x-slot>
@php
    $s = fn (string $key, string $default = '') => old($key, $settings[$key] ?? $default);
    $funnel = [
        'app_opened' => 'فتح التطبيق', 'registration_completed' => 'إتمام التسجيل',
        'child_profile_created' => 'إنشاء ملف طفل', 'photo_upload_completed' => 'نجاح رفع الصور',
        'identity_generation_completed' => 'إتمام الهوية', 'product_viewed' => 'عرض منتج',
        'personalization_completed' => 'إتمام التخصيص', 'product_added_to_cart' => 'إضافة للسلة',
        'checkout_started' => 'بدء الدفع', 'order_completed' => 'إتمام الطلب',
        'preview_approved' => 'اعتماد المعاينة', 'product_reordered' => 'إعادة الطلب',
    ];
@endphp

<div class="space-y-6" dir="rtl">
    @if(session('success'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-800"><ul class="list-disc pr-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="grid gap-4 md:grid-cols-4">
        @foreach([
            ['الأجهزة النشطة خلال 30 يومًا', $stats['active_devices']],
            ['السلات المتروكة', $stats['abandoned_carts']],
            ['طلبات مكتملة خلال 30 يومًا', $stats['completed_checkouts']],
            ['طلبات خصوصية مفتوحة', $stats['pending_privacy']],
        ] as [$label, $value])
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-sm font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-black text-slate-900">{{ number_format($value) }}</p></div>
        @endforeach
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-black text-slate-900">الإصدار والحالة والمحتوى الرئيسي</h2>
        <p class="mt-1 text-sm text-slate-500">تصل هذه القيم إلى التطبيق من الخادم دون إصدار نسخة جديدة.</p>
        <form method="POST" action="{{ route('admin.mobile-operations.config.update') }}" class="mt-5 grid gap-4 md:grid-cols-2">
            @csrf @method('PUT')
            <label class="text-sm font-bold">أقل إصدار مدعوم<input name="mobile_minimum_supported_version" value="{{ $s('mobile_minimum_supported_version', '1.0.0') }}" dir="ltr" class="mt-2 w-full rounded-xl border-gray-300"></label>
            <label class="text-sm font-bold">أحدث إصدار<input name="mobile_latest_version" value="{{ $s('mobile_latest_version', '1.0.0') }}" dir="ltr" class="mt-2 w-full rounded-xl border-gray-300"></label>
            <label class="flex items-center gap-3 rounded-xl border p-4"><input type="hidden" name="mobile_force_update" value="0"><input type="checkbox" name="mobile_force_update" value="1" @checked((string)$s('mobile_force_update', '0') === '1')> <span class="font-bold">فرض تحديث التطبيق</span></label>
            <label class="flex items-center gap-3 rounded-xl border p-4"><input type="hidden" name="mobile_maintenance_mode" value="0"><input type="checkbox" name="mobile_maintenance_mode" value="1" @checked((string)$s('mobile_maintenance_mode', '0') === '1')> <span class="font-bold">وضع الصيانة</span></label>
            <label class="text-sm font-bold">عنوان البانر بالعربية<input name="mobile_home_banner_title_ar" value="{{ $s('mobile_home_banner_title_ar', 'طفلك هو بطل الحكاية') }}" class="mt-2 w-full rounded-xl border-gray-300"></label>
            <label class="text-sm font-bold">عنوان البانر بالإنجليزية<input name="mobile_home_banner_title_en" value="{{ $s('mobile_home_banner_title_en', 'Your child is the hero') }}" dir="ltr" class="mt-2 w-full rounded-xl border-gray-300"></label>
            <label class="text-sm font-bold">الوصف بالعربية<textarea name="mobile_home_banner_subtitle_ar" class="mt-2 w-full rounded-xl border-gray-300">{{ $s('mobile_home_banner_subtitle_ar') }}</textarea></label>
            <label class="text-sm font-bold">الوصف بالإنجليزية<textarea name="mobile_home_banner_subtitle_en" dir="ltr" class="mt-2 w-full rounded-xl border-gray-300">{{ $s('mobile_home_banner_subtitle_en') }}</textarea></label>
            <label class="text-sm font-bold">رابط صورة HTTPS<input name="mobile_home_banner_image_url" value="{{ $s('mobile_home_banner_image_url') }}" dir="ltr" class="mt-2 w-full rounded-xl border-gray-300"></label>
            <label class="text-sm font-bold">الرابط العميق داخل التطبيق<input name="mobile_home_banner_deep_link" value="{{ $s('mobile_home_banner_deep_link', '/catalog') }}" dir="ltr" class="mt-2 w-full rounded-xl border-gray-300"></label>
            @can('settings.mobile.manage')<div class="md:col-span-2"><button class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white">حفظ إعدادات التطبيق</button></div>@endcan
        </form>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-black text-slate-900">مسار التحويل — آخر 30 يومًا</h2>
        <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($funnel as $event => $label)<div class="rounded-xl bg-slate-50 p-4"><p class="text-sm font-bold text-slate-500">{{ $label }}</p><p class="mt-1 text-2xl font-black">{{ number_format((int)($events[$event] ?? 0)) }}</p></div>@endforeach
        </div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-black text-slate-900">أكواد الخصم الخاصة بالتطبيق</h2>
        @can('settings.mobile.manage')
        <form method="POST" action="{{ route('admin.mobile-operations.promo-codes.store') }}" class="mt-4 grid gap-3 md:grid-cols-4">
            @csrf
            <input name="code" placeholder="الكود" dir="ltr" class="rounded-xl border-gray-300" required>
            <input name="name" placeholder="اسم الحملة" class="rounded-xl border-gray-300">
            <select name="discount_type" class="rounded-xl border-gray-300"><option value="percent">نسبة مئوية</option><option value="fixed">قيمة ثابتة بالجنيه</option></select>
            <input name="discount_value" type="number" step="0.01" min="0.01" placeholder="قيمة الخصم" class="rounded-xl border-gray-300" required>
            <input name="minimum_subtotal" type="number" step="0.01" min="0" placeholder="أقل سلة بالجنيه" class="rounded-xl border-gray-300">
            <input name="maximum_discount" type="number" step="0.01" min="0" placeholder="أقصى خصم بالجنيه" class="rounded-xl border-gray-300">
            <input name="usage_limit" type="number" min="1" placeholder="إجمالي الاستخدام" class="rounded-xl border-gray-300">
            <input name="per_user_limit" type="number" min="1" placeholder="لكل مستخدم" class="rounded-xl border-gray-300">
            <input name="starts_at" type="datetime-local" class="rounded-xl border-gray-300">
            <input name="ends_at" type="datetime-local" class="rounded-xl border-gray-300">
            <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">إنشاء الكود</button>
        </form>
        @endcan
        <div class="mt-5 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-right text-slate-500"><th class="p-3">الكود</th><th class="p-3">الخصم</th><th class="p-3">الاستخدام</th><th class="p-3">الحالة</th></tr></thead><tbody>
            @forelse($promoCodes as $promo)<tr class="border-b"><td class="p-3 font-black" dir="ltr">{{ $promo->code }}</td><td class="p-3">{{ $promo->discount_type === 'percent' ? number_format($promo->discount_value / 100, 2).'%' : number_format($promo->discount_value / 100, 2).' ج.م' }}</td><td class="p-3">{{ $promo->used_count }} / {{ $promo->usage_limit ?? '∞' }}</td><td class="p-3"><form method="POST" action="{{ route('admin.mobile-operations.promo-codes.update', $promo) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $promo->is_active ? 0 : 1 }}"><button class="rounded-lg px-3 py-1 font-bold {{ $promo->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700' }}">{{ $promo->is_active ? 'نشط' : 'متوقف' }}</button></form></td></tr>
            @empty<tr><td colspan="4" class="p-6 text-center text-slate-500">لا توجد أكواد بعد.</td></tr>@endforelse
        </tbody></table></div>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-xl font-black text-slate-900">طلبات الخصوصية</h2>
        <p class="mt-1 text-sm text-slate-500">الإكمال النهائي يتم فقط بعد تنفيذ الحذف أو التصدير والتحقق منه؛ هذه الشاشة تبدأ المعالجة أو ترفض الطلب.</p>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-right text-slate-500"><th class="p-3">العميل</th><th class="p-3">النوع</th><th class="p-3">تاريخ الطلب</th><th class="p-3">الحالة</th><th class="p-3">إجراء</th></tr></thead><tbody>
        @forelse($privacyRequests as $privacy)<tr class="border-b"><td class="p-3"><strong>{{ $privacy->user?->name }}</strong><br><span dir="ltr">{{ $privacy->user?->email ?: $privacy->user?->phone }}</span></td><td class="p-3">{{ $privacy->request_type }}</td><td class="p-3">{{ $privacy->requested_at?->format('Y-m-d H:i') }}</td><td class="p-3">{{ $privacy->status }}</td><td class="p-3">@if(!in_array($privacy->status, ['completed','cancelled','rejected']))<form method="POST" action="{{ route('admin.mobile-operations.privacy-requests.update', $privacy) }}" class="flex gap-2">@csrf @method('PATCH')<button name="status" value="in_progress" class="rounded-lg bg-amber-100 px-3 py-1 font-bold text-amber-800">بدء المعالجة</button><button name="status" value="rejected" class="rounded-lg bg-red-100 px-3 py-1 font-bold text-red-800">رفض</button></form>@endif</td></tr>
        @empty<tr><td colspan="5" class="p-6 text-center text-slate-500">لا توجد طلبات خصوصية.</td></tr>@endforelse
        </tbody></table></div>
    </section>
</div>
</x-admin-layout>
