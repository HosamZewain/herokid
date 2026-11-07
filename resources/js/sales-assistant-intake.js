// Native commerce controls stay bound to their existing upload/validation/pricing code.
// The assistant presents those controls as short questions, without a second storefront.
export function intakeNames(key) {
    return [key, `child[${key}]`, `personalizations[0][${key}]`];
}

export function startSalesIntake(report) {
    const form = document.querySelector('[data-story-order-form],[data-checkout-form],[data-product-order-form],[data-package-order-form]');
    if (!form) return;
    const story = form.matches('[data-story-order-form]'), checkout = form.matches('[data-checkout-form]');
    const shell = document.createElement('section'); shell.className = 'hk-sales-intake';
    const heading = document.createElement('h2');
    heading.textContent = checkout ? 'نوصّل الطلب فين؟' : (document.querySelector('h1')?.textContent.trim() || 'نكمّل اختيارك');
    shell.append(heading);
    document.querySelectorAll('[data-scroll-on-load]').forEach(error => shell.append(error));
    // Keep the private cart editor reachable before the final review.
    if (checkout) {
        const edit = document.querySelector('a[href*="/shopping-assistant/cart-edit"]');
        if (edit) shell.append(edit);
    }
    const host = document.body.querySelector(':scope > .min-h-screen');
    shell.append(form); document.querySelectorAll('[data-package-story-dialog]').forEach(dialog=>shell.append(dialog)); if (host) host.hidden = true; document.body.append(shell);
    document.body.classList.add('hk-sales-intake-page');
    // A native selector avoids a modal centered in a long embedded document on mobile.
    form.querySelectorAll('[data-package-story-select]').forEach(select=>select.classList.remove('hidden'));
    const fields = [], add = (node, question, field = null) => {
        if (node && !fields.some(s => s.node === node)) fields.push({node, question, field});
    };
    const wrapper = name => form.elements.namedItem(name)?.closest('div');
    if (story) {
        add(wrapper('child_name'), 'اسم الطفل اللي هنخصص له القصة إيه؟', 'child_name');
        add(wrapper('child_age'), 'عمر الطفل كام سنة؟', 'child_age');
        add(wrapper('child_gender'), 'نخصص الشخصية لولد ولا لبنت؟', 'child_gender');
        add(wrapper('language'), 'تحب القصة بأي لغة؟');
        add(form.querySelector('[data-photo-input]')?.parentElement, 'ارفع صور الطفل هنا علشان نخصص القصة بصورته.');
        add(form.querySelector('[data-story-stage="2"]'), 'تحب تضيف إهداء أو ملاحظة؟ الخطوة دي اختيارية.');
        add(form.querySelector('[data-story-cart-actions]'), 'بيانات الطفل جاهزة؟ نضيف القصة ونكمّل الطلب.');
    } else if (checkout) {
        add(wrapper('parent_name'), 'اسم ولي الأمر اللي هنتواصل معاه إيه؟', 'parent_name');
        add(wrapper('phone'), 'رقم الموبايل أو واتساب إيه؟', 'phone');
        add(wrapper('alternate_phone'), 'تحب تضيف رقم احتياطي؟ تقدر تتخطى الخطوة دي.');
        add(form.querySelector('#delivery_country_id')?.parentElement.parentElement, 'اختار المحافظة والمنطقة، واكتب الشارع ورقم العقار.');
        add(wrapper('address_details'), 'في علامة مميزة أو تفاصيل إضافية للعنوان؟ دي اختيارية.');
        add(form.querySelector('button[type="submit"]'), 'نراجع العناصر والأطفال والصور والسعر والشحن قبل تأكيد الطلب.');
    }
    let current = 0, show = () => {};
    if (fields.length) {
        const content = document.createElement('div'); content.className = 'hk-sales-intake-fields';
        const prompt = document.createElement('h3'); prompt.tabIndex = -1; prompt.dataset.intakePrompt='';
        const progress = document.createElement('p'); progress.className = 'hk-sales-intake-progress';
        const navigation = document.createElement('div'); navigation.className = 'hk-sales-intake-nav';
        const back = document.createElement('button'); back.type = 'button'; back.textContent = 'السابق';
        const next = document.createElement('button'); next.type = 'button'; next.textContent = 'متابعة';
        navigation.append(back, next);
        // Move controls, do not clone them or their listeners/photo ownership.
        fields.forEach(s => { s.node.classList.add('hk-sales-intake-question'); content.append(s.node); });
        [...form.children].forEach(child => {
            if (!child.matches('input[type="hidden"],script,[data-existing-order-dialog]')) child.classList.add('hk-sales-intake-unused');
        });
        const requirements = form.querySelector('[data-story-requirements]');
        if (requirements) { requirements.classList.remove('hk-sales-intake-unused'); content.append(requirements); }
        form.append(progress, prompt, content, navigation);
        form.querySelectorAll('details').forEach(d => {d.open = true;});
        const controls = step => [...step.node.querySelectorAll('input,select,textarea')].filter(c => c.type !== 'hidden' && !c.disabled);
        show = (focus = true) => {
            fields.forEach((s, i) => {s.node.hidden = i !== current;});
            prompt.textContent = fields[current].question;
            progress.textContent = `الخطوة ${current + 1} من ${fields.length} — تقدر ترجع وتعدّل`;
            back.hidden = current === 0; next.hidden = current === fields.length - 1;
            report('question', {field: fields[current].field});
            if (focus) prompt.focus({preventScroll:true});
        };
        const advance = () => {
            const invalid = controls(fields[current]).find(c => !c.checkValidity());
            if (invalid) { invalid.reportValidity(); return; }
            if (story && fields[current].node.querySelector('[data-photo-input]') && form.querySelectorAll('[name="photo_upload_ids[]"]').length < Number(form.dataset.salesMinPhotos || 2)) {
                const error = form.querySelector('[data-photo-global-error]');
                if (error) { error.textContent = 'استنى اكتمال رفع العدد المطلوب من الصور قبل المتابعة.'; error.classList.remove('hidden'); }
                return;
            }
            current = Math.min(fields.length - 1, current + 1); show();
        };
        back.addEventListener('click', () => {current = Math.max(0, current - 1); show();});
        next.addEventListener('click', advance);
        form.addEventListener('submit', event => {
            if (current < fields.length - 1) {event.preventDefault();event.stopImmediatePropagation();advance();}
        }, true);
        form.addEventListener('invalid', event => {
            const index = fields.findIndex(s => s.node.contains(event.target));
            if (index >= 0) {current = index;show(false);}
        }, true);
        form.addEventListener('input', () => {document.body.dataset.salesDraftApplied = '';});
        show(false);
    }
    window.applySalesDetails = values => {
        const applied = [];
        for (const [key, value] of Object.entries(values)) {
            if (!['child_name','child_age','child_gender','parent_name','phone','street'].includes(key)) continue;
            // Only first/current child, explicit application, no cloning into other units.
            const field = intakeNames(key).map(n => form.elements.namedItem(n)).find(c => c?.tagName);
            if (!field || field.value || (field.tagName === 'SELECT' && ![...field.options].some(o => o.value === String(value)))) continue;
            field.value = String(value); field.dispatchEvent(new Event('input',{bubbles:true})); field.dispatchEvent(new Event('change',{bubbles:true})); applied.push(key);
        }
        if (applied.length) {
            const incomplete = fields.findIndex(s => controlsFor(s.node).some(c => c.required && (!c.value || !c.checkValidity())) || (s.node.querySelector('[data-photo-input]') && form.querySelectorAll('[name="photo_upload_ids[]"]').length < Number(form.dataset.salesMinPhotos || 2)));
            if (incomplete >= 0) current = incomplete;
            show(false);
        }
        return applied.length;
    };
    const controlsFor = node => [...node.querySelectorAll('input,select,textarea')].filter(c => c.type !== 'hidden' && !c.disabled);
    // Metadata only crosses the frame boundary; no customer values or photos.
    const resize = () => report('resize', {height: Math.ceil(shell.getBoundingClientRect().height + 32)});
    new ResizeObserver(resize).observe(shell); resize();
}
