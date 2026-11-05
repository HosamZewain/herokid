// No localStorage, HTML injection, arbitrary tools or client-supplied permission claims.
export function mountAdminAssistant(root) {
    if (!root || root.dataset.mounted) return;
    root.dataset.mounted = 'true';
    const dialog = root.querySelector('dialog');
    const launcher = root.querySelector('[data-assistant-open]');
    const messages = root.querySelector('[data-assistant-messages]');
    const form = root.querySelector('form');
    const input = root.querySelector('textarea');
    const submit = root.querySelector('[data-assistant-submit]');
    const status = root.querySelector('[data-assistant-status]');
    let signature = null, previous = null, busy = false, controller = null, epoch = 0;
    const headers = () => ({'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? ''});
    function clear(text = 'محادثة جديدة. اسأل عن أوردر أو تقرير متاح لك.') {
        epoch += 1; controller?.abort(); controller = null; previous = null; busy = false; submit.disabled = false;
        messages.replaceChildren(); input.value = ''; status.textContent = text;
    }
    async function access() {
        const response = await fetch(root.dataset.access, {headers: headers(), cache: 'no-store', credentials: 'same-origin'});
        if (!response.ok) { signature = null; clear('الوصول غير متاح. راجع تسجيل الدخول والصلاحيات.'); return false; }
        const data = await response.json();
        if (signature && signature !== data.signature) clear('تغيّرت صلاحياتك؛ تم مسح المحادثة السابقة.');
        signature = data.signature;
        return true;
    }
    function bubble(text, kind) {
        messages.querySelector('.assistant-welcome')?.remove();
        const article = document.createElement('article'); article.className = `assistant-message assistant-message--${kind}`;
        const p = document.createElement('p'); p.textContent = text; article.append(p); messages.append(article);
        messages.scrollTop = messages.scrollHeight; return article;
    }
    function result(data) {
        const article = bubble(data.text, 'answer');
        const list = document.createElement('dl');
        for (const fact of data.facts ?? []) {
            const row = document.createElement('div'), label = document.createElement('dt'), value = document.createElement('dd');
            label.textContent = fact.label; value.textContent = String(fact.value ?? 'غير متاح'); row.append(label, value); list.append(row);
        }
        article.append(list);
        const links = document.createElement('div'); links.className = 'assistant-sources';
        for (const source of data.sources ?? []) {
            const url = new URL(source.url, location.origin);
            if (url.origin !== location.origin || !url.pathname.startsWith('/admin/')) continue;
            const a = document.createElement('a'); a.href = url.href; a.textContent = source.label; links.append(a);
        }
        const time = document.createElement('small'); time.textContent = `قراءة: ${data.read_at} · لم يُنفّذ أي إجراء`;
        article.append(links, time); messages.scrollTop = messages.scrollHeight;
    }
    launcher.addEventListener('click', async () => {
        dialog.classList.remove('is-minimized');
        if (!dialog.open) dialog.show();
        launcher.setAttribute('aria-expanded', 'true');
        input.focus(); root.querySelector('[data-assistant-context]').textContent = document.querySelector('main h1, header h1')?.textContent?.trim() || 'الصفحة الحالية';
        status.textContent = 'جارٍ التحقق من صلاحياتك…';
        try {
            if (await access()) status.textContent = 'جاهز · تقدر تستخدم الصفحة وتنسخ منها والمساعد مفتوح';
        } catch { clear('تعذّر التحقق من الصلاحيات.'); }
    });
    root.querySelector('[data-assistant-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        dialog.classList.remove('is-minimized');
        launcher.setAttribute('aria-expanded', 'false');
        clear(); launcher.focus();
    });
    function minimize() {
        dialog.classList.add('is-minimized');
        launcher.setAttribute('aria-expanded', 'false');
        launcher.focus();
    }
    root.querySelector('[data-assistant-minimize]').addEventListener('click', minimize);
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); minimize(); }
    });
    root.querySelector('[data-assistant-reset]').addEventListener('click', () => { clear(); input.focus(); });
    root.querySelectorAll('[data-assistant-suggest]').forEach(button => button.addEventListener('click', () => { input.value = button.dataset.assistantSuggest; input.focus(); }));
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy || !input.value.trim()) return;
        busy = true; submit.disabled = true;
        try {
            if (!await access()) return;
            const question = input.value.trim(); input.value = ''; bubble(question, 'question');
            const turnEpoch = epoch; controller = new AbortController();
            status.textContent = 'بفهم سؤالك وبراجع المصدر المسموح…';
            const response = await fetch(root.dataset.ask, {method: 'POST', headers: headers(), credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                body: JSON.stringify({question, signature, previous, path: location.pathname, query: Object.fromEntries(new URLSearchParams(location.search))})});
            if (turnEpoch !== epoch) return;
            if ([401,403,419].includes(response.status)) { signature = null; clear('انتهت الجلسة أو تغيّرت الصلاحيات. افتح المساعد مجددًا.'); return; }
            const data = await response.json();
            if (!response.ok) { bubble(response.status === 422 ? 'السؤال أو الفترة يحتاج توضيحًا. اكتب رقم أوردر صحيحًا أو فترة محددة.' : (data.message || 'تعذرت القراءة. لم يتم تنفيذ أي إجراء.'), 'answer'); return; }
            // Fresh server authorization after the read and once again before displaying it.
            if (!await access() || turnEpoch !== epoch || data.signature !== signature) return;
            previous = data.previous; result(data);
        } catch (error) {
            if (error.name !== 'AbortError') bubble('تعذّر الاتصال بالمساعد. لم يتم تنفيذ أي إجراء؛ تقدر تتابع من صفحات النظام.', 'answer');
        } finally { busy = false; submit.disabled = false; if (signature) status.textContent = 'قراءة وتشخيص فقط · المحادثة لا تُحفظ بعد إغلاقها'; }
    });
    // Hide retained browser text when the user leaves; reauthorize on return/open.
    document.addEventListener('visibilitychange', () => { if (document.hidden) clear(); else if (dialog.open) access().catch(() => clear('تعذّر التحقق من الصلاحيات.')); });
    setInterval(() => { if (dialog.open && !document.hidden && !busy) access().catch(() => clear('تعذّر التحقق من الصلاحيات.')); }, 15000);
}
if (typeof document !== 'undefined') mountAdminAssistant(document.querySelector('[data-admin-assistant]'));
