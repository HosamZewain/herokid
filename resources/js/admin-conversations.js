import '../css/admin-conversations.css';
import { mergeMessages, safeAttachmentUrl, shouldPoll } from './conversation-state';

export function initializeOrderConversations() {
    const dock = document.querySelector('[data-conversation-dock]');
    const template = document.getElementById('hk-conversation-template');
    if (!dock || !template) return;
    const windows = new Map();
    const dates = new Intl.DateTimeFormat('ar-EG', { timeZone:'Africa/Cairo', day:'numeric', month:'long', year:'numeric' });
    const times = new Intl.DateTimeFormat('ar-EG', { timeZone:'Africa/Cairo', hour:'numeric', minute:'2-digit' });
    const states = { read:'تمت القراءة', delivered:'تم التسليم', sent:'أُرسلت', pending:'في الانتظار', failed:'تعذر الإرسال' };
    const el = (tag, text, className) => {
        const node = document.createElement(tag); node.textContent = text ?? ''; if (className) node.className = className; return node;
    };
    const selector = (state, name) => state.panel.querySelector(`[data-chat-${name}]`);
    function render(state) {
        const list = selector(state, 'messages');
        const scroll = selector(state, 'scroll');
        const nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 90;
        const nodes = []; let day = '';
        for (const message of state.messages) {
            const date = new Date(message.date); const label = dates.format(date);
            if (label !== day) { nodes.push(el('p', label, 'hk-chat-day')); day = label; }
            const bubble = el('article', '', 'hk-chat-message'); bubble.dataset.direction = message.direction;
            if (message.agent_name) bubble.append(el('p', message.agent_name, 'hk-chat-agent'));
            if (message.text) bubble.append(el('p', message.text));
            for (const attachment of message.attachments ?? []) {
                const url = safeAttachmentUrl(attachment.url);
                if (url) {
                    const link = el('a', attachment.name || 'عرض المرفق'); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer';
                    link.referrerPolicy = 'no-referrer'; bubble.append(link);
                    if (message.kind === 'image') {
                        const image = document.createElement('img'); image.src = url; image.alt = attachment.name || 'مرفق صورة';
                        image.loading = 'lazy'; image.referrerPolicy = 'no-referrer'; link.append(image);
                    }
                } else bubble.append(el('p', `${attachment.name || 'مرفق'} — حدّث المحادثة لإعادة فتحه`));
            }
            bubble.append(el('small', `${times.format(date)}${states[message.status] ? ` · ${states[message.status]}` : ''}`));
            nodes.push(bubble);
        }
        list.replaceChildren(...nodes);
        selector(state, 'empty').hidden = state.messages.length > 0;
        selector(state, 'older').hidden = !state.hasMore;
        if (nearBottom || !state.rendered) scroll.scrollTop = scroll.scrollHeight;
        state.rendered = true;
    }
    async function load(state, { sync = false, full = false, before = null } = {}) {
        if (state.closed || state.busy || state.minimized) return;
        state.busy = true;
        selector(state, 'refresh').disabled = true;
        state.abort = new AbortController();
        try {
            const url = new URL(sync ? state.sync : state.history, window.location.origin);
            if (before) url.searchParams.set('before', before);
            const response = await fetch(url, {
                method:sync ? 'POST' : 'GET', credentials:'same-origin', signal:state.abort.signal,
                headers:{ Accept:'application/json', ...(sync ? { 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content ?? '' } : {}) },
                ...(sync ? { body:JSON.stringify({ full:full ? 1 : 0 }) } : {}),
            });
            const data = await response.json();
            if (state.closed) return;
            if (!response.ok) throw new Error(response.status === 403 ? 'ليس لديك صلاحية عرض المحادثات.' : (data.message || 'تعذر تحميل المحادثة. حاول مرة أخرى.'));
            const existing = [...windows.values()].find(item => item !== state && item.key === data.key && !item.closed);
            if (existing) { close(state, false); restore(existing); return; }
            state.key = data.key; state.configured = data.configured;
            state.messages = mergeMessages(state.messages, data.messages ?? []);
            if (before || !state.olderLoaded) { state.hasMore = data.has_more; state.before = data.older_before; }
            if (before) state.olderLoaded = true;
            const notice = selector(state, 'notice');
            notice.hidden = data.configured;
            notice.textContent = data.configured ? '' : 'قراءة المحادثات غير مفعّلة. أضف بيانات الربط من إعدادات RoboDesk.';
            selector(state, 'state').textContent = data.last_synced_at ? `آخر تحديث: ${times.format(new Date(data.last_synced_at))}` : 'لا توجد مزامنة سابقة';
            render(state);
        } catch (error) {
            if (state.closed || error.name === 'AbortError') return;
            const notice = selector(state, 'notice'); notice.hidden = false;
            notice.textContent = error.message.includes('JSON') || error.name === 'TypeError' ? 'تعذر الاتصال. الرسائل المحفوظة ما زالت متاحة.' : error.message;
        } finally {
            state.busy = false; selector(state, 'refresh').disabled = false;
        }
    }
    function schedule(state) {
        clearTimeout(state.timer);
        if (state.closed || state.minimized || !state.configured) return;
        state.timer = setTimeout(async () => {
            if (shouldPoll(state, document.visibilityState)) await load(state, { sync:true });
            schedule(state);
        }, 15000);
    }
    function restore(state) {
        state.minimized = false; selector(state, 'content').hidden = false;
        selector(state, 'minimize').textContent = 'تصغير'; selector(state, 'minimize').setAttribute('aria-label', 'تصغير المحادثة');
        state.panel.scrollIntoView({ block:'nearest', inline:'nearest' }); schedule(state);
    }
    function close(state, focus = true) {
        state.closed = true; clearTimeout(state.timer); state.abort?.abort(); windows.delete(state.history); state.panel.remove();
        if (focus) state.opener.focus();
    }
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-order-conversation]');
        if (!button) return;
        const history = button.dataset.historyUrl;
        if (windows.has(history)) { restore(windows.get(history)); return; }
        const panel = template.content.firstElementChild.cloneNode(true);
        const state = { panel, history, sync:button.dataset.syncUrl, messages:[], opener:button, closed:false, busy:false, minimized:false };
        windows.set(history, state); dock.append(panel);
        selector(state, 'title').textContent = button.dataset.contactTitle;
        panel.setAttribute('aria-label', `محادثة واتساب: ${button.dataset.contactTitle}`);
        selector(state, 'close').onclick = () => close(state);
        selector(state, 'minimize').onclick = () => {
            if (state.minimized) { restore(state); return; }
            state.minimized = true; selector(state, 'content').hidden = true; clearTimeout(state.timer); state.abort?.abort();
            selector(state, 'minimize').textContent = 'فتح'; selector(state, 'minimize').setAttribute('aria-label', 'فتح المحادثة');
        };
        selector(state, 'refresh').onclick = async () => { await load(state, { sync:true, full:true }); schedule(state); };
        selector(state, 'older').onclick = async () => { await load(state, { before:state.before }); };
        panel.onkeydown = event => { if (event.key === 'Escape') close(state); };
        restore(state); selector(state, 'close').focus();
        await load(state);
        if (state.configured && !state.closed) await load(state, { sync:true, full:true });
        schedule(state);
    });
    window.addEventListener('pagehide', () => { for (const state of windows.values()) close(state, false); });
}
