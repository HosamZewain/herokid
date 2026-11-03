@php
    $generationBannerData = app(\App\Services\CodexScenes\OrderGenerationBanner::class)->data($generationBannerOrder);
@endphp
<div>
    <div data-codex-generation-banner data-url="{{ route('admin.orders.codex-scenes.banner', $generationBannerOrder) }}" aria-live="polite" aria-atomic="true">
        @include('admin.orders._codex-generation-banner-content', $generationBannerData)
    </div>
    <p data-codex-refresh-note class="mt-2 text-xs font-bold text-gray-600">حالة التوليد وعدد الصفحات المحفوظة يتحدثان تلقائيًا كل 10 ثوانٍ.</p>
</div>
@include('admin.orders._staff-preview-approval')
@include('admin.orders._book-start-actions', $generationBannerData)
@include('admin.orders._codex-scenes-poll')
