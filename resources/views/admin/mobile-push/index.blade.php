<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold">إشعارات التطبيق</h2></x-slot>
<div class="space-y-6" dir="rtl">
    @if($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-4 text-red-700">{{ $errors->first() }}</div>@endif
    <p class="rounded-xl bg-amber-50 p-4 text-amber-900">الإرسال للأجهزة المسجلة التي تسمح بهذا النوع فقط. التسويقي يتطلب موافقة التسويق. إصدار basic المعطّل فيه الإشعارات يحتاج تحديث التطبيق وإعداد Apple أولًا. اختيار الصفحة يحتاج نسخة التطبيق الداعمة للتنقل.</p>
    @can('settings.mobile.manage')
    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-xl font-black">إشعار جديد</h2>
        <form method="GET" class="mt-4 flex gap-3"><label class="flex-1">ابحث عن عميل بالاسم أو الهاتف أو الإيميل<input name="q" value="{{ $q }}" maxlength="100" class="mt-2 w-full rounded-xl border-gray-300"></label><button class="rounded-xl bg-slate-100 px-4">بحث</button></form>
        <form method="POST" action="{{ route('admin.mobile-push.preview') }}" class="mt-6 grid gap-4 md:grid-cols-2">
            @csrf
            <label>الجمهور<select name="audience" class="mt-2 w-full rounded-xl border-gray-300"><option value="customer" @selected(old('audience') !== 'all')>عميل محدد</option><option value="all" @selected(old('audience') === 'all')>كل المستخدمين المؤهلين</option></select></label>
            <label>العميل (ابحث أولًا لو غير موجود)<select name="user_id" class="mt-2 w-full rounded-xl border-gray-300"><option value="">اختر العميل</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((int)old('user_id') === $customer->id)>{{ $customer->name }} — {{ $customer->phone ?: $customer->email }}</option>@endforeach</select></label>
            <label>نوع الإشعار<select name="category" class="mt-2 w-full rounded-xl border-gray-300"><option value="marketing" @selected(old('category') !== 'operational')>تسويقي / عرض</option><option value="operational" @selected(old('category') === 'operational')>خدمي — ليس إعلانًا</option></select></label>
            <label>الصفحة عند الضغط<select name="destination" class="mt-2 w-full rounded-xl border-gray-300">@foreach($destinations as $key => $label)<option value="{{ $key }}" @selected(old('destination') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="md:col-span-2">عنوان الإشعار<input name="title" value="{{ old('title') }}" required maxlength="120" class="mt-2 w-full rounded-xl border-gray-300"></label>
            <label class="md:col-span-2">نص الإشعار<textarea name="body" required maxlength="1000" rows="4" class="mt-2 w-full rounded-xl border-gray-300">{{ old('body') }}</textarea></label>
            <p class="text-sm text-slate-500 md:col-span-2">لا تضع صور الأطفال أو معلومات حساسة؛ نص الإشعار قد يظهر على شاشة القفل. راجع الجمهور قبل التأكيد.</p>
            <button class="rounded-xl bg-indigo-600 px-6 py-3 font-bold text-white">معاينة ومراجعة قبل الإرسال</button>
        </form>
    </section>
    @endcan
    <section class="rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-xl font-black">سجل الإرسال</h2>
        <div class="mt-4 overflow-x-auto"><table class="w-full text-right"><thead><tr><th class="p-3">التاريخ / الموظف</th><th class="p-3">الإشعار</th><th class="p-3">الجمهور</th><th class="p-3">السجلات</th><th class="p-3">التفاصيل</th></tr></thead><tbody>
        @forelse($campaigns as $campaign)<tr class="border-t"><td class="p-3">{{ app_datetime($campaign->created_at, 'Y-m-d H:i') }}<br>{{ $campaign->creator?->name ?: '—' }}</td><td class="p-3">{{ $campaign->title }}</td><td class="p-3">{{ $campaign->audience === 'all' ? 'كل المؤهلين' : ($campaign->target?->name ?: 'عميل محذوف') }}</td><td class="p-3">{{ $campaign->notifications_count }} عميل</td><td class="p-3"><a href="{{ route('admin.mobile-push.show', $campaign) }}" class="font-bold text-indigo-700 underline">عرض النتائج</a></td></tr>@empty<tr><td colspan="5" class="p-4">لا توجد حملات بعد.</td></tr>@endforelse
        </tbody></table></div>{{ $campaigns->links() }}
    </section>
</div>
</x-admin-layout>
