// Native forms are an internal commerce adapter. Customers answer messages; only
// upload controls and the final native review are displayed, never a product form.
export function intakeNames(key, unit = null) {
    return unit === null ? [key, `child[${key}]`, `personalizations[0][${key}]`] : [`personalizations[${unit}][${key}]`];
}
export function intakeFieldKey(name) {
    const key=name?.match(/^personalizations\[\d+\]\[([a-z_]+)\]$/)?.[1];
    return ['child_name','child_age','child_gender','parent_name','phone','street'].find(k=>key===k || intakeNames(k).includes(name)) || 'commerce';
}
export function normalizeIntake(value) {
    return String(value).normalize('NFKC').replace(/[٠-٩]/g,c=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(c))).replace(/[أإآ]/g,'ا').replace(/ى/g,'ي').replace(/[ً-ٟـ]/g,'').trim().toLowerCase();
}
export function matchIntakeOption(text, options) {
    const normalized=normalizeIntake(text), matches=options.filter(o=>normalizeIntake(o.label)===normalized || normalizeIntake(o.value)===normalized);
    return matches.length===1 ? matches[0].value : null;
}
export function startSalesIntake(report) {
    const form=document.querySelector('[data-story-order-form],[data-checkout-form],[data-product-order-form],[data-package-order-form]');if(!form)return;
    const checkout=form.matches('[data-checkout-form]'), story=form.matches('[data-story-order-form]');
    const shell=document.createElement('section');shell.className='hk-sales-intake hk-sales-message-intake';
    const host=document.body.querySelector(':scope > .min-h-screen');
    shell.append(form);if(host)host.hidden=true;document.body.append(shell);document.body.classList.add('hk-sales-intake-page');
    // Keep native listeners, inputs, upload ownership and server validation intact.
    form.querySelectorAll('[data-package-story-select]').forEach(c=>c.required=true);
    const originals=[...form.children];originals.forEach(n=>n.classList.add('hk-sales-intake-unused'));
    const photoHost=document.createElement('div');photoHost.dataset.messagePhotos='';form.append(photoHost);
    const photoSteps=[...form.querySelectorAll('[data-photo-input],[data-identity-photo-input],input[type="file"]')].map((control,index)=>{const unit=control.closest('[data-personalization-unit]');let min=Number(control.dataset.salesMinFiles ?? form.dataset.salesMinPhotos ?? (story?2:1));try{min=Number(JSON.parse(unit?.querySelector('[data-identity-upload-config]')?.textContent || '{}').minimumFiles ?? min);}catch{}return {key:`photos:${index}`,control,unit,panel:control.closest('[data-package-uploader]') || control.parentElement,min,kind:'photos',options:[],label:`ارفع ${control.parentElement.querySelector('h3')?.textContent.trim() || 'صور الطفل'}${unit?' '+(Number(unit.dataset.personalizationUnit)+1):''} من زر رفع الصور. المطلوب ${min===1?'صورة واحدة':min===2?'صورتين':min+' صور'} على الأقل.`};});
    let current=null, done=new Set(), edit=null, lastSignature='', paused=false;
    const active=c=>!c.disabled && (!c.closest('[hidden],.hidden') || c.matches('[data-package-story-select]')) && c.type!=='hidden' && c.type!=='search' && c.type!=='checkbox';
    function label(c) {
        if(c.matches('[data-package-story-select]'))return `اختار القصة ${Number(c.closest('[data-slot]')?.dataset.slot || 0)+1} في الباقة`;
        if(c.name==='variant_id')return 'تحب أنهي مقاس أو نوع للمنتج';
        const unit=c.closest('[data-personalization-unit]');const prefix=unit ? `للطفل ${Number(unit.dataset.personalizationUnit)+1}: ` : '';
        const node=c.labels?.[0] || c.closest('label');
        const clone=node?.cloneNode(true);clone?.querySelectorAll('input,select,textarea,.text-red-500,.text-slate-400').forEach(n=>n.remove());
        return prefix+(clone?.textContent.trim().replace(/\s+/g,' ').replace(/\*/g,'').replace(/\(اختياري\)/g,'').trim().slice(0,140) || c.getAttribute('aria-label') || ({delivery_country_id:'الدولة',delivery_governorate_id:'المحافظة',bosta_district_id:'المنطقة'}[c.name]) || c.name);
    }
    function choices(c) {
        if(c.tagName==='SELECT')return [...c.options].filter(o=>o.value && !o.hidden && !o.disabled).map(o=>({value:o.value,label:o.textContent.trim()}));
        if(c.type==='radio')return [...form.elements].filter(o=>o.name===c.name && active(o)).map(o=>({value:o.value,label:o.dataset.name || o.labels?.[0]?.textContent.trim().replace(/\s+/g,' ').slice(0,160) || o.value}));
        return [];
    }
    function steps() {
        const list=[],seen=new Set();
        for(const c of form.querySelectorAll('input,select,textarea')) {
            if(!c.name || !active(c) || seen.has(c.name) || c.type==='file')continue;
            if(!c.required && !['language','quantity','alternate_phone','address_details','gift_note','parent_notes'].includes(c.name) && c.tagName!=='TEXTAREA' && !c.matches('[data-personalization-field],[data-package-required]'))continue;
            seen.add(c.name);list.push({key:c.name,control:c,label:label(c),options:choices(c),kind:'text'});
        }
        // Photo requirements remain native, scoped to the exact child/unit.
        for(const photo of photoSteps)if(!photo.control.disabled && !photo.unit?.hidden)list.push(photo);
        return list;
    }
    function enoughPhotos(s) {
        const prefix=s.unit ? `personalizations[${s.unit.dataset.personalizationUnit}][photo_upload_ids]` : story?'photo_upload_ids':'child[photo_upload_ids]';
        return [...form.querySelectorAll('input[type="hidden"][name*="photo_upload_ids"]')].filter(c=>c.name.startsWith(prefix)).length>=s.min && !s.unit?.querySelector('[data-identity-submit]')?.disabled;
    }

    function complete(s) {
        if(s.kind==='photos')return enoughPhotos(s);
        if(s.control.type==='radio')return [...form.elements].some(c=>c.name===s.key && c.checked);
        return s.control.getAttribute('aria-invalid')!=='true' && !!s.control.value && s.control.checkValidity();
    }
    function nativeKey(c) {return intakeFieldKey(c?.name);}
    function hidePhotos() {photoHost.hidden=true;[...photoHost.children].forEach(p=>p.hidden=true);}
    function showPhotos(s) {
        const panel=s.panel;panel.classList.remove('hk-sales-intake-unused');panel.hidden=false;photoHost.append(panel);photoHost.hidden=false;
    }

    function question() {
        if(paused)return;
        const all=steps(), editing=edit;
        for(const s of all)if(s.options.length===1 && complete(s))done.add(s.key);
        // Previously completed required data is reused only in this active native entry.
        current=edit ? all.find(s=>s.key===edit) : all.find(s=>!done.has(s.key) && !(s.control.type!=='radio' && s.control.tagName!=='SELECT' && s.control.name!=='quantity' && complete(s)));
        edit=null;
        hidePhotos();
        if(current?.kind==='photos') {
            if(enoughPhotos(current) && !editing){done.add(current.key);question();return;}showPhotos(current);
        }
        const payload=current ? {token:current.key,field:nativeKey(current.control),question:current.kind==='photos'?current.label:`${current.label}${current.control.required?'':' (اختياري؛ تقدر تقول تخطي)'}؟`,options:current.kind==='photos'?[]:[...current.options,...(!current.control.required?[{value:'تخطي',label:'تخطي'}]:[])],upload:current.kind==='photos'}
            : {token:'ready',field:null,question:checkout?'كل البيانات جاهزة. نراجع العناصر والشحن والإجمالي؟':'بيانات الاختيار وصوره جاهزة. نضيفه للسلة ونكمّل التوصيل؟',options:[],ready:true};
        payload.fields=all.map(s=>({token:s.key,label:s.label}));
        const signature=JSON.stringify(payload);if(signature!==lastSignature){lastSignature=signature;report('conversation-question',payload);}
        report('resize',{height:Math.ceil(shell.getBoundingClientRect().height+24)});
    }
    function set(s,value) {
        let answer=String(value).trim();const options=choices(s.control);
        if(options.length){
            if(s.key.includes('child_gender')){const n=normalizeIntake(answer);answer=['بنت','انثي','girl','female'].includes(n)?'girl':['ولد','ذكر','boy','male'].includes(n)?'boy':answer;}
            if(s.key.includes('child_age'))answer=normalizeIntake(answer).replace(/\s*(سنه|سنة|سنين|سنوات)\s*$/,'');
            const matched=matchIntakeOption(answer,options);if(matched===null)return false;answer=matched;
        }
        if(s.control.type==='radio'){const radio=[...form.elements].find(c=>c.name===s.key && c.value===answer);if(!radio)return false;radio.checked=true;radio.dispatchEvent(new Event('change',{bubbles:true}));}
        else {s.control.value=answer;s.control.removeAttribute('aria-invalid');s.control.setCustomValidity('');s.control.dispatchEvent(new Event('input',{bubbles:true}));s.control.dispatchEvent(new Event('change',{bubbles:true}));}
        if(!s.control.checkValidity())return false;done.add(s.key);return true;
    }
    const previousDialog=form.querySelector('[data-existing-order-dialog]');
    window.salesConversationAnswer=(token,text,values={})=>{
        if(token==='previous-order' && previousDialog && !previousDialog.classList.contains('hidden')){
            const options=[...previousDialog.querySelectorAll('[data-order-choice]')].filter(b=>['separate','merge'].includes(b.dataset.orderChoice) && !b.disabled);
            const chosen=options.find(b=>(b.dataset.orderChoice==='merge'?'طلب ضم الشحن':'الطلب الجديد منفصل')===text);
            if(!chosen)return {accepted:false,message:'اختار طلبًا منفصلًا أو طلب ضم الشحن من الأزرار.'};
            chosen.click();return {accepted:true};
        }
        if(!current || current.key!==token)return {accepted:false,message:'الخطوة اتغيرت؛ رد على السؤال الحالي.'};
        let applied=0;
        for(const [key,value] of Object.entries(values)) {
            const unit=current.key.match(/^personalizations\[(\d+)\]/)?.[1];
            const step=steps().find(s=>intakeNames(key,unit ?? null).includes(s.key));
            if(step && (step.key===token || !complete(step)) && set(step,value))applied++;
        }
        if(!applied) {
            if(current.kind==='photos'){question();return {accepted:false,message:'ارفع الصور واستنى اكتمالها، وبعدها اضغط تم رفع الصور.'};}
            if(/^(تخطي|تخطى|لا|مش محتاج|بدون|skip)$/i.test(text.trim()) && !current.control.required){done.add(current.key);applied++;}
            else if((!Object.keys(values).length || nativeKey(current.control)==='commerce') && set(current,text))applied++;
        }
        if(!applied)return {accepted:false,message:'مش واضح الاختيار. اكتب إجابتك أو اختار من الأزرار الظاهرة.'};
        lastSignature='';question();return {accepted:true};
    };
    window.applySalesDetails=values=>{if(!current)return 0;const result=window.salesConversationAnswer(current.key,'',values);return result.accepted?1:0;};
    window.resumeSalesIntake=()=>{lastSignature='';question();return current?nativeKey(current.control):null;};
    window.salesConversationAction=(token,action)=>{
        if(action==='edit'){if(!steps().some(s=>s.key===token))return;done.delete(token);edit=token;lastSignature='';question();return;}
        if(token!== (current?.key || 'ready'))return;
        if(action==='photos'){if(current?.kind==='photos' && enoughPhotos(current)){done.add(current.key);lastSignature='';question();}else report('conversation-error',{message:'استنى اكتمال العدد المطلوب من الصور.'});return;}
        if(action==='submit' && !current){
            const invalid=steps().find(s=>s.control.required && !complete(s));
            if(invalid){done.delete(invalid.key);edit=invalid.key;lastSignature='';question();return;}
            const submit=form.querySelector(story?'[data-story-submit][value="cart"]':'button[type="submit"]');
            if(submit && !submit.disabled){paused=true;form.requestSubmit(submit);}else report('conversation-error',{message:'لسه فيه رفع صور أو بيانات محتاجة مراجعة. استنى اكتمالها.'});
        }
    };
    if(previousDialog)new MutationObserver(()=>{
        if(previousDialog.classList.contains('hidden'))return;
        const options=[...previousDialog.querySelectorAll('[data-order-choice]')].filter(b=>['separate','merge'].includes(b.dataset.orderChoice) && !b.disabled).map(b=>({value:b.dataset.orderChoice,label:b.dataset.orderChoice==='merge'?'طلب ضم الشحن':'الطلب الجديد منفصل'}));
        report('conversation-question',{token:'previous-order',field:'commerce',question:'في طلب سابق بنفس الرقم. تحب الجديد منفصل، ولا تطلب ضم الشحن؟ الفريق هيراجع إمكانية الضم ومصاريفه.',options,fields:[]});
    }).observe(previousDialog,{attributes:true,attributeFilter:['class']});
    form.addEventListener('submit',event=>queueMicrotask(()=>{
        if(event.defaultPrevented && !checkout){paused=false;const photo=steps().find(s=>s.kind==='photos');if(photo){edit=photo.key;done.delete(photo.key);}lastSignature='';question();report('conversation-error',{message:'راجع الصور: استنى اكتمال رفعها، أو احذف الصورة اللي فشلت وأعد رفعها.'});}
    }));
    form.addEventListener('invalid',event=>{const s=steps().find(s=>s.control===event.target);if(s){paused=false;edit=s.key;lastSignature='';question();}event.preventDefault();},true);
    // Native dependent shipping selectors populate asynchronously; publish current choices only.
    new MutationObserver(()=>{if(current?.control.tagName==='SELECT'){lastSignature='';question();}}).observe(form,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled']});
    new ResizeObserver(()=>report('resize',{height:Math.ceil(shell.getBoundingClientRect().height+24)})).observe(shell);
    question();
    const errors=[...document.querySelectorAll('[data-scroll-on-load] li,[role=alert] li,form ul.text-red-600 li')].map(n=>n.textContent.trim()).filter(Boolean);
    if(errors.length)report('conversation-error',{message:[...new Set(errors)].slice(0,6).join(' — ')});
}
