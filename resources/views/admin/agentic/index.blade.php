<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">عقل HeroKid — متابعة الطلبات</h1></x-slot>
    <main class="mx-auto max-w-7xl space-y-6 p-6" dir="rtl">
        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4">
            <strong>Shadow Mode — اقتراحات ومحاكاة فقط</strong>
            <p>المتابعة التشغيلية مستقلة عن الذكاء الاصطناعي. التنفيذ الفعلي مستمر من خلال خدمات HeroKid الحالية والموظفين.</p>
        </div>
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <label>نطاق الطلبات <select name="scope" class="rounded-lg border-gray-300"><option value="active">النشطة</option><option value="all" @selected(($filters['scope'] ?? '') === 'all')>كل الطلبات</option></select></label>
            <label>التقييم <select name="state" class="rounded-lg border-gray-300"><option value="">كل الحالات</option>@foreach($states as $value)<option value="{{ $value }}" @selected(($filters['state'] ?? '') === $value)>{{ __('agentic.states.'.$value) }}</option>@endforeach</select></label>
            <label>آخر اقتراح <select name="decision" class="rounded-lg border-gray-300"><option value="">الكل</option>@foreach(\App\Services\Agentic\DecisionValidator::TYPES as $value)<option value="{{ $value }}" @selected(($filters['decision'] ?? '') === $value)>{{ __('agentic.decisions.'.$value) }}</option>@endforeach</select></label>
            <label>المنتج <select name="product" class="rounded-lg border-gray-300"><option value="">كل المنتجات</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(($filters['product'] ?? '') == $product->id)>{{ $product->name_ar }}</option>@endforeach</select></label>
            <button class="rounded-lg bg-indigo-700 px-4 py-2 text-white">تصفية</button>
        </form>
        <p id="agentic-refresh" class="text-sm text-gray-600" aria-live="polite">تحديث تلقائي كل 30 ثانية؛ دون استدعاء الموديل.</p>
        <section id="agentic-overview" data-url="{{ route('admin.agentic.monitor', request()->only('scope','state','decision','product','page')) }}">@include('admin.agentic._overview')</section>
    </main>
    <script>
    (() => {
        const root = document.getElementById('agentic-overview'), note = document.getElementById('agentic-refresh');
        let busy = false;
        setInterval(async () => {
            if (document.hidden || busy) return;
            busy = true;
            try {
                const response = await fetch(root.dataset.url, {headers: {'Accept': 'application/json'}, signal: AbortSignal.timeout(15000)});
                if (!response.ok) throw new Error('refresh');
                const data = await response.json(); root.innerHTML = data.html;
                note.textContent = 'آخر تحديث: ' + new Date().toLocaleTimeString('ar-EG');
            } catch { note.textContent = 'تعذر التحديث؛ البيانات المعروضة من آخر قراءة ناجحة.'; }
            finally { busy = false; }
        }, 30000);
    })();
    </script>
</x-admin-layout>
