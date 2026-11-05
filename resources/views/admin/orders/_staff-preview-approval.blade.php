@php
    $approvalOrder = $generationBannerOrder;
    $canApprovePreview = auth()->user()->hasPermission('orders.photos.view')
        && auth()->user()->hasPermission('orders.production_prompt.manage')
        && app(\App\Services\Orders\OrderAccess::class)->controls($approvalOrder, auth()->user());
    $approvalManifest = $canApprovePreview ? \App\Models\OrderReviewManifest::where('checkout_key', $approvalOrder->checkoutGroupKey())->latest('id')->with('run')->first() : null;
    $identityReplacement = $canApprovePreview && app(\App\Services\Orders\ApprovedIdentityRecovery::class)->candidate($approvalOrder);
@endphp
@if($identityReplacement)
    <section class="mt-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4" id="approved-identity-recovery">
        <p class="font-bold">الهوية المعتمدة الحالية أحدث من المعاينة السابقة.</p>
        <p class="my-2 text-sm">راجع الهوية البديلة والصور الحالية ثم سجّل موافقة العميل عليها لاستكمال المتبقي المؤهل. النسخة السابقة محفوظة.</p>
        <a class="inline-block rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white" href="{{ route('admin.orders.workspace.identity-recovery', $approvalOrder) }}">مراجعة الهوية المعتمدة الجديدة واستكمال الطلب</a>
    </section>
@elseif($approvalManifest && !\App\Models\CodexSceneRun::where('checkout_key', $approvalOrder->checkoutGroupKey())->exists())
    @php
        $approvalControlVersion = \Illuminate\Support\Facades\DB::table('order_automation_controls')->where('checkout_key', $approvalOrder->checkoutGroupKey())->value('version') ?? 1;
        $approvalInputs = null;
        try { $approvalInputs = app(\App\Services\Orders\StaffPreviewInputs::class)->capture($approvalManifest); } catch (\Throwable) {}
    @endphp
    <section class="mt-3 rounded-xl border border-indigo-200 bg-indigo-50 p-4" id="staff-preview-approval">
        @if($approvalInputs === null)
            <p class="mt-3 text-sm font-bold text-red-700">الموافقة الحالية مرتبطة بصورة أو بيانات سابقة. لكتاب التلوين: ارفع الكفر الجديد في «المعاينات»، ثم استخدم «اختيار واعتماد الكفر البديل واستكمال الكتاب» عند بند الكتاب أعلاه. اختر الصورة التي وافق عليها العميل؛ لن يُعاد توليد الكفر.</p>
        @elseif(app(\App\Services\Orders\StaffPreviewInputs::class)->needsApproval($approvalManifest, $approvalInputs))
        @if($approvalManifest->approved_at)
            <p class="mb-2 text-sm">تغيّرت تعليمات الإنتاج. بعد مراجعتها، يمكنك اعتماد نفس صور المعاينة واستكمال الطلب دون إعادة توليد الكفر.</p>
        @endif
        <form method="POST" action="{{ route('admin.orders.workspace.preview-approval', $approvalOrder) }}">
            @csrf
            <input type="hidden" name="inputs_fingerprint" value="{{ app(\App\Services\Orders\StaffPreviewInputs::class)->fingerprint($approvalInputs) }}">
            <input type="hidden" name="manifest_id" value="{{ $approvalManifest->id }}">
            <input type="hidden" name="fingerprint" value="{{ $approvalManifest->fingerprint }}">
            <input type="hidden" name="run_version" value="{{ $approvalManifest->run->version }}">
            <input type="hidden" name="control_version" value="{{ $approvalControlVersion }}">
            <button type="submit" name="confirm" value="1" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white">تسجيل الموافقة واستكمال الطلب</button>
        </form>
        @endif
    </section>
@endif
