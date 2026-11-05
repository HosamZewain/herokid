<x-admin-layout>
    <x-slot name="header"><h1 class="text-2xl font-bold">HeroKid Auto Pilot</h1></x-slot>
    <main class="pilot-dashboard" dir="rtl">
        <section id="autopilot-activity" data-url="{{ route('admin.agentic.activity') }}">@include('admin.agentic._cockpit')</section>
        <p id="autopilot-activity-refresh" class="pilot-refresh" aria-live="polite">متابعة كل 15 ثانية؛ بدون استدعاء الذكاء الاصطناعي.</p>
        <details id="pilot-orders" class="pilot-details" @if(request()->hasAny(['scope','state','decision','product','reason','page'])) open @endif>
            <summary>سجل الطلبات والفلاتر <span>التغطية، القرارات، وحالة كل أوردر</span></summary>
            <div class="pilot-details-body">
        <form method="GET" class="pilot-filters">
            <label>نطاق الطلبات <select name="scope" class="rounded-lg border-gray-300"><option value="active">النشطة</option><option value="all" @selected(($filters['scope'] ?? '') === 'all')>كل الطلبات</option></select></label>
            <label>التقييم <select name="state" class="rounded-lg border-gray-300"><option value="">كل الحالات</option>@foreach($states as $value)<option value="{{ $value }}" @selected(($filters['state'] ?? '') === $value)>{{ __('agentic.states.'.$value) }}</option>@endforeach</select></label>
            <label>آخر اقتراح <select name="decision" class="rounded-lg border-gray-300"><option value="">الكل</option>@foreach(\App\Services\Agentic\DecisionValidator::TYPES as $value)<option value="{{ $value }}" @selected(($filters['decision'] ?? '') === $value)>{{ __('agentic.decisions.'.$value) }}</option>@endforeach</select></label>
            <label>المنتج <select name="product" class="rounded-lg border-gray-300"><option value="">كل المنتجات</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected(($filters['product'] ?? '') == $product->id)>{{ $product->name_ar }}</option>@endforeach</select></label>
            <button class="pilot-button">تصفية</button>
        </form>

                @if($filters['reason'] ?? null)<p class="pilot-filter-note">سبب محدد: {{ __('agentic.reasons.'.$filters['reason']) }} · <a href="{{ route('admin.agentic.index') }}#pilot-orders">إلغاء الفلاتر</a></p>@endif
                <p id="agentic-refresh" class="pilot-refresh" aria-live="polite">جدول الطلبات يتحدث كل 30 ثانية أثناء عرضه.</p>
                <section id="agentic-overview" data-url="{{ route('admin.agentic.monitor', request()->only('scope','state','decision','reason','product','page')) }}">@include('admin.agentic._overview')</section>
            </div>
        </details>
    </main>
    <script>
    (() => {
        function reveal() {
            const target = document.getElementById(location.hash.slice(1));
            if (target && target.tagName === 'DETAILS') target.open = true;
        }
        window.addEventListener('hashchange', reveal);
        document.addEventListener('click', event => {
            const link = event.target.closest('a[href^="#pilot-"]');
            if (link) { const target = document.getElementById(link.hash.slice(1)); if (target) target.open = true; }
        });
        reveal();
        function poll(rootId, noteId, interval) {
            const root = document.getElementById(rootId), note = document.getElementById(noteId);
            let busy = false;
            setInterval(async () => {
                // Keep an open audit/stop form stable while staff reads or uses it.
                if (root.querySelector('details[open]')) { note.textContent = 'التحديث متوقف مؤقتًا أثناء قراءة سجل التنفيذ؛ أغلق السجل لاستئنافه.'; return; }
                if (document.hidden || busy || root.contains(document.activeElement) || (rootId === 'agentic-overview' && !document.getElementById('pilot-orders').open)) return;
                busy = true;
                try {
                    const response = await fetch(root.dataset.url, {headers: {'Accept': 'application/json'}, signal: AbortSignal.timeout(15000)});
                    if (!response.ok) throw new Error('refresh');
                    const data = await response.json(); root.innerHTML = data.html;
                    note.dataset.error = 'false';
                    note.textContent = 'آخر تحديث: ' + new Date().toLocaleTimeString('ar-EG', {timeZone:'Africa/Cairo'}) + ' · القاهرة';
                } catch { note.dataset.error = 'true'; note.textContent = 'تعذر التحديث؛ البيانات المعروضة من آخر قراءة ناجحة. أعد تحميل الصفحة للمحاولة مجددًا.'; }
                finally { busy = false; }
            }, interval);
        }
        poll('autopilot-activity', 'autopilot-activity-refresh', 15000);
        poll('agentic-overview', 'agentic-refresh', 30000);
    })();
    </script>
</x-admin-layout>
