// Admin editor only. Keep the typed, complete schema-1 API unchanged.
const labels = {
    production_typography: 'الخطوط وجانب النص الافتراضي', export_preferences: 'إعدادات تصدير PDF', ui_preferences: 'عرض منطقة التصميم',
    default_back_cover: 'الغلاف الخلفي الافتراضي', brand_logo: 'اللوجو الرسمي وموضعه', openai_cover: 'توليد أغلفة القصص والتلوين', text_style_presets: 'أنماط النص المحفوظة', product_production_profiles: 'قوالب إنتاج المنتجات المشتركة',
    default_font_id: 'الخط الافتراضي', default_font_weight: 'وزن الخط', default_text_box_page_side: 'جانب صفحة النص', default_mode: 'ترتيب صفحات التصدير', allow_warnings: 'السماح بتحذيرات التصدير', show_safe_area: 'إظهار المنطقة الآمنة', show_bleed: 'إظهار النزيف', show_gutter: 'إظهار هامش التجليد',
    enabled: 'تفعيل', model: 'موديل OpenAI', quality: 'الجودة', size: 'أبعاد الصورة بالبكسل', prompt_template: 'تعليمات غلاف القصة', coloring_book_prompt_template: 'تعليمات غلاف التلوين', use_identity_image: 'السماح بصورة الهوية عند اختيارها صراحة في المشروع', cover_style_notes: 'ملاحظات أسلوب الغلاف',
    crop: 'قص الغلاف', focalX: 'نقطة التركيز الأفقية (0–1)', focalY: 'نقطة التركيز الرأسية (0–1)', zoom: 'التكبير (1–4)', placement: 'موضع اللوجو', position: 'موضع اللوجو', x: 'الموضع الأفقي (0–1)', y: 'الموضع الرأسي (0–1)', widthPercent: 'نسبة العرض', opacity: 'ظهور اللوجو (0–1)',
    name: 'الاسم', style: 'تنسيق النص', fontId: 'الخط', fontWeight: 'وزن الخط', fontSizePt: 'حجم الخط بالنقطة', lineHeight: 'ارتفاع السطر', color: 'لون النص', backgroundColor: 'لون الخلفية', backgroundOpacity: 'ظهور الخلفية (0–1)', paddingTopMm: 'الهامش العلوي (مم)', paddingRightMm: 'الهامش الأيمن (مم)', paddingBottomMm: 'الهامش السفلي (مم)', paddingLeftMm: 'الهامش الأيسر (مم)', borderRadiusMm: 'تدوير الحواف (مم)', alignment: 'محاذاة النص', verticalAlignment: 'المحاذاة الرأسية', textDirection: 'اتجاه النص',
    workflow: 'نوع الإنتاج', match: 'ربط القالب بالمنتج', productId: 'رقم المنتج', sku: 'كود SKU', componentKey: 'مفتاح المكوّن', canvas: 'مقاس التصميم', widthMm: 'العرض (مم)', heightMm: 'الارتفاع (مم)', orientation: 'اتجاه التصميم', sideCount: 'عدد الأوجه', defaultCopies: 'عدد النسخ الافتراضي', cut: 'حدود القص والنزيف', showBorder: 'إظهار حد القص', borderColor: 'لون الحد', borderWidthMm: 'سمك الحد (مم)', borderInsetMm: 'إزاحة الحد (مم)', bleedMm: 'النزيف (مم)', templates: 'صور قوالب الأوجه', front: 'الوجه الأمامي', back: 'الوجه الخلفي', photo: 'صورة الطفل داخل القالب', side: 'الوجه', shape: 'شكل الصورة', rect: 'منطقة العنصر داخل التصميم (%)', xPercent: 'الموضع الأفقي (%)', yPercent: 'الموضع الرأسي (%)', heightPercent: 'الارتفاع (%)', fields: 'حقول التخصيص', sourceKey: 'مفتاح بيانات الحقل في المنتج', label: 'عنوان الحقل', visible: 'إظهار الحقل', showLabel: 'إظهار العنوان', showWhenEmpty: 'إظهاره إذا كان فارغًا', typography: 'تنسيق الحقل', direction: 'اتجاه النص',
};
const choices = {
    right: 'يمين', left: 'يسار', center: 'وسط', start: 'البداية', end: 'النهاية', justify: 'ضبط', rtl: 'من اليمين لليسار', ltr: 'من اليسار لليمين', 'reading-order': 'ترتيب القراءة', booklet: 'ملزمة للطباعة', portrait: 'رأسي', landscape: 'أفقي', front: 'أمامي', back: 'خلفي', 'personalized-card': 'بطاقة مخصصة', 'image-sticker-sheet': 'استيكرات صور', 'text-sticker-sheet': 'استيكرات نصوص', 'coloring-book': 'كتاب تلوين', oval: 'بيضاوي', circle: 'دائري', rectangle: 'مستطيل', low: 'منخفضة', medium: 'متوسطة', high: 'عالية', xhigh: 'عالية جدًا', max: 'قصوى', 'top-left': 'أعلى اليسار', 'top-center': 'أعلى الوسط', 'top-right': 'أعلى اليمين', 'center-left': 'منتصف اليسار', 'center-right': 'منتصف اليمين', 'bottom-left': 'أسفل اليسار', 'bottom-center': 'أسفل الوسط', 'bottom-right': 'أسفل اليمين', custom: 'موضع مخصص',
};
const rect = () => ({ xPercent: 10, yPercent: 10, widthPercent: 80, heightPercent: 30 });
export function newStudioEntry(key) {
    const now = new Date().toISOString(), id = crypto.randomUUID();
    if (key === 'text_style_presets') return { id, name: 'نمط نص جديد', createdAt: now, updatedAt: now, style: { fontId: 'cairo', fontWeight: 400, fontSizePt: 18, lineHeight: 1.5, color: '#000000', backgroundColor: '#ffffff', backgroundOpacity: 0, paddingTopMm: 0, paddingRightMm: 0, paddingBottomMm: 0, paddingLeftMm: 0, borderRadiusMm: 0, alignment: 'center', verticalAlignment: 'center', textDirection: 'rtl' } };
    if (key === 'fields') return { id, sourceKey: 'child_name', label: 'اسم الطفل', side: 'front', visible: true, showLabel: false, showWhenEmpty: false, rect: rect(), typography: { fontId: 'cairo', fontWeight: 400, fontSizePt: 18, color: '#000000', direction: 'rtl', alignment: 'center' } };
    return { schemaVersion: 1, id, name: 'قالب منتج جديد', workflow: 'personalized-card', match: { productId: null, sku: null, componentKey: null }, canvas: { widthMm: 60, heightMm: 90, orientation: 'portrait' }, sideCount: 1, defaultCopies: 1, cut: { showBorder: false, borderColor: '#000000', borderWidthMm: 0.5, borderInsetMm: 0, bleedMm: 0 }, templates: { front: null, back: null }, photo: { enabled: false, side: 'front', shape: 'oval', rect: rect() }, fields: [], updatedAt: now };
}
export function initializeStudioSettingsEditor(form) {
    const output = form.elements.settings_json, status = form.querySelector('[data-studio-editor-status]'), publish = form.querySelector('[data-studio-publish]');
    try {
        const settings = JSON.parse(output.value), options = JSON.parse(form.querySelector('[data-studio-editor-options]').textContent);
        let pending = 0;
        const sync = () => { output.value = JSON.stringify(settings); };
        const node = (tag, text = '', className = '') => { const el = document.createElement(tag); el.textContent = text; el.className = className; return el; };
        const button = (text, action) => { const el = node('button', text, 'rounded-lg border px-4 py-2 text-indigo-700'); el.type = 'button'; el.addEventListener('click', action); return el; };
        const inputClass = 'mt-1 w-full rounded-lg border-slate-300';
        function render(parent, key, schema, container, path, changed = () => {}) {
            const title = path === 'brand_logo.placement.widthPercent' ? 'عرض اللوجو (0.28 = 28% من الغلاف)' : labels[key] || key;
            if (['schemaVersion', 'createdAt', 'updatedAt', 'id'].includes(key)) return;
            if (typeof schema === 'object' && !Array.isArray(schema)) {
                const section = node('fieldset', '', 'space-y-4 rounded-xl border p-4'); section.append(node('legend', title, 'font-bold'));
                if (schema.asset_id) {
                    const select = node('select', '', inputClass); select.setAttribute('aria-label', `${path}: اختيار الصورة`);
                    const image = node('img'); image.alt = `معاينة ${title}`; image.className = 'h-36 max-w-full rounded object-contain';
                    const preview = () => { const asset = options.assets.find(a => a.id === parent[key]?.asset_id); image.hidden = !asset; if (asset) image.src = asset.preview_url; else image.removeAttribute('src'); };
                    const fill = () => { select.replaceChildren(new Option('بدون صورة', '')); options.assets.forEach((a, i) => select.add(new Option(`صورة ${i + 1} — ${a.width} × ${a.height}`, a.id))); select.value = parent[key]?.asset_id || ''; preview(); };
                    const children = node('div', '', 'grid gap-4 md:grid-cols-2');
                    const draw = () => { children.replaceChildren(); if (parent[key]) Object.entries(schema).filter(([k]) => !k.startsWith('@') && k !== 'asset_id').forEach(([k, s]) => render(parent[key], k, s, children, `${path}.${k}`, changed)); };
                    select.addEventListener('change', () => {
                        if (!select.value && parent[key] && !window.confirm('إزالة الصورة من النسخة الجديدة؟ ستظل النسخ المنشورة القديمة محفوظة.')) { select.value = parent[key].asset_id; return; }
                        parent[key] = select.value ? { ...(parent[key] || (key === 'default_back_cover' ? { crop: { focalX: 0.5, focalY: 0.5, zoom: 1 } } : key === 'brand_logo' ? { placement: { position: 'top-center', x: 0.36, y: 0.04, widthPercent: 0.28, opacity: 1 } } : {})), asset_id: select.value } : null;
                        changed(); sync(); draw(); preview();
                    });
                    const file = node('input'); file.type = 'file'; file.accept = 'image/png,image/jpeg,image/webp'; file.setAttribute('aria-label', `${path}: رفع صورة`);
                    const uploadStatus = node('p', '', 'text-sm text-slate-600'); uploadStatus.setAttribute('role', 'status');
                    file.addEventListener('change', async () => {
                        const imageFile = file.files[0]; if (!imageFile) return;
                        if (imageFile.size > 15 * 1024 * 1024) { uploadStatus.textContent = 'الحد الأقصى 15 ميجا.'; file.value = ''; return; }
                        pending++; publish.disabled = true; file.disabled = true; uploadStatus.textContent = 'جارٍ رفع الصورة وحمايتها…';
                        try {
                            const body = new FormData(); body.append('file', imageFile);
                            const response = await fetch(form.dataset.uploadUrl, { method: 'POST', credentials: 'same-origin', signal: AbortSignal.timeout(30000), headers: { Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value }, body });
                            if (!response.ok) throw new Error('upload');
                            const { data } = await response.json(); options.assets.unshift(data); fill(); select.value = data.id; select.dispatchEvent(new Event('change')); uploadStatus.textContent = 'تم الرفع. راجع الإعدادات ثم انشرها للفريق.';
                        } catch { uploadStatus.textContent = 'تعذر الرفع. استخدم PNG / JPEG / WebP حتى 15 ميجا و6000 بكسل لكل ضلع و25 مليون بكسل. لم تتغير الصورة السابقة.'; }
                        finally { pending--; publish.disabled = pending > 0; file.disabled = false; file.value = ''; }
                    });
                    section.append(select, image, node('p', 'رفع أو استبدال الصورة (PNG / JPEG / WebP، حتى 15 ميجا)', 'text-sm text-slate-600'), file, uploadStatus, children); fill(); draw();
                } else if (schema['@list']) {
                    const list = parent[key], entries = node('div', '', 'space-y-4');
                    const draw = () => {
                        entries.replaceChildren(); if (!list.length) entries.append(node('p', 'لا توجد عناصر محفوظة بعد.', 'text-sm text-slate-500'));
                        list.forEach((entry, index) => {
                            const card = node('details', '', 'rounded-xl border p-4'), summary = node('summary', entry.name || entry.label || `عنصر ${index + 1}`, 'cursor-pointer font-bold'); card.append(summary);
                            const fields = node('div', '', 'mt-4 grid gap-4 md:grid-cols-2');
                            const edit = () => { if ('updatedAt' in entry) entry.updatedAt = new Date().toISOString(); summary.textContent = entry.name || entry.label || `عنصر ${index + 1}`; changed(); };
                            Object.entries(schema['@list']).forEach(([k, s]) => render(entry, k, s, fields, `${path}.${index}.${k}`, edit));
                            card.append(fields, button('حذف هذا العنصر', () => { if (window.confirm('حذف هذا العنصر من النسخة الجديدة؟ لن تتغير النسخة المنشورة قبل تأكيد النشر.')) { list.splice(index, 1); changed(); sync(); draw(); } })); entries.append(card);
                        });
                    };
                    section.append(entries, button('إضافة عنصر', () => { if (list.length >= schema['@max']) return; list.push(newStudioEntry(key)); changed(); sync(); draw(); entries.lastElementChild.open = true; })); draw();
                } else {
                    const fields = node('div', '', 'grid gap-4 md:grid-cols-2'); Object.entries(schema).filter(([k]) => !k.startsWith('@')).forEach(([k, s]) => render(parent[key], k, s, fields, `${path}.${k}`, changed)); section.append(fields);
                }
                container.append(section); return;
            }
            const rules = Array.isArray(schema) ? schema : schema.split('|'), rule = prefix => rules.find(r => r.startsWith(`${prefix}:`))?.slice(prefix.length + 1);
            const label = node('label', title, 'block text-sm font-medium'), bool = rules.includes('boolean'), numeric = rules.includes('integer') || rules.includes('numeric');
            let values = rule('in')?.split(','); if (key === 'model') values = [...new Set([parent[key], ...options.models.map(m => m.code)])];
            if (key === 'fontId' || key === 'default_font_id') values = [...new Set([parent[key], 'cairo', 'baloo-bhaijaan-2', 'baloo-2'])];
            const long = ['prompt_template', 'coloring_book_prompt_template', 'cover_style_notes'].includes(key);
            const input = node(values ? 'select' : long ? 'textarea' : 'input', '', bool ? 'mx-2 rounded' : inputClass); input.setAttribute('aria-label', `${path}: ${title}`);
            if (values) values.forEach(v => input.add(new Option(choices[v] || options.models.find(m => m.code === v)?.name || v, v)));
            else if (!long) input.type = bool ? 'checkbox' : numeric ? 'number' : /Color$|^color$/.test(key) ? 'color' : 'text';
            if (long) { input.rows = key === 'cover_style_notes' ? 3 : 7; label.classList.add('md:col-span-2'); }
            if (bool) input.checked = parent[key]; else input.value = parent[key] ?? '';
            if (numeric) { input.step = rules.includes('integer') ? '1' : 'any'; const between = rule('between')?.split(','); if (between) [input.min, input.max] = between; if (rule('max')) input.max = rule('max'); if (rule('gt')) input.min = '0.001'; }
            else if (rule('max')) input.maxLength = Number(rule('max'));
            if (!bool && !rules.includes('nullable') && !rules.includes('present')) input.required = true;
            input.addEventListener('input', () => { parent[key] = bool ? input.checked : numeric ? (input.value === '' ? null : Number(input.value)) : (rules.includes('nullable') && input.value === '' ? null : input.value); changed(); sync(); });
            label.append(input); container.append(label);
        }
        const root = form.querySelector('[data-studio-fields]'); root.replaceChildren();
        ['default_back_cover', 'brand_logo', 'production_typography', 'export_preferences', 'ui_preferences', 'openai_cover', 'text_style_presets', 'product_production_profiles'].forEach(key => {
            const section = node('section', '', 'rounded-2xl border bg-white p-5'); render(settings, key, options.schema[key], section, key); root.append(section);
        });
        form.addEventListener('submit', event => { sync(); if (pending) { event.preventDefault(); status.textContent = 'انتظر انتهاء رفع الصور.'; } }); publish.disabled = false;
    } catch { status.textContent = 'تعذر فتح مسودة الإعدادات. أعد تحميل الصفحة؛ لن تُنشر إعدادات ناقصة.'; }
}
