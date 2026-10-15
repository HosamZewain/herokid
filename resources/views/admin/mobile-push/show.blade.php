<x-admin-layout>
<x-slot name="header"><h2 class="text-xl font-bold">نتائج إشعار التطبيق</h2></x-slot>
<div class="space-y-5" dir="rtl">
    @if(session('success'))<p role="status" class="rounded-xl bg-emerald-50 p-4">{{ session('success') }}</p>@endif
    <a href="{{ route('admin.mobile-push.index') }}" class="text-indigo-700 underline">رجوع إلى إشعارات التطبيق</a>
    <section class="space-y-3 rounded-2xl bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-black">{{ $campaign->title }}</h2><p class="whitespace-pre-wrap">{{ $campaign->body }}</p>
        <p>حالة التجهيز: {{ ['queued' => 'بانتظار العامل', 'dispatching' => 'تجهيز الإرسال', 'enqueued' => 'تم تجهيز طابور الإرسال', 'failed' => 'تعذر التجهيز — راجع عامل الخلفية'][$campaign->status] ?? $campaign->status }}</p>
        <p>الصفحة: {{ \App\Http\Controllers\Admin\MobilePushCampaignController::DESTINATIONS[$campaign->data['screen']] ?? '—' }}</p>
        <p>عدد العملاء في المعاينة: {{ $campaign->preview_recipients }} · المسجلون فعليًا: {{ $notifications->total() }}</p>
        <p class="font-bold">قبلتها خدمة الإشعارات: {{ $counts['sent'] ?? 0 }} جهاز · فشل: {{ $counts['failed'] ?? 0 }} · بانتظار المحاولة: {{ $counts['pending'] ?? 0 }}</p>
        <p>نتائج المتابعة: قبلتها Apple/Google {{ $receiptCounts['provider_accepted'] ?? 0 }} · فشل لاحق {{ $receiptCounts['failed'] ?? 0 }} · غير معروف {{ $receiptCounts['unknown'] ?? 0 }}. تُراجع الإيصالات بعد 15 دقيقة عبر المجدول.</p>
        <p class="text-sm text-amber-800">قبول الخدمة ليس إثبات وصول للهاتف أو قراءة. النتائج تتحدث مع تشغيل العامل؛ حدّث الصفحة لمراجعتها. لا توجد محاولة مسجلة = بانتظار العامل أو لم تعد هناك أجهزة مؤهلة. عدد الأجهزة قد يزيد عن العملاء.</p>
    </section>
    <section class="overflow-x-auto rounded-2xl bg-white p-6 shadow-sm"><table class="w-full text-right"><thead><tr><th class="p-3">العميل</th><th class="p-3">نتيجة كل جهاز</th><th class="p-3">قراءة داخل التطبيق</th></tr></thead><tbody>
    @forelse($notifications as $notification)<tr class="border-t"><td class="p-3">{{ $notification->user?->name ?: '—' }}</td><td class="p-3">@forelse($notification->deliveries as $delivery)<p>{{ ['sent' => 'قبلتها الخدمة', 'pending' => 'بانتظار الإرسال', 'failed' => 'فشل'][$delivery->status] ?? 'قيد التنفيذ' }} · المحاولات {{ $delivery->attempts }} @if($delivery->receipt_status) · {{ ['provider_accepted' => 'قبلتها Apple/Google', 'failed' => 'فشل لاحق لدى المزود', 'pending' => 'انتظار إيصال المزود', 'unknown' => 'لا يوجد إيصال خلال 24 ساعة'][$delivery->receipt_status] ?? '' }} @endif @if($delivery->error_code && preg_match('/^[a-zA-Z_]{1,60}$/', $delivery->error_code)) · {{ $delivery->error_code }} @endif</p>@empty لا توجد محاولة مسجلة بعد @endforelse</td><td class="p-3">{{ $notification->read_at ? app_datetime($notification->read_at, 'Y-m-d H:i') : 'غير مقروء' }}</td></tr>@empty<tr><td colspan="3" class="p-4">لم يجهز العامل أي مستلمين بعد.</td></tr>@endforelse
    </tbody></table>{{ $notifications->links() }}</section>
</div>
</x-admin-layout>
