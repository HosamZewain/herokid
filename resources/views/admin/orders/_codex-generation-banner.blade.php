@php
    $generationBannerData = app(\App\Services\CodexScenes\OrderGenerationBanner::class)->data($generationBannerOrder);
@endphp
<div>
    <div data-codex-generation-banner data-url="{{ route('admin.orders.codex-scenes.banner', $generationBannerOrder) }}" aria-live="polite" aria-atomic="true">
        @include('admin.orders._codex-generation-banner-content', $generationBannerData)
    </div>
    <p data-codex-refresh-note class="mt-2 text-xs font-bold text-gray-600">حالة التوليد وعدد الصفحات المحفوظة يتحدثان تلقائيًا كل 10 ثوانٍ.</p>
</div>
@if(auth()->user()->hasPermission('orders.update') && auth()->user()->hasPermission('orders.photos.view')
    && auth()->user()->hasPermission('orders.production_prompt.manage') && app(\App\Services\Orders\OrderAccess::class)->controls($generationBannerOrder,auth()->user())
    && app(\App\Services\Orders\StoryPreviewRecovery::class)->candidate($generationBannerOrder))
    <section class="my-4 rounded-xl border border-amber-300 bg-amber-50 p-4" id="story-recovery">
        <p class="font-bold">تغيّرت القصة بعد المعاينة؛ يلزم تجهيز نسخة محدثة قبل الاستكمال.</p>
        <p class="my-2 text-sm">راجع القصة الجديدة وصور المنتجات. الصور المطابقة تُحفظ، والمنتج الذي تغيرت مراجعه تختار الاحتفاظ بصورته أو إعادة توليده.</p>
        <a class="inline-block rounded-lg bg-indigo-700 px-4 py-2 font-bold text-white" href="{{ route('admin.orders.workspace.story-recovery',$generationBannerOrder) }}">استكمال بعد تغيير القصة</a>
    </section>
@endif
@include('admin.orders._staff-preview-approval')
@include('admin.orders._book-start-actions', $generationBannerData)
@include('admin.orders._codex-scenes-poll')
