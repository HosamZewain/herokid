<div>
    <div id="autopilot-order-panel" data-url="{{ route('admin.orders.autopilot-monitor',$order) }}" aria-live="polite">@include('admin.orders._autopilot')</div>
    <p id="autopilot-order-refresh" class="mt-2 text-sm text-gray-600">متابعة Auto Pilot تتحدث كل 15 ثانية دون استدعاء الذكاء الاصطناعي.</p>
</div>
<script>
(() => {
    const root = document.getElementById('autopilot-order-panel'), note = document.getElementById('autopilot-order-refresh');
    let busy = false;
    setInterval(async () => {
        if (document.hidden || busy) return;
        busy = true;
        try {
            const response = await fetch(root.dataset.url, {headers: {'Accept': 'application/json'}, signal: AbortSignal.timeout(10000)});
            if (!response.ok) throw new Error('refresh');
            const data = await response.json(), expanded = root.querySelector('details')?.open;
            root.innerHTML = data.html;
            if (expanded && root.querySelector('details')) root.querySelector('details').open = true;
            note.textContent = 'آخر تحديث: ' + new Date().toLocaleTimeString('ar-EG');
        } catch { note.textContent = 'تعذر التحديث؛ المعروض من آخر قراءة ناجحة. حدّث الصفحة للمحاولة مجددًا.'; }
        finally { busy = false; }
    }, 15000);
})();
</script>
