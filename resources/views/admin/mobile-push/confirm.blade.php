<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold">تأكيد إشعار التطبيق</h2></x-slot>
<section class="mx-auto max-w-3xl space-y-5 rounded-2xl bg-white p-6 shadow-sm" dir="rtl">
    @if($errors->any())<p role="alert" class="text-red-700">{{ $errors->first() }}</p>@endif
    <h2 class="text-2xl font-black">راجع قبل الإرسال</h2>
    <p>الجمهور: {{ $draft['audience'] === 'all' ? 'كل المستخدمين المؤهلين' : $customer?->name }} — <strong>{{ $draft['count'] }} عميل مؤهل حاليًا</strong></p>
    <p>النوع: {{ $draft['category'] === 'marketing' ? 'تسويقي' : 'خدمي' }} · الصفحة: {{ $destinations[$draft['destination']] }}</p>
    <div class="rounded-xl bg-slate-50 p-5"><h3 class="text-xl font-bold">{{ $draft['title'] }}</h3><p class="mt-3 whitespace-pre-wrap">{{ $draft['body'] }}</p></div>
    <p class="text-sm text-slate-600">قد يقل العدد إذا أُلغي الإذن أو تغيرت الأهلية قبل التنفيذ. لن يُرسل قبل تأكيدك. الإرسال لا يمكن سحبه بعد بدئه.</p>
    <form method="POST" action="{{ route('admin.mobile-push.store') }}" class="space-y-5">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="flex gap-3"><input type="checkbox" name="confirmed" value="1" required><span>راجعت النص والجمهور ونوع الإشعار وأؤكد الإرسال.</span></label>
        <button class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white">تأكيد وجدولة الإرسال</button>
        <a href="{{ route('admin.mobile-push.index') }}" class="mr-4 underline">رجوع بدون إرسال</a>
    </form>
</section>
</x-admin-layout>
