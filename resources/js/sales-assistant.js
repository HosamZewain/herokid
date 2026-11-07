import { shouldPoll, trustedFrameMessage, publicUrl, terminalMessage, newClientNonce } from './sales-assistant-state.js';

const root = document.querySelector('[data-sales-assistant]');
if (root) {
    const q = (selector) => root.querySelector(selector);
    const panel = q('[data-panel]'), frame = q('[data-purchase-frame]');
    const composer = q('[data-compose]'), input = composer.querySelector('textarea');
    // Use the actual storefront header and visual viewport, including the mobile keyboard.
    if (root.dataset.full === '1') {
        const fitPage = () => {
            const viewport = window.visualViewport;
            const top = Math.max(0, root.getBoundingClientRect().top - (viewport?.offsetTop || 0));
            panel.style.height = `${Math.max(160, (viewport?.height || window.innerHeight) - top)}px`;
        };
        fitPage();
        const header = document.querySelector('.hk-site-header');
        if (header && typeof ResizeObserver !== 'undefined') new ResizeObserver(fitPage).observe(header);
        window.addEventListener('resize', fitPage);
        window.visualViewport?.addEventListener('resize', fitPage);
        window.visualViewport?.addEventListener('scroll', fitPage);
        window.addEventListener('pageshow', fitPage);
    }

    let open = root.dataset.full === '1', state = null, timer = null, polling = false, busy = false, previousSignature = '', nonce = null, lastText = null;
    let refreshQueued = false;
    let refreshPromise = null, privateStep = null, privateSignature = '', approvedContact = {};
    let abortPoll = null, upsellKey = '', returnFocus = null, interest = '', restoredFrame = false;
    const element = (tag, className, text) => { const e = document.createElement(tag); if (className) e.className = className; if (text) e.textContent = text; return e; };
    const notice = (message = '') => { q('[data-notice]').textContent = message; q('[data-notice]').hidden = !message; };
    const storageGet = (key) => { try { return sessionStorage.getItem(key); } catch { return null; } };
    const storageSet = (key, value) => { try { sessionStorage.setItem(key, value); } catch {} };
    async function api(url, data = {}, signal) {
        const response = await fetch(url, { method: 'POST', credentials: 'same-origin', signal, headers: {
            Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        }, body: JSON.stringify(data) });
        if (response.status === 204) return {};
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            const message = response.status === 419 ? 'جلسة الصفحة انتهت. حدّث الصفحة لاستكمال الطلب بأمان.' : Object.values(result.errors || {}).flat()[0] || result.message || 'تعذر الاتصال. جرّب مرة تانية أو كمل من السلة.';
            throw new Error(message);
        }
        return result;
    }
    function pending() { return state?.turns.some(t => ['queued', 'processing'].includes(t.status)) || false; }
    function stopPolling() { clearTimeout(timer); timer = null; abortPoll?.abort(); }
    function schedulePoll() {
        clearTimeout(timer);
        if (shouldPoll({ open, hidden: document.hidden, pending: pending(), working: polling })) timer = setTimeout(() => refresh(), 4000);
    }
    function refresh() {
        if (refreshPromise) {refreshQueued=true;return refreshPromise;}
        if (!open || document.hidden) return Promise.resolve();
        polling = true; abortPoll = new AbortController();
        refreshPromise = (async () => {
            try {
                const next = await api(root.dataset.state, {}, abortPoll.signal);
                const changedConversation = state && state.conversation !== next.conversation;
                state = next;
                if (changedConversation) { privateStep=null;privateSignature='';approvedContact={};frame.removeAttribute('src'); q('[data-workspace]').hidden = true; q('[data-chat-view]').hidden = false; notice('بدأت جلسة جديدة. راجع السلة والصور قبل التأكيد.'); }
                render();
                if(!restoredFrame && root.dataset.full==='1') {restoredFrame=true;const saved=storageGet(`hk-sales-frame:${state.conversation}`);if(saved && !saved.includes('/checkout/success'))purchase(saved);}
            } catch (error) { if (error.name !== 'AbortError') notice(error.message); }
            finally { polling = false; refreshPromise = null; if(refreshQueued){refreshQueued=false;queueMicrotask(refresh);}else schedulePoll(); }
        })();
        return refreshPromise;
    }
    function render() {
        q('[data-cart-count]').textContent = state.cart.line_count;
        q('.hk-sales-welcome').hidden = state.turns.length > 0;
        q('[data-send]').disabled = busy || pending();
        const signature = JSON.stringify(state.turns);
        if (signature !== previousSignature) {
            const thread = q('[data-thread]'); thread.replaceChildren();
            for (const turn of state.turns) {
                thread.append(element('div', 'hk-sales-message hk-sales-message--user', turn.question));
                const complete = turn.status === 'completed';
                thread.append(element('div', `hk-sales-message ${complete ? '' : ['queued','processing'].includes(turn.status) ? 'hk-sales-message--pending':'hk-sales-message--error'}`,
                    complete ? turn.result?.message : terminalMessage(turn.status)));
                if (complete && turn.result?.cards?.length) thread.append(cards(turn.result.cards));
            }
            previousSignature = signature;
            requestAnimationFrame(() => thread.lastElementChild?.scrollIntoView({block:'start',behavior:'instant'}));
        }
        if (!state.chat_available) notice('الردود الذكية مش متاحة حاليًا؛ البحث والاختيار والسلة شغالين.');
        renderPrivateDetails();
        if (state.upsell_declined) q('[data-upsells]').hidden = true;
    }
    function cards(items) {
        const list = element('div', 'hk-sales-cards');
        for (const item of items.slice(0, 3)) {
            const article = element('article', 'hk-sales-card');
            const imageUrl = item.image && publicUrl(item.image, location.origin, true);
            if (imageUrl) { const img = element('img'); img.src = imageUrl; img.alt = item.title; img.loading = 'lazy'; img.width = 84; img.height = 110; article.append(img); }
            else article.append(element('div','hk-sales-card-placeholder','✦'));
            const body = element('div'); body.append(element('h3','',item.title),element('p','hk-sales-card-reason',item.reason),
                element('p','hk-sales-card-meta', item.age ? `العمر المناسب: ${item.age}` : 'راجع التفاصيل حسب عمر طفلك'),
                element('p','hk-sales-price',`${new Intl.NumberFormat('ar-EG',{maximumFractionDigits:2}).format(item.price_cents/100)} ج.م${item.price_from ? ' (يبدأ من)':''}`));
            if (item.offer_applied && item.regular_price_cents > item.price_cents) {
                const regular = element('del','hk-sales-regular-price',`${new Intl.NumberFormat('ar-EG',{maximumFractionDigits:2}).format(item.regular_price_cents/100)} ج.م`);
                regular.setAttribute('aria-label','السعر قبل العرض');
                body.insertBefore(regular,body.querySelector('.hk-sales-price'));
                body.append(element('p','hk-sales-offer',item.offer_label || 'عرض على الاختيار المعروض'));
            }
            if (item.requires_story) body.append(element('p','hk-sales-card-meta','يحتاج قصة مرتبطة في السلة'));
            const actions = element('div','hk-sales-card-actions');
            const choose = element('button','','اختيار وتخصيص'); choose.type = 'button';
            choose.addEventListener('click', () => purchase(`${root.dataset.itemBase}/${item.kind}/${item.id}?_sales_assistant_embed=1&_sales_entry=${newClientNonce()}`,1));
            actions.append(choose);
            const url = publicUrl(item.url,location.origin);
            if (url) { const preview = element('a','','معاينة التفاصيل ↗'); preview.href = url; preview.target = '_blank'; preview.rel = 'noopener noreferrer'; preview.addEventListener('click',()=>api(root.dataset.event,{type:'preview',key:item.key}).catch(()=>{})); actions.append(preview); }
            article.append(body,actions); list.append(article);
        }
        return list;
    }
    function step(number) { root.querySelectorAll('[data-step]').forEach((e,i)=> { if(i===number)e.setAttribute('aria-current','step');else e.removeAttribute('aria-current'); }); }
    function purchase(url, stage = 2) {
        let safe = publicUrl(url,location.origin); if(!safe) return;
        const destination=new URL(safe);
        if(destination.pathname.startsWith('/shopping-assistant/item/') && !destination.searchParams.has('_sales_entry')){destination.searchParams.set('_sales_entry',newClientNonce());safe=destination.href;}
        if(!/^\/(?:shopping-assistant\/(?:item\/(?:story|product|package)\/[1-9][0-9]*|cart-edit)|cart|checkout\/success|stories\/[^/]+|shop\/(?:product|package)\/[^/]+)$/.test(destination.pathname))safe=root.dataset.cart;
        q('[data-workspace]').hidden = false; q('[data-chat-view]').hidden = false; privateStep=null;step(stage);
        q('[data-workspace]').scrollIntoView({block:'start',behavior:'instant'});
        if(state)storageSet(`hk-sales-frame:${state.conversation}`,safe);
        if(frame.src!==safe) frame.src=safe;
        q('[data-frame-status]').textContent='بنجهّز الخطوة التالية…';
    }
    q('[data-chat]').addEventListener('click',()=> { q('[data-workspace]').hidden=true;q('[data-chat-view]').hidden=false;privateStep=null;if(state)storageSet(`hk-sales-frame:${state.conversation}`,'');step(0);input.focus();refresh(); });
    q('[data-reload-frame]').addEventListener('click',()=> { if(frame.src) frame.contentWindow.location.reload(); });
    q('[data-cart-button]').addEventListener('click',()=>purchase(root.dataset.cart));
    frame.addEventListener('load',()=> {
        try {
            const doc = frame.contentDocument;
            if(state && frame.contentWindow.location.origin===location.origin)storageSet(`hk-sales-frame:${state.conversation}`,frame.contentWindow.location.href);
            if(frame.contentWindow.location.pathname==='/cart' && typeof frame.contentWindow.applySalesDetails==='function')frame.contentWindow.applySalesDetails(approvedContact);
            const stage = doc.querySelector('[data-sales-review]') ? 3 : frame.contentWindow.location.pathname === '/checkout/success' ? 3 : frame.contentWindow.location.pathname === '/cart' ? 2 : 1;
            step(stage); q('[data-frame-status]').textContent=['اختيار','بيانات المنتج والصور','السلة وعنوان الاستلام','مراجعة وتأكيد'][stage];
        } catch { notice('افتح السلة من الرابط العادي لاستكمال الطلب.'); }
    });
    window.addEventListener('message',event=> {
        if (!trustedFrameMessage(event,frame,location.origin)) return;
        if(['cart','added','complete'].includes(event.data.stage)) refresh();
        if(event.data.stage==='question') {
            privateStep=['child_name','child_age','child_gender','parent_name','phone','street'].includes(event.data.field)?event.data.field:null;
            q('[data-workspace]').scrollIntoView({block:'nearest',behavior:'instant'});
        }
        if(event.data.stage==='resize' && Number.isFinite(event.data.height)) frame.style.height=`${Math.max(180,Math.min(2400,event.data.height))}px`;
        if(event.data.stage==='added') {privateStep=null;notice('اتضاف اختيارك. نكمّل بيانات التوصيل هنا، أو اختار «إضافة اختيار آخر».');offerUpsells();}

        if(event.data.stage==='complete') { notice('طلبك اتسجل. رقم الطلب وحالته ظاهرين في صفحة التأكيد.'); if(state)storageSet(`hk-sales-frame:${state.conversation}`,''); }
    });
    function renderPrivateDetails() {
        const draft=state?.private_details || {}, signature=JSON.stringify(draft);
        if(signature===privateSignature)return; privateSignature=signature;
        const box=q('[data-private-draft]');box.replaceChildren();box.hidden=!Object.keys(draft).length;
        if(box.hidden)return;
        box.append(element('h3','','راجع البيانات اللي فهمتها'));
        box.append(element('p','hk-sales-card-meta','مسودة خاصة. استخدامها للطفل الحالي باختيارك، والطلب هيتسجل بعد المراجعة والتأكيد.'));
        const names={child_name:'اسم الطفل',child_age:'عمر الطفل',child_gender:'نوع الطفل',parent_name:'اسم ولي الأمر',phone:'رقم الهاتف',street:'الشارع ورقم العقار'};
        const controls={};
        for(const [key,value] of Object.entries(draft)) {
            if(!names[key])continue;
            const label=element('label','',names[key]),control=element(key==='child_gender'?'select':'input');
            if(key==='child_gender'){for(const [v,t] of [['boy','ولد'],['girl','بنت']]){const option=element('option','',t);option.value=v;control.append(option);}}
            else control.type=key==='phone'?'tel':key==='child_age'?'number':'text';
            control.value=String(value);control.dataset.privateField=key;if(key==='phone')control.dir='ltr';label.append(control);controls[key]=control;box.append(label);
        }
        const apply=element('button','','استخدم البيانات دي في الخطوة الحالية');apply.type='button';
        apply.addEventListener('click',()=> {
            try {
                if(q('[data-workspace]').hidden || typeof frame.contentWindow.applySalesDetails!=='function'){notice('اختار القصة أو المنتج الأول، وبعدها استخدم البيانات هنا.');return;}
                const values=Object.fromEntries(Object.entries(controls).map(([k,c])=>[k,c.value]));
                approvedContact=Object.fromEntries(Object.entries(values).filter(([key])=>['parent_name','phone','street'].includes(key)));
                const count=frame.contentWindow.applySalesDetails(values);
                notice(count?'تم ملء الخانات الناقصة. راجعها وكمّل الصور وباقي البيانات.':'الخانات دي مليانة بالفعل أو تخص خطوة تانية. تقدر تعدّلها مباشرة في الخطوة الظاهرة.');
                q('[data-workspace]').scrollIntoView({block:'start',behavior:'instant'});
            }catch{notice('استنى تحميل الخطوة، وبعدها جرّب استخدام البيانات.');}
        });box.append(apply);
        requestAnimationFrame(()=>box.scrollIntoView({block:'start',behavior:'instant'}));
    }
    async function offerUpsells() {
        if(!state || state.upsell_declined) return;
        try {
            const result=await api(root.dataset.catalog.replace('/catalog','/upsells'));
            if(!result.cards?.length) return;
            const key=result.cards.map(c=>c.key).join('|'); if(key===upsellKey)return;upsellKey=key;
            const box=q('[data-upsells]');box.replaceChildren(element('h3','hk-sales-upsell-title','تحب تضيف نشاط يكمل اختيارك؟ (اختياري)'),cards(result.cards));
            const decline=element('button','hk-sales-decline','كفاية اختياري، مش محتاج إضافات');decline.type='button';
            decline.addEventListener('click',async()=> {try {await api(root.dataset.event,{type:'upsell_declined'});state.upsell_declined=true;box.hidden=true;}catch(e){notice(e.message);} });
            box.append(decline);box.hidden=false;
        } catch {}
    }
    async function browse(query = '') {
        try { const result=await api(root.dataset.catalog,{query});q('[data-browser-results]').replaceChildren(cards(result.cards));
            if(!result.cards.length) notice('ما لقيتش اختيار مطابق. جرّب موضوع أبسط أو كلم الفريق.');
        } catch(error){notice(error.message);}
    }
    q('[data-browse]').addEventListener('click',()=>browse(input.value));
    composer.addEventListener('submit',async event=> {
        event.preventDefault();const text=input.value.trim();if(!text || busy || pending())return;
        busy=true;q('[data-send]').disabled=true;notice();
        try {
            if(!state) await refresh();
            if(text!==lastText || !nonce){nonce=newClientNonce();lastText=text;}
            await api(root.dataset.message,{message:text,client_nonce:nonce,...(privateStep?{private_step:privateStep}:{})});input.value='';nonce=null;lastText=null;
            await refresh();
        } catch(error){notice(error.message);await refresh();}
        finally {busy=false;q('[data-send]').disabled=pending();}
    });
    input.addEventListener('keydown',e=> {if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing){e.preventDefault();composer.requestSubmit();}});
    root.querySelectorAll('[data-topic]').forEach(b=>b.addEventListener('click',()=> {interest=b.dataset.topic;input.value=interest;composer.requestSubmit();}));
    q('[data-handoff]')?.addEventListener('click',()=> {
        const box=element('div','hk-sales-message');box.append(element('p','','الرسالة هتفتح في واتساب؛ أنت اللي تبعتها.'));
        const label=element('label');const checkbox=element('input');checkbox.type='checkbox';label.append(checkbox,document.createTextNode(' إضافة ملخص عن الاهتمام وعناصر السلة بدون بيانات الطفل'));box.append(label);
        const link=element('a','hk-sales-decline','فتح واتساب ↗');link.target='_blank';link.rel='noopener noreferrer';
        function update(){const summary=checkbox.checked ? `\n${interest}\n${(state?.cart.items || []).slice(0,6).map(i=>(i.title || 'عنصر من السلة').slice(0,100)).join('، ')}`:'';const url=new URL(root.dataset.whatsapp);url.searchParams.set('text',`أهلاً فريق HeroKid، محتاج مساعدة في اختيار طلب جديد.${summary}`);link.href=url.href;}
        checkbox.addEventListener('change',update);update();link.addEventListener('click',()=>api(root.dataset.event,{type:'handoff'}).catch(()=>{}));box.append(link);q('[data-thread]').append(box);box.scrollIntoView({block:'nearest'});
    });
    function show(){returnFocus=document.activeElement;open=true;panel.hidden=false;q('[data-backdrop]').hidden=false;q('[data-open]').setAttribute('aria-expanded','true');q('[data-invite]').hidden=true;document.documentElement.style.overflow='hidden';input.focus();refresh();}
    function close(){open=false;panel.hidden=true;q('[data-backdrop]').hidden=true;q('[data-open]').setAttribute('aria-expanded','false');document.documentElement.style.overflow='';stopPolling();returnFocus?.focus();}
    q('[data-open]')?.addEventListener('click',show);q('[data-close]')?.addEventListener('click',close);q('[data-backdrop]')?.addEventListener('click',close);
    document.addEventListener('visibilitychange',()=> {if(document.hidden)stopPolling();else if(open)refresh();});
    panel.addEventListener('keydown',e=> {
        if(root.dataset.full==='1')return;if(e.key==='Escape'){close();return;}if(e.key!=='Tab')return;
        const focusable=[...panel.querySelectorAll('button,a[href],textarea,iframe,[tabindex="0"]')].filter(el=>el.getClientRects().length&&!el.disabled);
        const first=focusable[0],last=focusable.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus();}
    });
    document.addEventListener('focusin',event=> {if(open && root.dataset.full!=='1' && !panel.contains(event.target))panel.querySelector('[data-close]')?.focus();});
    q('[data-dismiss-invite]')?.addEventListener('click',()=>q('[data-invite]').hidden=true);
    if(!open){try{if(!localStorage.getItem('hk-sales-invited')){q('[data-invite]').hidden=false;localStorage.setItem('hk-sales-invited','1');}}catch{}}
    else refresh();
    window.addEventListener('pagehide',stopPolling);
}
