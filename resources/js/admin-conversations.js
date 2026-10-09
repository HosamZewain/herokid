import '../css/admin-conversations.css';
import { mergeMessages, safeAttachmentUrl, shouldPoll, normalizeConversationWindows, readConversationWindows, writeConversationWindows, shortContactName, safeOrderUrl, replyWindowState } from './conversation-state';

export function initializeOrderConversations() {
    const dock = document.querySelector('[data-conversation-dock]');
    const template = document.getElementById('hk-conversation-template');
    const rail = document.querySelector('[data-conversation-bubbles]');
    const bubbleTemplate = document.getElementById('hk-conversation-bubble-template');
    if (!dock || !template || !rail || !bubbleTemplate) return;
    const windows = new Map();
    const tooltip = document.createElement('div'); tooltip.className = 'hk-chat-bubble-tooltip'; tooltip.hidden = true; tooltip.dir = 'rtl';
    document.body.append(tooltip);
    const updateRail = () => {
        rail.hidden = ![...windows.values()].some(state => state.minimized);
        dock.classList.toggle('has-minimized', !rail.hidden);
        tooltip.hidden = true;
    };
    let storage;
    try { storage = window.localStorage; } catch { /* The viewer still works without persistence. */ }
    const storageKey = dock.dataset.windowStorageKey;
    const persist = () => writeConversationWindows(storage, storageKey, [...windows.values()].map(({ orderId, minimized }) => ({ orderId, minimized })));
    const dates = new Intl.DateTimeFormat('ar-EG', { timeZone:'Africa/Cairo', day:'numeric', month:'long', year:'numeric' });
    const times = new Intl.DateTimeFormat('ar-EG', { timeZone:'Africa/Cairo', hour:'numeric', minute:'2-digit' });
    const states = { read:'تمت القراءة', delivered:'تم التسليم', sent:'أُرسلت', pending:'في الانتظار', failed:'تعذر الإرسال' };
    const el = (tag, text, className) => {
        const node = document.createElement(tag); node.textContent = text ?? ''; if (className) node.className = className; return node;
    };
    const selector = (state, name) => state.panel.querySelector(`[data-chat-${name}]`);
    function title(state, value) {
        state.title = value; selector(state, 'title').textContent = shortContactName(value); selector(state, 'title').title = value;
        state.panel.setAttribute('aria-label', `محادثة واتساب: ${value}`);
        state.bubble.querySelector('[data-chat-reopen]').setAttribute('aria-label', `فتح محادثة ${value}`);
        state.bubble.querySelector('[data-chat-bubble-close]').setAttribute('aria-label', `إغلاق محادثة ${value}`);
    }
    function preview(state) {
        const rect = state.bubble.getBoundingClientRect();
        const last = state.messages.at(-1);
        tooltip.replaceChildren(el('strong', state.title), el('p', last?.text || (last?.attachments?.length ? 'مرفق' : 'فتح المحادثة')));
        tooltip.hidden = false;
        tooltip.style.left = `${rect.right + 12}px`;
        tooltip.style.top = `${Math.max(8, Math.min(rect.top, window.innerHeight - tooltip.offsetHeight - 8))}px`;
    }
    function orderDetails(state, data) {
        const link = selector(state, 'order');
        const url = safeOrderUrl(data.order_url, window.location.origin);
        link.hidden = !url || typeof data.order_reference !== 'string';
        if (!link.hidden) { link.href = url; link.textContent = data.order_reference.replace(/^HK(?=\d{2}-)/, ''); link.title = `فتح الطلب ${data.order_reference}`; }
    }
    function render(state) {
        const list = selector(state, 'messages');
        const scroll = selector(state, 'scroll');
        const nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 90;
        const nodes = []; let day = '';
        for (const message of state.messages) {
            const date = new Date(message.date); const label = dates.format(date);
            if (label !== day) { nodes.push(el('p', label, 'hk-chat-day')); day = label; }
            const bubble = el('article', '', 'hk-chat-message'); bubble.dataset.direction = message.direction;
            if (message.employee_name || message.agent_name) bubble.append(el('p', message.employee_name || message.agent_name, 'hk-chat-agent'));
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
    async function load(state, { sync = false, full = false, before = null, allowMinimized = false } = {}) {
        if (state.closed || state.suspended || state.busy || (state.minimized && !allowMinimized)) return;
        state.busy = true;
        selector(state, 'refresh').disabled = true;
        state.abort = new AbortController();
        const requestedHistory = state.history;
        try {
            const url = new URL(sync ? state.sync : state.history, window.location.origin);
            if (before) url.searchParams.set('before', before);
            const response = await fetch(url, {
                method:sync ? 'POST' : 'GET', credentials:'same-origin', signal:state.abort.signal,
                headers:{ Accept:'application/json', ...(sync ? { 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content ?? '' } : {}) },
                ...(sync ? { body:JSON.stringify({ full:full ? 1 : 0 }) } : {}),
            });
            const data = await response.json();
            if (state.closed || state.suspended || state.history !== requestedHistory) return;
            if ([401, 403, 404].includes(response.status)) { close(state, false); return; }
            if (!response.ok) throw new Error(response.status === 403 ? 'ليس لديك صلاحية عرض المحادثات.' : (data.message || 'تعذر تحميل المحادثة. حاول مرة أخرى.'));
            const existing = [...windows.values()].find(item => item !== state && item.key === data.key && !item.closed);
            if (existing) {
                const expanded = !state.minimized;
                existing.abort?.abort(); windows.delete(existing.history);
                existing.history = state.history; existing.sync = state.sync; existing.orderId = state.orderId; existing.opener = state.opener ?? existing.opener;
                windows.set(existing.history, existing); close(state, false);
                if (typeof data.contact_title === 'string') title(existing, data.contact_title);
                existing.readEnabled ||= state.readEnabled;
                existing.readVersion = data.read_version; existing.configured = data.configured;
                existing.lastCustomerMessageAt = data.last_customer_message_at;
                existing.lastSyncedAt = data.last_synced_at;
                if (sync && !data.refresh_deferred) existing.syncFailed = false;
                existing.messages = mergeMessages(existing.messages, data.messages ?? []);
                render(existing); updateComposer(existing);
                orderDetails(existing, data); persist();
                if (expanded) restore(existing);
                void markRead(existing);
                return;
            }
            state.key = data.key; state.configured = data.configured;
            state.readVersion = data.read_version;
            state.lastCustomerMessageAt = data.last_customer_message_at;
            state.lastSyncedAt = data.last_synced_at;
            if (sync && !data.refresh_deferred) state.syncFailed = false;
            if (typeof data.contact_title === 'string') {
                title(state, data.contact_title);
            }
            orderDetails(state, data);
            state.messages = mergeMessages(state.messages, data.messages ?? []);
            if (before || !state.olderLoaded) { state.hasMore = data.has_more; state.before = data.older_before; }
            if (before) state.olderLoaded = true;
            const notice = selector(state, 'notice');
            notice.hidden = data.configured;
            notice.textContent = data.configured ? '' : 'قراءة المحادثات غير مفعّلة. أضف بيانات الربط من إعدادات RoboDesk.';
            selector(state, 'state').textContent = data.last_synced_at ? `آخر تحديث: ${times.format(new Date(data.last_synced_at))}` : 'لا توجد مزامنة سابقة';
            render(state);
            updateComposer(state); void markRead(state);
        } catch (error) {
            if (state.closed || error.name === 'AbortError') return;
            state.syncFailed = true;
            const notice = selector(state, 'notice'); notice.hidden = false;
            notice.textContent = error.message.includes('JSON') || error.name === 'TypeError' ? 'تعذر الاتصال. الرسائل المحفوظة ما زالت متاحة.' : error.message;
        } finally {
            state.busy = false; selector(state, 'refresh').disabled = false;
            updateComposer(state);
            if (state.refreshPending && !state.closed && !state.suspended) {
                state.refreshPending = false; void refresh(state);
            }
        }
    }
    function schedule(state) {
        clearTimeout(state.timer);
        if (state.closed || state.suspended || state.minimized || !state.configured) return;
        state.timer = setTimeout(async () => {
            if (shouldPoll(state, document.visibilityState)) await load(state, { sync:true });
            schedule(state);
        }, 15000);
    }
    function restore(state) {
        const wasMinimized = state.minimized;
        state.panel.hidden = false; state.bubble.hidden = true;
        state.minimized = false; selector(state, 'content').hidden = false;
        updateRail(); state.panel.scrollIntoView({ block:'nearest', inline:'nearest' }); persist(); schedule(state);
        if (wasMinimized) void refresh(state);
    }
    function close(state, focus = true) {
        state.closed = true; clearTimeout(state.timer); state.abort?.abort();
        if (windows.get(state.history) === state) windows.delete(state.history);
        if (state.imageUrl) URL.revokeObjectURL(state.imageUrl);
        state.panel.remove(); state.bubble.remove(); updateRail();
        persist();
        if (focus) state.opener?.focus();
    }
    async function refresh(state) {
        if (state.busy) { state.refreshPending = true; return; }
        await load(state, { allowMinimized:true });
        if (shouldPoll(state, document.visibilityState)) await load(state, { sync:true, full:true });
        schedule(state);
    }
    async function open(saved, button = null) {
        const [entry] = normalizeConversationWindows([saved]);
        if (!entry) return;
        const history = dock.dataset.historyTemplate.replace('__ORDER__', String(entry.orderId));
        if (windows.has(history)) { const state = windows.get(history); state.readEnabled = true; restore(state); void markRead(state); return; }
        const panel = template.content.firstElementChild.cloneNode(true);
        const bubble = bubbleTemplate.content.firstElementChild.cloneNode(true);
        const state = { panel, bubble, history, orderId:entry.orderId, sync:dock.dataset.syncTemplate.replace('__ORDER__', String(entry.orderId)),
            messages:[], opener:button, closed:false, suspended:false, busy:false, minimized:entry.minimized, readEnabled:Boolean(button) };
        windows.set(history, state); dock.append(panel); rail.append(bubble);
        title(state, button?.dataset.contactTitle || `محادثة طلب #${entry.orderId}`);
        bubble.querySelector('[data-chat-reopen]').onclick = () => { state.readEnabled = true; restore(state); void markRead(state); selector(state, 'close').focus(); };
        bubble.querySelector('[data-chat-bubble-close]').onclick = () => close(state);
        bubble.onmouseenter = () => preview(state); bubble.onmouseleave = () => { tooltip.hidden = true; };
        bubble.onfocusin = () => preview(state); bubble.onfocusout = () => { tooltip.hidden = true; };
        bubble.onkeydown = event => { if (event.key === 'Escape') close(state); };
        selector(state, 'close').onclick = () => close(state);
        selector(state, 'minimize').onclick = () => {
            if (state.minimized) { restore(state); return; }
            state.minimized = true; selector(state, 'content').hidden = true; clearTimeout(state.timer); state.abort?.abort();
            panel.hidden = true; bubble.hidden = false; updateRail();
            persist(); bubble.querySelector('[data-chat-reopen]').focus();
        };
        selector(state, 'refresh').onclick = async () => { await load(state, { sync:true, full:true }); schedule(state); };
        selector(state, 'older').onclick = async () => { await load(state, { before:state.before }); };
        panel.onkeydown = event => { if (event.key === 'Escape') close(state); };
        selector(state, 'content').hidden = state.minimized;
        panel.hidden = state.minimized; bubble.hidden = !state.minimized; updateRail();
        panel.querySelector('.hk-conversation-header').onclick = event => {
            if (!event.target.closest('button, a')) selector(state, 'minimize').click();
        };
        panel.addEventListener('pointerdown', () => { state.readEnabled = true; void markRead(state); });
        panel.addEventListener('focusin', () => { state.readEnabled = true; void markRead(state); });
        initializeComposer(state);
        persist();
        if (button) { panel.scrollIntoView({ block:'nearest', inline:'nearest' }); selector(state, 'close').focus(); }
        await refresh(state);
    }
    const saved = readConversationWindows(storage, storageKey);
    for (const entry of saved) void open(entry);
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-order-conversation]');
        if (button) void open({ orderId:Number(button.dataset.orderId), minimized:false }, button);
    });
    document.addEventListener('submit', event => {
        if (event.target.action !== dock.dataset.logoutUrl) return;
        for (const state of [...windows.values()]) close(state, false);
    });
    window.addEventListener('pagehide', () => {
        for (const state of windows.values()) {
            state.suspended = true; clearTimeout(state.timer); state.abort?.abort();
        }
    });
    window.addEventListener('pageshow', event => {
        if (!event.persisted) return;
        for (const state of windows.values()) { state.suspended = false; void refresh(state); }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') return;
        for (const state of windows.values()) {
            if (shouldPoll(state, document.visibilityState)) { void load(state, { sync:true }); schedule(state); }
        }
    });
    rail.addEventListener('scroll', () => { tooltip.hidden = true; });
    window.addEventListener('resize', () => { tooltip.hidden = true; });

    let notificationBusy = false; let notificationTimer;
    const notifications = document.querySelector('[data-chat-notifications]');
    async function updateNotifications() {
        if (!notifications || notificationBusy || document.visibilityState !== 'visible') return;
        notificationBusy = true;
        try {
            const response = await fetch(notifications.dataset.url, { credentials:'same-origin', headers:{Accept:'application/json'} });
            if (!response.ok) throw new Error('notifications');
            const data = await response.json();
            const count = notifications.querySelector('[data-chat-unread-count]'); count.textContent = String(data.count); count.hidden = data.count === 0;
            const toggle = notifications.querySelector('[data-chat-notifications-toggle]'); toggle.setAttribute('aria-label', `محادثات طلباتي غير المقروءة: ${data.count}`);
            notifications.querySelector('[data-chat-notifications-empty]').hidden = data.count > 0;
            const items = notifications.querySelector('[data-chat-notifications-items]');
            items.replaceChildren(...data.items.map(item => {
                const button = el('button', '', 'hk-chat-notification-item'); button.type = 'button';
                button.append(el('strong', `${shortContactName(item.contact_title || 'العميل')} · ${item.order_reference}`), el('span', 'رسالة جديدة — اضغط لفتح المحادثة'));
                button.onclick = () => {
                    notifications.querySelector('[data-chat-notifications-menu]').hidden = true; toggle.setAttribute('aria-expanded', 'false');
                    void open({orderId:item.order_id, minimized:false}, button);
                }; return button;
            }));
            notifications.querySelector('[data-chat-notifications-error]').hidden = true;
        } catch {
            notifications.querySelector('[data-chat-notifications-error]').hidden = false;
        } finally { notificationBusy = false; }
    }
    function pollNotifications() {
        clearTimeout(notificationTimer);
        notificationTimer = setTimeout(async () => { await updateNotifications(); pollNotifications(); }, 30000);
    }
    notifications?.querySelector('[data-chat-notifications-toggle]').addEventListener('click', async () => {
        const menu = notifications.querySelector('[data-chat-notifications-menu]'); menu.hidden = !menu.hidden;
        notifications.querySelector('[data-chat-notifications-toggle]').setAttribute('aria-expanded', String(!menu.hidden));
        if (!menu.hidden) await updateNotifications();
    });
    notifications?.addEventListener('keydown', event => { if (event.key === 'Escape') { notifications.querySelector('[data-chat-notifications-menu]').hidden = true; notifications.querySelector('[data-chat-notifications-toggle]').setAttribute('aria-expanded', 'false'); notifications.querySelector('[data-chat-notifications-toggle]').focus(); } });
    document.addEventListener('click', event => { if (notifications && !notifications.contains(event.target)) { notifications.querySelector('[data-chat-notifications-menu]').hidden = true; notifications.querySelector('[data-chat-notifications-toggle]').setAttribute('aria-expanded', 'false'); } });
    window.addEventListener('pagehide', () => clearTimeout(notificationTimer));
    window.addEventListener('pageshow', event => { if (event.persisted) { void updateNotifications(); pollNotifications(); } });
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') void updateNotifications(); });
    void updateNotifications(); pollNotifications();

    async function markRead(state) {
        if (!state.readEnabled || state.closed || state.suspended || state.minimized || document.visibilityState !== 'visible' || !Number.isSafeInteger(state.readVersion) || !state.readVersion || state.markedVersion >= state.readVersion || state.markingRead) return;
        state.markingRead = true; const version = state.readVersion;
        try {
            const response = await fetch(dock.dataset.readTemplate.replace('__ORDER__', state.orderId), {method:'POST', credentials:'same-origin',
                headers:{Accept:'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content ?? ''}, body:JSON.stringify({version})});
            if (response.ok) { state.markedVersion = version; void updateNotifications(); }
        } catch { /* A failed acknowledgement must leave the conversation unread. */ }
        finally { state.markingRead = false; }
    }
    function updateComposer(state) {
        const composer = selector(state, 'composer'); if (!composer) return;
        const windowState = replyWindowState(state);
        state.canReply = windowState === 'open';
        selector(state, 'reply-window').textContent = {
            loading:'جارٍ تحميل المحادثة والتحقق من إمكانية الرد…',
            setup_required:'فعّل ربط المحادثات أولاً.',
            sync_required:'حدّث المحادثة للتحقق من إمكانية الرد.',
            sync_failed:'تعذر التحقق من إمكانية الرد بسبب فشل تحديث المحادثة. اضغط تحديث.',
            no_customer_message:'لا توجد رسالة واردة من العميل في المحادثة المحمّلة؛ يلزم قالب واتساب (قريباً).',
            unverified:'تعذر التحقق من وقت آخر رسالة. حدّث المحادثة.',
            open:'يمكنك الرد بنص أو صورة · الإرسال باسمك',
            closed:'مهلة الرد انتهت — يلزم قالب واتساب (قريباً).',
        }[windowState];
        selector(state, 'send').disabled = !state.canReply || state.sending || state.uncertain;
    }
    function initializeComposer(state) {
        const composer = selector(state, 'composer'); if (!composer) return;
        const text = selector(state, 'reply-text'); const file = selector(state, 'image'); const emoji = selector(state, 'emojis');
        const error = selector(state, 'reply-error');
        const showError = value => { error.textContent = value; error.hidden = false; };
        for (const value of ['😊', '❤️', '👍', '🙏', '🎉', '🌟', '😍', '✅', '💛', '🌸', '👌', '🤗']) {
            const button = el('button', value); button.type = 'button'; button.setAttribute('aria-label', `إضافة ${value}`);
            button.onclick = () => { if (state.sending) return; const start = text.selectionStart; const end = text.selectionEnd;
                if (text.value.length - (end - start) + value.length > 4096) return;
                text.setRangeText(value, start, end, 'end'); text.focus(); emoji.hidden = true; selector(state, 'emoji').setAttribute('aria-expanded', 'false');
            }; emoji.append(button);
        }
        selector(state, 'emoji').onclick = () => { emoji.hidden = !emoji.hidden; selector(state, 'emoji').setAttribute('aria-expanded', String(!emoji.hidden)); };
        const clearImage = () => { file.value = ''; if (state.imageUrl) URL.revokeObjectURL(state.imageUrl); state.imageUrl = null; selector(state, 'image-preview').hidden = true; };
        selector(state, 'image-remove').onclick = clearImage;
        file.onchange = () => {
            if (state.imageUrl) URL.revokeObjectURL(state.imageUrl);
            const image = file.files[0];
            if (!image) { clearImage(); return; }
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(image.type) || image.size > 5 * 1024 * 1024) { clearImage(); showError('اختر صورة JPG أو PNG أو WebP حتى 5 ميجا.'); return; }
            state.imageUrl = URL.createObjectURL(image); selector(state, 'image-preview').querySelector('img').src = state.imageUrl; selector(state, 'image-preview').hidden = false;
        };
        composer.onsubmit = async event => {
            event.preventDefault(); updateComposer(state);
            if (!state.canReply || state.sending || state.uncertain) return;
            if (!text.value.trim() && !file.files[0]) { showError('اكتب رسالة أو أضف صورة.'); return; }
            const requestId = crypto.randomUUID(); const body = new FormData(); body.set('request_id', requestId); body.set('text', text.value);
            if (file.files[0]) body.set('image', file.files[0]);
            state.sending = true; selector(state, 'send').textContent = 'جارٍ الإرسال…'; updateComposer(state); error.hidden = true;
            text.disabled = true; file.disabled = true; selector(state, 'emoji').disabled = true; selector(state, 'image-remove').disabled = true;
            try {
                // Exactly one remote send. No timeout retry or optimistic fake bubble.
                const response = await fetch(dock.dataset.replyTemplate.replace('__ORDER__', state.orderId), {method:'POST', credentials:'same-origin',
                    headers:{Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content ?? ''}, body});
                const data = await response.json();
                if (!response.ok) {
                    state.uncertain = data.code === 'SEND_UNCERTAIN' || (response.status >= 500 && !['SEND_FAILED', 'INVALID_ATTACHMENT', 'PROVIDER_AUTHENTICATION'].includes(data.code));
                    showError(Object.values(data.errors ?? {}).flat()[0] || data.message || 'تعذر إرسال الرسالة.'); return;
                }
                state.messages = mergeMessages(state.messages, data.messages ?? []); render(state); text.value = ''; clearImage();
                selector(state, 'scroll').scrollTop = selector(state, 'scroll').scrollHeight;
            } catch { state.uncertain = true; showError('نتيجة الإرسال غير مؤكدة. حدّث المحادثة وتحقق قبل إرسال نفس الرسالة مجدداً.'); }
            finally {
                state.sending = false; selector(state, 'send').textContent = state.uncertain ? 'تحقق من الإرسال' : 'إرسال';
                text.disabled = false; file.disabled = false; selector(state, 'emoji').disabled = false; selector(state, 'image-remove').disabled = false;
                updateComposer(state);
            }
        };
    }
}
