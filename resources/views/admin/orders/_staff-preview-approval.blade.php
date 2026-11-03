@php
    $approvalOrder = $generationBannerOrder;
    $canApprovePreview = auth()->user()->hasPermission('orders.photos.view')
        && auth()->user()->hasPermission('orders.production_prompt.manage')
        && app(\App\Services\Orders\OrderAccess::class)->controls($approvalOrder, auth()->user());
    $approvalManifest = $canApprovePreview ? \App\Models\OrderReviewManifest::where('checkout_key', $approvalOrder->checkoutGroupKey())->latest('id')->with('run')->first() : null;
@endphp
@if($approvalManifest && !$approvalManifest->approved_at)
    @php
        $approvalControlVersion = \Illuminate\Support\Facades\DB::table('order_automation_controls')->where('checkout_key', $approvalOrder->checkoutGroupKey())->value('version') ?? 1;
        $approvalInputs = null;
        try { $approvalInputs = app(\App\Services\Orders\StaffPreviewInputs::class)->capture($approvalManifest); } catch (\Throwable) {}
        $approvalPreviewUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('order-process.review-preview', now()->addMinutes(30), ['manifest' => $approvalManifest->id]);
    @endphp
    <section class="mt-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4" id="staff-preview-approval">
        <h3 class="text-sm font-black text-indigo-900">تسجيل موافقة العميل بواسطة الفريق</h3>
        <p class="mt-2 text-sm">لو العميل وافق بمكالمة أو رسالة عادية، سجّل موافقته هنا. تغيير حالة الطلب أو تسجيل دفعة لا يعتمد المعاينة.</p>
        <a href="{{ $approvalPreviewUrl }}" target="_blank" rel="noopener" class="mt-2 inline-block font-bold text-indigo-700 underline">فتح المعاينة الحالية — نسخة {{ $approvalManifest->id }} ({{ count($approvalManifest->images) }} صورة)</a>
        <ul class="mt-2 text-xs">
            @foreach($approvalManifest->images as $approvalImage)<li>{{ $approvalImage['label'] ?? 'صورة المعاينة' }}</li>@endforeach
        </ul>
        @if($approvalInputs === null)
            <p class="mt-3 text-sm font-bold text-red-700">بيانات الطفل أو مراجع المعاينة تغيرت أو غير متاحة. راجع المعاينة الحالية قبل تسجيل الموافقة.</p>
        @else
        @foreach($approvalInputs as $approvalItemId => $approvalInput)
            @if($approvalInput['page_count'])<p class="mt-2 text-sm">منتج #{{ $approvalItemId }}: {{ $approvalInput['page_count'] }} صفحة بالبرومبت الحالي.</p>@endif
        @endforeach
        <p class="mt-2 text-xs">لو تم تعديل برومبت الإنتاج، هذا الإجراء يعتمد صورة المعاينة المعروضة كما هي ويستخدم البرومبت الحالي للصفحات، دون إعادة توليد الكفر.</p>
        <form method="POST" action="{{ route('admin.orders.workspace.preview-approval', $approvalOrder) }}" class="mt-3 space-y-3">
            @csrf
            <input type="hidden" name="inputs_fingerprint" value="{{ app(\App\Services\Orders\StaffPreviewInputs::class)->fingerprint($approvalInputs) }}">
            <input type="hidden" name="manifest_id" value="{{ $approvalManifest->id }}">
            <input type="hidden" name="fingerprint" value="{{ $approvalManifest->fingerprint }}">
            <input type="hidden" name="run_version" value="{{ $approvalManifest->run->version }}">
            <input type="hidden" name="control_version" value="{{ $approvalControlVersion }}">
            <label class="block text-sm font-bold">طريقة موافقة العميل
                <select name="channel" required class="mt-1 block w-full rounded-lg border-gray-300"><option value="">اختر طريقة الموافقة</option><option value="phone">مكالمة</option><option value="whatsapp">رسالة واتساب</option><option value="in_person">موافقة مباشرة</option><option value="other">طريقة أخرى</option></select>
            </label>
            <label class="block text-sm font-bold">تفاصيل الموافقة
                <textarea name="note" required minlength="3" maxlength="1000" rows="2" placeholder="متى وكيف وافق العميل على هذه النسخة؟" class="mt-1 block w-full rounded-lg border-gray-300"></textarea>
            </label>
            <label class="flex gap-2 text-sm"><input type="checkbox" name="confirm" value="1" required> أؤكد موافقة العميل على كل الصور في هذه النسخة، وأطلب استكمال الأوتوميشن. التوليد يبدأ فقط عند استيفاء شروطه وتسجيل دفعة فعلية.</label>
            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white">تسجيل الموافقة واستكمال الطلب</button>
        </form>
        @endif
    </section>
@endif
