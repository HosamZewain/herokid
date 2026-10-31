const escape = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const types = {generate_identity:'توليد هوية الطفل وحفظها',approve_identity:'اعتماد الهوية من رد العميل',whatsapp:'إرسال قالب واتساب',wait_response:'انتظار رد العميل',change_status:'تغيير حالة الطلب',condition:'شرط وتفرّع',manual_review:'مراجعة موظف',end:'إنهاء المسار'};
export function newNode(type, id) {
    const config = {generate_identity:{budget_usd:1},approve_identity:{response_node:''},whatsapp:{template:'herokid_order_confirmation',language:'ar-EG',variables:['customer_name','order_reference','order_details','order_total']},wait_response:{message_node:'',timeout_hours:24,cases:[{key:'confirm',text:'تأكيد الطلب',next:''}],timeout_next:''},change_status:{from:'new',to:'new_confirmed'},condition:{field:'status',value:'new_confirmed',yes:'',no:''},manual_review:{instructions:'راجع الطلب قبل المتابعة.'},end:{}}[type];
    return {id,type,label:types[type],config:structuredClone(config),...(type === 'end' || type === 'wait_response' || type === 'condition' ? {} : {next:''})};
}
export function initializeProcessBuilder(root) {
    const form = root.closest('form');
    const hidden = form.querySelector('[name="definition_json"]');
    let definition;
    try { definition = JSON.parse(hidden.value); } catch { definition = JSON.parse(root.dataset.initial); }
    if (!Array.isArray(definition?.nodes) || definition.schema !== 2) definition = JSON.parse(root.dataset.initial);
    const statuses = JSON.parse(root.dataset.statuses);
    const variables = JSON.parse(root.dataset.variables);
    const list = root.querySelector('[data-nodes]');
    const save = () => { hidden.value = JSON.stringify(definition); };
    const options = (entries, current) => Object.entries(entries).map(([value,label]) => `<option value="${escape(value)}" ${String(value)===String(current)?'selected':''}>${escape(label)}</option>`).join('');
    const select = (path, label, entries, current) => `<label class="block text-sm font-bold">${escape(label)}<select data-field="${path}" class="mt-2 w-full rounded-lg border-gray-300">${options(entries,current)}</select></label>`;
    const input = (path, label, value, type='text') => `<label class="block text-sm font-bold">${escape(label)}<input data-field="${path}" ${path==='config.template'?'list="process-templates"':''} type="${type}" value="${escape(value)}" ${type==='number'?'min="0.001" max="720" step="any"':'maxlength="500"'} class="mt-2 w-full rounded-lg border-gray-300"></label>`;
    const targets = i => Object.fromEntries([['','اختر الخطوة التالية'], ...definition.nodes.slice(i+1).map((n,j)=>[n.id,`${i+j+2}. ${n.label}`])]);
    const render = () => {
        list.innerHTML = definition.nodes.map((n,i)=> {
            const c=n.config, next=targets(i); let fields='';
            if(n.type==='whatsapp') {
                fields=input('config.template','اسم القالب',c.template)+`<p class="text-sm text-slate-500">اللغة: العربية — ar-EG. القوالب المخصصة متاحة لتصميم المحاكاة؛ تحتاج تحققًا قبل التشغيل الحقيقي.</p>`;
                fields+=`<div class="space-y-2 md:col-span-2"><p class="font-bold">متغيرات القالب بالترتيب</p>${c.variables.map((v,j)=>`<div class="flex items-end gap-2">${select(`config.variables.${j}`,`المتغير ${j+1}`,variables,v)}<button type="button" data-command="variable-remove" data-position="${j}" class="rounded border px-3 py-2">حذف</button></div>`).join('')}<button type="button" data-command="variable-add" class="text-indigo-700">+ متغير</button></div>`;
            } else if(n.type==='generate_identity') {
                fields=input('config.budget_usd','ميزانية تقديرية قصوى بالدولار لكل عملية شراء',c.budget_usd,'number')+'<p class="text-sm text-slate-500">توليد واحد لكل طفل، من صور الأوردر والبرومبت الحالي. النتيجة تنتظر موافقة العميل؛ التفعيل الفعلي يحتاج ميزانية وعامل توليد.</p>';
            } else if(n.type==='approve_identity') {
                fields=select('config.response_node','خطوة رد العميل على معاينة الهوية',Object.fromEntries([['','اختر انتظارًا سابقًا'],...definition.nodes.slice(0,i).filter(n=>n.type==='wait_response').map(n=>[n.id,n.label])]),c.response_node);
            } else if(n.type==='wait_response') {
                fields=select('config.message_node','الرسالة المرتبط بها الرد',Object.fromEntries([['','اختر رسالة سابقة'],...definition.nodes.slice(0,i).filter(n=>n.type==='whatsapp').map(n=>[n.id,n.label])]),c.message_node)+input('config.timeout_hours','مهلة الرد بالساعات',c.timeout_hours,'number');
                fields+=`<div class="space-y-3 md:col-span-2">${c.cases.map((b,j)=>`<div class="grid gap-3 rounded-xl bg-emerald-50 p-3 md:grid-cols-3">${input(`config.cases.${j}.text`,'إذا كان الرد',b.text)}${select(`config.cases.${j}.next`,'انتقل إلى',next,b.next)}<button type="button" data-command="case-remove" data-position="${j}" class="text-red-700">حذف الفرع</button></div>`).join('')}<button type="button" data-command="case-add" class="text-indigo-700">+ فرع رد</button></div>`;
                fields+=select('config.timeout_next','إذا انتهت المهلة بدون رد',next,c.timeout_next);
            } else if(n.type==='change_status') {
                fields=select('config.from','الحالة المطلوبة قبل التغيير',statuses,c.from)+select('config.to','الحالة الجديدة',statuses,c.to);
            } else if(n.type==='condition') {
                fields=select('config.field','تحقق من',{status:'حالة الطلب',type:'نوع المحتويات'},c.field)+select('config.value','يساوي',c.field==='status'?statuses:{story:'قصص',product:'منتجات',mixed:'قصص ومنتجات'},c.value)+select('config.yes','إذا تحقق الشرط',next,c.yes)+select('config.no','إذا لم يتحقق',next,c.no);
            } else if(n.type==='manual_review') fields=input('config.instructions','تعليمات الموظف',c.instructions);
            if(!['end','wait_response','condition'].includes(n.type)) fields+=select('next','بعد اكتمال الخطوة',next,n.next);
            return `<section data-node="${escape(n.id)}" class="space-y-4 rounded-2xl border border-indigo-100 bg-white p-5"><div class="flex flex-wrap items-center justify-between gap-3"><span class="rounded-full bg-indigo-50 px-3 py-1 font-bold text-indigo-700">${i+1} · ${escape(types[n.type])}</span><div class="flex gap-2"><button type="button" data-command="up" ${i===0?'disabled':''} class="rounded border px-3 py-1 disabled:opacity-30" aria-label="نقل الخطوة لأعلى">↑</button><button type="button" data-command="down" ${i===definition.nodes.length-1?'disabled':''} class="rounded border px-3 py-1 disabled:opacity-30" aria-label="نقل الخطوة لأسفل">↓</button><button type="button" data-command="remove" class="rounded border px-3 py-1 text-red-700">حذف</button></div></div>${input('label','اسم الخطوة',n.label)}<div class="grid gap-4 md:grid-cols-2">${fields}</div></section>`;
        }).join('<div class="text-center text-2xl text-indigo-300" aria-hidden="true">↓</div>');
        save();
    };
    root.querySelector('[data-add]').addEventListener('click',()=> {
        if(definition.nodes.length>=40) return;
        const node = newNode(root.querySelector('[data-new-type]').value, 'step_'+crypto.randomUUID().replaceAll('-',''));
        const last = definition.nodes.at(-1);
        if (last?.type === 'end' && node.type !== 'end') {
            for (const previous of definition.nodes) {
                if (previous.next === last.id) previous.next = node.id;
                if (previous.type === 'condition') for (const edge of ['yes','no']) if(previous.config[edge]===last.id) previous.config[edge]=node.id;
                if (previous.type === 'wait_response') {
                    if(previous.config.timeout_next===last.id) previous.config.timeout_next=node.id;
                    for(const branch of previous.config.cases) if(branch.next===last.id) branch.next=node.id;
                }
            }
            if ('next' in node) node.next = last.id;
            if(node.type==='condition') node.config.yes=node.config.no=last.id;
            if(node.type==='wait_response') {
                node.config.message_node=definition.nodes.filter(n=>n.type==='whatsapp').at(-1)?.id || '';
                node.config.timeout_next=last.id;
                node.config.cases[0].next=last.id;
            }
            definition.nodes.splice(definition.nodes.length-1,0,node);
        } else definition.nodes.push(node);
        render();
        list.lastElementChild?.scrollIntoView({block:'nearest'});
    });
    list.addEventListener('input', event=> {
        const field=event.target.dataset.field;
        const node=definition.nodes.find(n=>n.id===event.target.closest('[data-node]')?.dataset.node);
        if(!field || !node) return;
        const path=field.split('.'); let parent=node;
        for(const key of path.slice(0,-1)) parent=parent[key];
        parent[path.at(-1)]=event.target.type==='number'?Number(event.target.value):event.target.value;
        if(field==='config.field') { node.config.value=node.config.field==='status'?'new_confirmed':'story'; render(); }
        save();
    });
    list.addEventListener('change', event=> {
        if(event.target.dataset.field==='config.template') {
            const node=definition.nodes.find(n=>n.id===event.target.closest('[data-node]')?.dataset.node);
            if(node?.config.template==='herokid_identity_review_payment') node.config.variables=['customer_name','order_reference','story_names','child_names','product_names','identity_preview_url','order_total','remaining_amount'];
            if(node?.config.template==='herokid_order_confirmation') node.config.variables=['customer_name','order_reference','order_details','order_total'];
            render();
        }
        if(event.target.dataset.field==='label') {
            for (const select of list.querySelectorAll('select[data-field]')) {
                if (!['next','config.yes','config.no','config.timeout_next','config.message_node'].includes(select.dataset.field) && !select.dataset.field.endsWith('.next')) continue;
                for (const option of select.options) {
                    const index=definition.nodes.findIndex(n=>n.id===option.value);
                    if(index>=0) option.textContent=`${index+1}. ${definition.nodes[index].label}`;
                }
            }
        }
    });
    list.addEventListener('click',event=> {
        const button=event.target.closest('[data-command]'); if(!button) return;
        const i=definition.nodes.findIndex(n=>n.id===button.closest('[data-node]')?.dataset.node);
        const n=definition.nodes[i]; if(!n) return;
        const j=Number(button.dataset.position);
        switch(button.dataset.command) {
            case 'up': if(i>0) [definition.nodes[i-1],definition.nodes[i]]=[n,definition.nodes[i-1]]; break;
            case 'down': if(i<definition.nodes.length-1) [definition.nodes[i+1],definition.nodes[i]]=[n,definition.nodes[i+1]]; break;
            case 'remove': definition.nodes.splice(i,1); break;
            case 'variable-add': if(n.config.variables.length<10) n.config.variables.push('order_reference'); break;
            case 'variable-remove': n.config.variables.splice(j,1); break;
            case 'case-add': if(n.config.cases.length<5) n.config.cases.push({key:'reply_'+crypto.randomUUID().replaceAll('-','').slice(0,16),text:'',next:''}); break;
            case 'case-remove': n.config.cases.splice(j,1); break;
        }
        render();
    });
    form.addEventListener('submit',()=> {
        definition.scope=form.querySelector('[data-scope]').value;
        definition.trigger=form.querySelector('[data-trigger]').value;
        definition.conditions.type=form.querySelector('[data-target]').value;
        definition.conditions.status=form.querySelector('[data-status]').value;
        definition.conditions.story_ids=[...form.querySelector('[data-stories]').selectedOptions].map(o=>Number(o.value));
        definition.conditions.product_ids=[...form.querySelector('[data-products]').selectedOptions].map(o=>Number(o.value));
        save();
    });
    render();
    form.querySelector('[data-save]').disabled=false;
    root.dataset.ready='true';
}
