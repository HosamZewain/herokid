<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">متابعة توليد مشاهد القصص — Codex</h1></x-slot>
    <div class="mx-auto max-w-7xl space-y-6 p-6" dir="rtl">
        <a href="{{ route('admin.order-automation.index') }}" class="text-indigo-700 underline">كل مسارات الأوتوميشن</a>
        <p>التسجيل التلقائي: {{ config('codex_scenes.automatic') ? 'مفعّل' : 'متوقف' }} · تشغيل حتى {{ config('codex_scenes.concurrency') }} قصة بالتوازي. كل قصة لها هوية وبرومبت ومشاهد مستقلة.</p>
        <p class="text-sm text-gray-600">هوية معتمدة + دفعة فعلية مسجلة. لا إعادة تلقائية للقصص السابقة، ولا سحب للطلبات المتوقفة للعمل اليدوي. لا يتم إرسال الصور للعميل تلقائيًا.</p>
        <form method="GET" class="flex gap-3"><label>الحالة <select name="status" class="rounded-lg border-gray-300"><option value="">كل الحالات</option>@foreach(\App\Models\CodexSceneRun::LABELS as $key=>$label)<option value="{{ $key }}" @selected($sceneStatus === $key)>{{ $label }}</option>@endforeach</select></label><button class="rounded-lg border px-4 py-2">عرض</button></form>
        <section>
            <p data-codex-refresh-note class="mb-3 text-xs text-gray-500" aria-live="polite">تتحدث المشاهد وحالة التوليد تلقائيًا كل 10 ثوانٍ.</p>
            <div data-codex-progress data-url="{{ route('admin.order-automation.codex-scenes.progress',request()->only('status','page')) }}">@include('admin.order-automation._codex-scene-runs')</div>
        </section>
    </div>
    @include('admin.orders._codex-scenes-poll')
</x-admin-layout>
