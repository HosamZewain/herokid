// Preserve DOM order, selection and open editors. Never replace the table with a response.
export const membershipChanged = (before, after, oldTotal, total) => oldTotal !== total || before.join(',') !== after.join(',');
const root = typeof document === 'undefined' ? null : document.querySelector('[data-orders-workspace]');
if (root) {
    const all = (selector) => [...document.querySelectorAll(selector)];
    const initialIds = [...new Set(all('[data-assignment-block]').map(el => Number(el.dataset.assignmentBlock)))];
    all('[data-assignment-block]').forEach(el=>{ const row=el.closest('tr') || el.closest('[data-order-card]'); if(row) row.dataset.orderWorkspaceRow=el.dataset.assignmentBlock; });
    const tones = {intervention:'border-red-300 bg-red-50 text-red-900',running:'border-blue-200 bg-blue-50 text-blue-900',waiting:'border-amber-200 bg-amber-50 text-amber-900',queued:'border-gray-200 bg-gray-50 text-gray-700',idle:'border-gray-200 bg-gray-50 text-gray-700'};
    let busy = false, stopped = false, timer;
    const text = (parent, tag, value, className = '') => { const el = document.createElement(tag); el.textContent = value || ''; el.className = className; parent.append(el); return el; };
    const render = (container,s) => {
        const open = container.querySelector('details')?.open;
        container.replaceChildren(); container.className = `rounded-xl border p-3 text-xs ${tones[s.state] || tones.idle}`; container.dataset.state = s.state;
        text(container,'p',`${s.state === 'intervention' ? '⚠' : s.tracking ? '🤖' : '👤'} ${s.label}`,'font-black');
        if(s.reason) text(container,'p',s.reason,'mt-1 break-words');
        if(s.running && s.state === 'intervention') text(container,'p','يوجد توليد جارٍ أيضًا؛ انتظر تأكيد الإيقاف قبل التعديل.','mt-1 font-bold text-blue-800');
        const details = document.createElement('details'); details.open = !!open; details.className = 'mt-2'; container.append(details);
        text(details,'summary',`تفاصيل المراحل (${s.parts.length})`,'cursor-pointer font-bold');
        for(const p of s.parts) { const item=text(details,'div',`${p.child || ''} — ${p.label}`,'mt-2 border-t pt-2'); if(p.reason) text(item,'p',p.reason); if(p.total) {const progress=document.createElement('progress');progress.max=p.total;progress.value=p.done;progress.className='mt-1 w-full';progress.setAttribute('aria-label',p.label);item.append(progress);} }
        text(container,'p',`آخر نشاط: ${s.updated_at || '—'}`,'mt-2 text-[10px]');
    };
    const poll = async () => {
        if(document.hidden || busy || stopped) return;
        busy = true;
        const abort = new AbortController(); const timeout=setTimeout(()=>abort.abort(),8000);
        try {
            const url=new URL(root.dataset.progressUrl,location.origin);url.search=location.search;
            initialIds.forEach(id=>url.searchParams.append('visible[]',id));
            const response=await fetch(url,{headers:{Accept:'application/json'},signal:abort.signal,cache:'no-store'});
            if([401,403,419].includes(response.status)) {stopped=true; all('[data-bulk-order-checkbox]').forEach(el=>{el.checked=false;el.disabled=true;el.dispatchEvent(new Event('change',{bubbles:true}));}); all('[data-order-workspace-row]').forEach(el=>el.remove()); throw new Error('access');}
            if(!response.ok) throw new Error('refresh');
            const data=await response.json();
            for(const id of data.revoked) {
                all(`[data-bulk-order-checkbox][value="${id}"]`).forEach(el=>{el.checked=false;el.disabled=true;el.dispatchEvent(new Event('change',{bubbles:true}));});
                all(`[data-order-workspace-row="${id}"], [data-workflow-panel-row="${id}"]`).forEach(el=>el.remove());
            }
            for(const row of data.rows) {
                all(`[data-automation-summary="${row.id}"]`).forEach(el=>render(el,row.summary));
                all(`[data-assignee-name="${row.id}"]`).forEach(el=>el.textContent=row.assignee);
                all(`[data-order-robot="${row.id}"]`).forEach(el=>el.textContent=row.summary.tracking?'🤖 ':'');
                all(`[data-assignment-block="${row.id}"]`).forEach(el=>{if(Number(el.dataset.assignmentVersion)!==row.summary.assignment_version) root.querySelector('[data-workspace-refresh]').hidden=false;});
                all(`[data-order-workspace-row="${row.id}"]`).forEach(parent=>{
                    for(const [field,badge] of Object.entries(row.badges || {})) parent.querySelectorAll(`[data-workflow-badge="${field}"]`).forEach(el=>{
                        el.textContent=badge.label;el.className=`inline-flex rounded-full px-2.5 py-1 text-[11px] font-black ${badge.color}`;
                    });
                });
                // Keep conflict tokens in forms unchanged; a stale submission must be rejected.
            }
            for(const [key,count] of Object.entries(data.counts)) all(`[data-workspace-count="${key}"]`).forEach(el=>el.textContent=count);
            if(membershipChanged(initialIds,data.ids,Number(root.dataset.total),data.total)) root.querySelector('[data-workspace-refresh]').hidden=false;
            root.querySelector('[data-workspace-updated]').textContent=`آخر تحديث ${new Date().toLocaleTimeString('ar-EG')}`;
        } catch {root.querySelector('[data-workspace-updated]').textContent=stopped?'انتهت صلاحية الوصول؛ حدّث الصفحة.':'تعذر التحديث؛ سنحاول مجددًا.';}
        finally {clearTimeout(timeout);busy=false;}
    };
    const schedule=()=>{clearInterval(timer);if(!document.hidden && !stopped) timer=setInterval(poll,10000);};
    document.addEventListener('visibilitychange',()=>{schedule();if(!document.hidden) poll();});schedule();
    document.querySelector('[data-workspace-bulk]')?.addEventListener('submit',event=>{
        const form=event.currentTarget, payload=form.querySelector('[data-assignment-payload]');payload.replaceChildren();
        const ids=[...new Set(all('[data-bulk-order-checkbox]:checked').filter(e=>!e.disabled).map(e=>e.value))];
        if(!ids.length) {event.preventDefault();alert('حدد طلبًا واحدًا على الأقل.');return;}
        ids.forEach((id,index)=>{ const row=document.querySelector(`[data-assignment-block="${id}"]`); for(const [field,value] of Object.entries({id,assignee:row.dataset.assigneeId,version:row.dataset.assignmentVersion})) {const input=document.createElement('input');input.type='hidden';input.name=`orders[${index}][${field}]`;input.value=value;payload.append(input);} });
    });
}
