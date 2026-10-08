export function catalogMatches(items, type, query) {
    const needle = query.trim().toLocaleLowerCase();
    return items.filter((item) => item.type === type && item.name.toLocaleLowerCase().includes(needle));
}

export function customerPhoneKey(value) {
    const digits = String(value ?? '').replace(/\D/g, '').replace(/^00/, '');
    if (/^01\d{9}$/.test(digits)) return `20${digits.slice(1)}`;
    if (/^1\d{9}$/.test(digits)) return `20${digits}`;
    return digits;
}

export function initializeAdminOrderWorkflow(root) {
    if (root.dataset.workflowBound) return;
    root.dataset.workflowBound = '1';
    const form = root.querySelector('[data-order-form]');
    const wizard = root.querySelector('[data-order-wizard]');
    const panels = Array.from(root.querySelectorAll('[data-order-step]'));
    const itemMessage = root.querySelector('[data-order-item-message]');
    const picker = root.querySelector('[data-catalog-picker]');
    const search = root.querySelector('[data-catalog-search]');
    const results = root.querySelector('[data-catalog-results]');
    const catalog = JSON.parse(root.querySelector('[data-order-catalog]')?.textContent || '[]');
    let activeType = 'product';
    let step = 1;
    let children = [];
    let childrenPhone = '';
    let lookupSequence = 0;
    const boundChildren = new WeakSet();
    const phone = root.querySelector('#phone');

    const showStep = (number) => {
        if (!wizard) return;
        step = number;
        root.dataset.orderStep = String(step);
        panels.forEach((panel) => { panel.hidden = Number(panel.dataset.orderStep) !== step; });
        wizard.querySelectorAll('[data-go-order-step]').forEach((button) => {
            const current = Number(button.dataset.goOrderStep) === step;
            button.classList.toggle('bg-indigo-600', current);
            button.classList.toggle('text-white', current);
            button.setAttribute('aria-current', current ? 'step' : 'false');
        });
        root.querySelector('[data-order-final-save]').hidden = step !== 3;
    };

    const updateChild = (select, fillValues = false) => {
        const scope = select.closest('[data-product-personalization-unit], [data-story-row]');
        const child = children.find((candidate) => String(candidate.order_id) === select.value);
        const photoInput = scope.querySelector('[data-photo-input], [data-product-photo-input]');
        const previews = scope.querySelector('[data-reused-child-photos]');
        previews?.replaceChildren();
        if (fillValues && child) {
            for (const [key, value] of Object.entries(child.values)) {
                const input = Array.from(scope.querySelectorAll('input, select, textarea'))
                    .find((candidate) => candidate.name.endsWith(`[${key}]`) && candidate !== select);
                if (input) {
                    const normalized = key === 'child_gender'
                        ? ({ female: 'girl', male: 'boy', girl: 'girl', boy: 'boy' }[value] || value)
                        : value;
                    input.value = normalized ?? '';
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        }
        if (photoInput) {
            const count = child?.photo_count || 0;
            const minimum = Number(photoInput.dataset.minFiles || 0);
            const maximum = Number(photoInput.dataset.photoMaxFiles || 3);
            photoInput.dataset.required = count < minimum ? '1' : '0';
            photoInput.required = !photoInput.disabled && count < minimum;
            photoInput.dataset.maxFiles = String(Math.max(0, maximum - count));
            select.setCustomValidity(count > maximum ? 'عدد صور الطفل السابق أكبر من الحد المسموح. اختر طفلًا جديدًا وارفع الصور المطلوبة.' : '');
            if (child && previews) {
                const label = document.createElement('p');
                label.className = 'w-full text-xs font-bold text-emerald-700';
                label.textContent = `سيُستخدم ${count} صورة محفوظة لهذا الطفل. يمكنك تعديل بياناته قبل الحفظ.`;
                previews.append(label);
                (child.photos || []).forEach((url) => {
                    const image = document.createElement('img');
                    image.src = url;
                    image.alt = 'صورة الطفل المختار';
                    image.className = 'h-14 w-14 rounded-xl object-cover';
                    image.loading = 'lazy';
                    previews.append(image);
                });
            }
        }
    };

    const populateChildSelects = () => {
        root.querySelectorAll('[data-customer-child-select]').forEach((select) => {
            if (select.disabled) return;
            const selected = select.value || select.dataset.selectedChild || '';
            select.replaceChildren();
            const manual = document.createElement('option');
            manual.value = '';
            manual.textContent = 'طفل جديد — إدخال البيانات والصور';
            select.append(manual);
            children.forEach((child) => {
                const option = document.createElement('option');
                option.value = child.order_id;
                option.textContent = child.label;
                select.append(option);
            });
            select.value = children.some((child) => String(child.order_id) === selected) ? selected : '';
            if (!boundChildren.has(select)) {
                boundChildren.add(select);
                select.addEventListener('change', () => {
                    delete select.dataset.selectedChild;
                    updateChild(select, true);
                });
            }
            updateChild(select);
        });
    };

    const loadChildren = async () => {
        const key = customerPhoneKey(phone?.value);
        if (!key || key === childrenPhone) { populateChildSelects(); return; }
        const lookup = root.querySelector('[data-existing-customer-lookup]');
        if (!lookup) return;
        const sequence = ++lookupSequence;
        try {
            const url = new URL(lookup.dataset.searchUrl, window.location.origin);
            url.searchParams.set('phone', phone.value);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Lookup failed');
            const payload = await response.json();
            if (sequence !== lookupSequence || key !== customerPhoneKey(phone.value)) return;
            children = payload.children || [];
            childrenPhone = key;
            populateChildSelects();
        } catch {
            if (sequence === lookupSequence) itemMessage.textContent = 'تعذر تحميل الأطفال السابقين. يمكنك إدخال طفل جديد، أو الرجوع للبحث عن العميل والمحاولة مرة أخرى.';
        }
    };

    root.addEventListener('admin:customer-selected', (event) => {
        ++lookupSequence;
        children = event.detail.children || [];
        childrenPhone = customerPhoneKey(event.detail.profile.phone);
        populateChildSelects();
    });
    phone?.addEventListener('input', () => {
        if (customerPhoneKey(phone.value) === childrenPhone) return;
        ++lookupSequence;
        children = [];
        childrenPhone = '';
        root.querySelectorAll('[data-customer-child-select]').forEach((select) => {
            select.value = '';
            delete select.dataset.selectedChild;
        });
        populateChildSelects();
    });

    const validateStep = (number) => {
        if (number === 2) {
            const hasStory = root.querySelectorAll('[data-story-row]').length > 0;
            const hasProduct = Array.from(root.querySelectorAll('[data-product-quantity]')).some((input) => Number(input.value) > 0);
            if (!hasStory && !hasProduct) {
                showStep(2);
                itemMessage.textContent = 'أضف قصة أو منتجًا واحدًا على الأقل.';
                return false;
            }
        }
        const panel = panels.find((candidate) => Number(candidate.dataset.orderStep) === number);
        for (const input of panel.querySelectorAll('input, select, textarea')) {
            if (input.willValidate && !input.checkValidity()) {
                showStep(number);
                input.reportValidity();
                return false;
            }
        }
        return true;
    };
    if (wizard) {
        // Package selection belongs with items, not with customer details.
        const packageSection = root.querySelector('[data-package-select]')?.closest('section');
        if (packageSection) root.querySelector('[data-order-item-picker]').after(packageSection);
        root.querySelectorAll('[data-go-order-step]').forEach((button) => button.addEventListener('click', () => {
            const target = Number(button.dataset.goOrderStep);
            if (target > step) {
                for (let index = step; index < target; index++) if (!validateStep(index)) return;
            }
            showStep(target);
            if (target === 2) loadChildren();
            wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }));
        form.addEventListener('invalid', (event) => {
            const panel = event.target.closest('[data-order-step]');
            if (panel) showStep(Number(panel.dataset.orderStep));
        }, true);
        showStep(1);
    }

    const bindProduct = (row) => {
        if (row.dataset.workflowProductBound) return;
        row.dataset.workflowProductBound = '1';
        const quantity = row.querySelector('[data-product-quantity]');
        const linked = row.querySelector('[data-story-link]');
        const update = () => {
            if (linked) linked.required = Number(quantity.value) > 0;
            populateChildSelects();
        };
        quantity.addEventListener('input', update);
        row.querySelector('[data-remove-selected-product]')?.addEventListener('click', () => {
            quantity.value = '0';
            quantity.dispatchEvent(new Event('input', { bubbles: true }));
            row.hidden = true; // Do not destroy selected files if added again.
        });
        update();
    };
    root.querySelectorAll('[data-product-row]').forEach(bindProduct);
    root.addEventListener('admin:product-added', (event) => bindProduct(event.detail.row));
    root.addEventListener('admin:story-added', populateChildSelects);

    const renderResults = () => {
        results.replaceChildren();
        const matches = catalogMatches(catalog, activeType, search.value);
        if (!matches.length) {
            results.textContent = 'لا توجد نتائج بهذا الاسم.';
            return;
        }
        matches.slice(0, 30).forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'rounded-xl border border-indigo-100 bg-indigo-50 p-3 text-right text-sm font-black text-indigo-800';
            button.textContent = `+ ${item.name}`;
            button.addEventListener('click', () => {
                itemMessage.textContent = '';
                if (item.type === 'story') {
                    root.dispatchEvent(new CustomEvent('admin:add-story', { detail: { storyId: item.id } }));
                } else {
                    let row = root.querySelector(`[data-product-row][data-product-id="${item.id}"]`);
                    if (!row) {
                        const template = root.querySelector(`[data-product-template="${item.id}"]`);
                        root.querySelector('[data-product-rows]').append(template.content.cloneNode(true));
                        row = root.querySelector(`[data-product-row][data-product-id="${item.id}"]`);
                        root.dispatchEvent(new CustomEvent('admin:product-added', { detail: { row } }));
                    }
                    const quantity = row.querySelector('[data-product-quantity]');
                    const next = Math.max(0, Number(quantity.value)) + 1;
                    if (next > Number(quantity.max)) {
                        itemMessage.textContent = `الحد الأقصى لهذا المنتج ${quantity.max} نسخة.`;
                        return;
                    }
                    row.hidden = false;
                    quantity.value = String(next);
                    quantity.dispatchEvent(new Event('input', { bubbles: true }));
                    row.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                picker.hidden = true;
            });
            results.append(button);
        });
        if (matches.length > 30) {
            const hint = document.createElement('p');
            hint.className = 'text-xs font-bold text-gray-500';
            hint.textContent = 'اكتب جزءًا من الاسم لتضييق النتائج.';
            results.append(hint);
        }
    };
    const openPicker = (type) => {
        activeType = type;
        search.value = '';
        picker.hidden = false;
        renderResults();
        search.focus();
    };
    root.querySelectorAll('[data-open-catalog]').forEach((button) => button.addEventListener('click', () => openPicker(button.dataset.openCatalog)));
    root.addEventListener('admin:open-story-picker', () => openPicker('story'));
    search?.addEventListener('input', renderResults);
    root.querySelector('[data-close-catalog]')?.addEventListener('click', () => { picker.hidden = true; });
    populateChildSelects();
}
