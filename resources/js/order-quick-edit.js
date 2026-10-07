export function changedPersonalization(values, initial) {
    return Object.fromEntries(Object.entries(values).filter(([key, value]) => String(value ?? '') !== String(initial[key] ?? '')));
}

export function filteredProducts(products, query) {
    const search = query.trim().toLocaleLowerCase();
    return products.filter((product) => product.name.toLocaleLowerCase().includes(search));
}

export function initializeOrderQuickEdit() {
    const root = document.querySelector('[data-order-quick-edit]');
    if (!root || root.dataset.initialized) return;
    root.dataset.initialized = '1';
    const dialog = root.querySelector('[data-quick-dialog]');
    const form = root.querySelector('[data-quick-form]');
    const fields = root.querySelector('[data-quick-fields]');
    const errors = root.querySelector('[data-quick-errors]');
    const save = root.querySelector('[data-quick-save]');
    const status = root.querySelector('[data-quick-status]');
    let options = null;
    let mode = null;
    let currentItem = null;
    let currentStory = null;
    let busy = false;
    let activeProduct = null;
    let requestKey = null;
    let opening = 0;

    const element = (tag, text, className) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    };
    const showError = (message) => { errors.textContent = message; errors.hidden = !message; };
    const input = (container, label, name, value = '', type = 'text') => {
        const wrapper = element('label', undefined, 'block text-xs font-bold text-gray-700');
        wrapper.append(element('span', label));
        const node = element(type === 'textarea' ? 'textarea' : 'input');
        if (type !== 'textarea') node.type = type;
        if (type === 'textarea') node.rows = 3;
        node.name = name;
        if (type !== 'file') node.value = value ?? '';
        node.className = 'mt-1 block w-full rounded-xl border-gray-200 bg-white text-sm';
        wrapper.append(node);
        container.append(wrapper);
        return node;
    };
    const select = (container, label, name, entries, value = '') => {
        const wrapper = element('label', undefined, 'block text-xs font-bold text-gray-700');
        wrapper.append(element('span', label));
        const node = element('select');
        node.name = name;
        node.className = 'mt-1 block w-full rounded-xl border-gray-200 bg-white text-sm';
        entries.forEach(([id, title]) => {
            const option = element('option', title);
            option.value = id;
            node.append(option);
        });
        if (value !== null && value !== undefined && value !== '' && !entries.some(([id]) => String(id) === String(value))) {
            const historical = element('option', `القيمة المسجلة: ${value}`);
            historical.value = value;
            node.append(historical);
        }
        node.value = value ?? '';
        wrapper.append(node);
        container.append(wrapper);
        return node;
    };
    const photoPreview = (container, urls) => {
        container.replaceChildren();
        urls.forEach((url) => {
            const img = element('img');
            img.src = url;
            img.alt = 'صورة الطفل الموجودة بالطلب';
            img.className = 'h-16 w-16 rounded-xl object-cover';
            container.append(img);
        });
    };
    const renderPersonalization = (container, schema, values = {}) => {
        container.replaceChildren();
        Object.entries(schema.fields ?? {}).filter(([, field]) => field.enabled).forEach(([key, field]) => {
            let node;
            const label = field.label + (field.required && field.type !== 'photos' ? ' *' : '');
            const name = `personalization[${key}]`;
            if (field.type === 'photos') {
                if (!options.can_upload_photos) return;
                node = input(container, mode === 'item' ? 'إضافة صور للطفل (الصور الحالية لن تُحذف)' : field.label, 'photos[]', '', 'file');
                node.multiple = true;
                node.accept = 'image/*,.heic,.heif';
                return;
            }
            if (field.type === 'gender') {
                node = select(container, label, name, [['', 'اختر الجنس'], ['boy', 'ولد'], ['girl', 'بنت']], values[key]);
            } else if (field.type === 'age') {
                node = select(container, label, name, [['', 'اختر العمر'], ...Array.from({ length: 15 }, (_, i) => [i + 2, `${i + 2} سنوات`])], values[key]);
            } else {
                node = input(container, label, name, values[key], field.type === 'textarea' ? 'textarea' : 'text');
                node.maxLength = { child_name: 100, school_name: 150, class_name: 100, interests: 500, parent_notes: 1000 }[key] ?? 255;
            }
            // Old purchases may legitimately lack fields introduced later in the catalog.
            node.required = mode === 'add' && field.required;
        });
    };

    const renderAdd = () => {
        const search = input(fields, 'ابحث عن منتج', '', '', 'search');
        search.placeholder = 'اكتب اسم المنتج لتقليل الاختيارات';
        const productSelect = select(fields, 'المنتج *', 'product_id', [['', 'اختر المنتج']]);
        productSelect.required = true;
        const productFields = element('div', undefined, 'space-y-3');
        fields.append(productFields);
        const populateProducts = () => {
            const selected = productSelect.value;
            productSelect.replaceChildren();
            [['', 'اختر المنتج'], ...filteredProducts(options.products, search.value).map((p) => [p.id, p.name])].forEach(([id, title]) => {
                const option = element('option', title);
                option.value = id;
                productSelect.append(option);
            });
            // Keep the selected product available while refining a search.
            if (selected && !Array.from(productSelect.options).some((o) => o.value === selected)) {
                const product = options.products.find((p) => String(p.id) === selected);
                if (product) { const option = element('option', product.name); option.value = selected; productSelect.append(option); }
            }
            productSelect.value = selected;
        };
        search.addEventListener('input', populateProducts);
        populateProducts();
        productSelect.addEventListener('change', () => {
            productFields.replaceChildren();
            activeProduct = options.products.find((p) => String(p.id) === productSelect.value);
            if (!activeProduct) return;
            const price = element('p', `سعر الوحدة الحالي: ${activeProduct.price} ج.م`, 'text-sm font-black text-indigo-700');
            productFields.append(price);
            if (activeProduct.variants.length) {
                const variant = select(productFields, 'خيار المنتج *', 'variant_id', [['', 'اختر الخيار'], ...activeProduct.variants.map((v) => [v.id, `${v.name} — ${v.price} ج.م`])]);
                variant.required = true;
                variant.addEventListener('change', () => {
                    const selected = activeProduct.variants.find((v) => String(v.id) === variant.value);
                    price.textContent = `سعر الوحدة الحالي: ${selected?.price ?? activeProduct.price} ج.م`;
                });
            }
            const quantity = input(productFields, 'العدد *', 'quantity', 1, 'number');
            quantity.min = 1; quantity.max = 20; quantity.required = true;
            if (activeProduct.linked) {
                const linked = select(productFields, 'الطفل / القصة المرتبط بها المنتج *', 'linked_order_id', [['', 'اختر القصة'], ...options.children.filter((c) => c.story).map((c) => [c.order_id, c.label ?? c.name])]);
                linked.required = true;
                return;
            }
            if (!Object.values(activeProduct.schema.fields ?? {}).some((field) => field.enabled)) return;
            productFields.append(element('p', 'إذا كان العدد أكثر من واحد، تُستخدم بيانات هذا الطفل لكل نسخة.', 'text-xs font-bold text-gray-500'));
            const reuse = select(productFields, 'بيانات الطفل', 'reuse_child_order_id', [['', 'طفل جديد / إدخال يدوي'], ...options.children.map((c) => [c.order_id, c.label ?? c.name])]);
            const previews = element('div', undefined, 'flex flex-wrap gap-2');
            const personalization = element('div', undefined, 'space-y-3');
            productFields.append(previews, personalization);
            renderPersonalization(personalization, activeProduct.schema);
            reuse.addEventListener('change', () => {
                const child = options.children.find((c) => String(c.order_id) === reuse.value);
                renderPersonalization(personalization, activeProduct.schema, child?.values ?? {});
                photoPreview(previews, child?.photos ?? []);
            });
        });
    };

    document.querySelectorAll('[data-quick-open]').forEach((button) => button.addEventListener('click', async () => {
        if (busy) return;
        mode = button.dataset.quickOpen;
        form.reset(); fields.replaceChildren(); showError(''); status.textContent = '';
        currentItem = null; currentStory = null; activeProduct = null;
        requestKey = crypto.randomUUID();
        const thisOpening = ++opening;
        save.disabled = true;
        dialog.showModal();
        root.querySelector('[data-quick-title]').textContent = { contact: 'تعديل بيانات التواصل', add: 'إضافة منتج إلى الطلب', item: 'تعديل بيانات المنتج / الصور', story: 'تعديل بيانات القصة / الصور' }[mode];
        if (mode === 'contact') {
            const contact = JSON.parse(root.dataset.contact);
            const name = input(fields, 'اسم ولي الأمر *', 'parent_name', contact.parent_name); name.required = true; name.maxLength = 150;
            const phone = input(fields, 'رقم الموبايل *', 'phone', contact.phone, 'tel'); phone.required = true; phone.dir = 'ltr';
            const extra = input(fields, 'رقم إضافي (اختياري)', 'alternate_phone', contact.alternate_phone, 'tel'); extra.dir = 'ltr';
            save.disabled = false;
            return;
        }
        status.textContent = 'جارٍ تحميل البيانات…';
        try {
            // Refetch when opening to avoid stale product settings or children after another employee's edit.
            const response = await fetch(root.dataset.optionsUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('تعذر تحميل بيانات التعديل. تحقق من الصلاحيات وحاول مرة أخرى.');
            const loaded = await response.json();
            if (!dialog.open || thisOpening !== opening) return;
            options = loaded;
            if (mode === 'add') renderAdd();
            else if (mode === 'story') {
                currentStory = options.stories.find((story) => String(story.order_id) === button.dataset.orderId);
                if (!currentStory) throw new Error('تعذر العثور على القصة الحالية. أعد تحميل الصفحة.');
                const v = currentStory.values;
                input(fields, 'اسم الطفل *', 'child_name', v.child_name).required = true;
                const age = input(fields, 'عمر الطفل *', 'child_age', v.child_age, 'number'); age.required = true; age.min = 1; age.max = 18;
                select(fields, 'جنس الطفل *', 'child_gender', [['boy', 'ولد'], ['girl', 'بنت']], v.child_gender).required = true;
                select(fields, 'لغة القصة *', 'language', [['ar', 'العربية'], ['en', 'English']], v.language || 'ar').required = true;
                [['lesson', 'الدرس أو القيمة', 500], ['interests', 'اهتمامات الطفل', 1000], ['gift_note', 'الإهداء', 1000], ['parent_notes', 'ملاحظات ولي الأمر', 2000]].forEach(([key, label, max]) => {
                    input(fields, label, key, v[key], 'textarea').maxLength = max;
                });
                const child = options.children.find((c) => c.order_id === currentStory.order_id);
                const previews = element('div', undefined, 'flex flex-wrap gap-2'); photoPreview(previews, child?.photos ?? []); fields.append(previews);
                if (options.can_upload_photos) {
                    const photos = input(fields, 'إضافة صور للطفل (الصور الحالية لن تُحذف)', 'photos[]', '', 'file'); photos.multiple = true; photos.accept = 'image/*,.heic,.heif';
                }
            } else {
                currentItem = options.items.find((item) => String(item.id) === button.dataset.itemId);
                if (!currentItem || currentItem.linked) throw new Error('هذا المنتج مرتبط بقصة؛ عدّل بيانات الطفل من القصة المرتبطة.');
                const child = options.children.find((c) => c.order_id === currentItem.order_id);
                const previews = element('div', undefined, 'flex flex-wrap gap-2');
                photoPreview(previews, child?.photos ?? []); fields.append(previews);
                const personalization = element('div', undefined, 'space-y-3'); fields.append(personalization);
                renderPersonalization(personalization, currentItem.schema, currentItem.values);
            }
            status.textContent = ''; save.disabled = false;
        } catch (error) {
            if (!dialog.open || thisOpening !== opening) return;
            status.textContent = ''; showError(error.message);
        }
    }));

    const close = () => { if (!busy) dialog.close(); };
    root.querySelector('[data-quick-close]').addEventListener('click', close);
    root.querySelector('[data-quick-cancel]').addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => { if (busy) event.preventDefault(); });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; save.disabled = true; showError(''); status.textContent = 'جارٍ حفظ التعديل…';
        const body = new FormData(form);
        if (mode === 'add') body.append('request_key', requestKey);
        body.append('_token', document.querySelector('meta[name="csrf-token"]').content);
        let url = root.dataset.addUrl;
        if (mode === 'contact') {
            url = root.dataset.contactUrl; body.append('_method', 'PATCH');
            const initial = JSON.parse(root.dataset.contact);
            const submitted = Object.fromEntries(Object.keys(initial).map((key) => [key, body.get(key)]));
            const changed = changedPersonalization(submitted, initial);
            Object.keys(submitted).forEach((key) => { if (!(key in changed)) body.delete(key); });
        }
        if (mode === 'item') {
            url = root.dataset.itemUrl.replace('__ITEM__', currentItem.id); body.append('_method', 'PATCH');
            const submitted = Object.fromEntries(Array.from(body.entries()).filter(([key]) => key.startsWith('personalization[')).map(([key, value]) => [key.slice(16, -1), value]));
            const changed = changedPersonalization(submitted, currentItem.values);
            Object.keys(submitted).forEach((key) => { if (!(key in changed)) body.delete(`personalization[${key}]`); });
        }
        if (mode === 'story') {
            url = root.dataset.storyUrl.replace('__ORDER__', currentStory.order_id); body.append('_method', 'PATCH');
            const submitted = Object.fromEntries(Object.keys(currentStory.values).map((key) => [key, body.get(key)]));
            const changed = changedPersonalization(submitted, currentStory.values);
            Object.keys(submitted).forEach((key) => { if (!(key in changed)) body.delete(key); });
        }
        // A blank file input is not an attempted upload.
        if (!Array.from(body.getAll('photos[]')).some((file) => file.name)) body.delete('photos[]');
        try {
            const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' } });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const messages = Object.values(payload.errors ?? {}).flat();
                throw new Error(messages.join('\n') || (response.status === 419 ? 'انتهت الجلسة. افتح الصفحة من جديد ثم حاول الحفظ.' : payload.message || 'تعذر حفظ التعديل.'));
            }
            status.textContent = payload.message;
            window.location.reload();
        } catch (error) { showError(error.message || 'تعذر الاتصال. تحقق من سجل النشاط قبل إعادة إضافة المنتج.'); status.textContent = ''; }
        finally { busy = false; save.disabled = false; }
    });
}
