// Admin editor only. Keep the typed, complete schema-1 API unchanged.
import { uploadStudioAsset } from './studio-asset-upload.js';
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
export function studioDisplayScale(path) {
    return /^brand_logo\.placement\.(x|y|widthPercent|opacity)$/.test(path)
        || /^default_back_cover\.crop\.(focalX|focalY)$/.test(path)
        || path.endsWith('.backgroundOpacity') ? 100 : 1;
}
const descriptions = {
    default_back_cover: 'صورة تُستخدم تلقائيًا خلف كل قصة، ما لم تختَر غلافًا مختلفًا داخل المشروع.',
    brand_logo: 'اللوجو الذي يضيفه Studio فوق الغلاف المولّد. يُفضّل PNG أو WebP بخلفية شفافة.',
    production_typography: 'قيم البداية للمشاريع الجديدة. يمكنك تعديل كل مشروع لاحقًا داخل Studio.',
    export_preferences: 'ترتيب القراءة للنسخة الرقمية، وملزمة للطباعة لتجهيز صفحات التجليد.',
    ui_preferences: 'خطوط إرشادية داخل المحرر: المنطقة الآمنة للنص، والنزيف خارج القص، وهامش التجليد.',
    openai_cover: 'نفس إعدادات توليد الغلاف في التطبيق، لكن مشتركة للفريق. المفتاح يبقى على الخادم.',
    text_style_presets: 'تنسيقات نص جاهزة يعيد الفريق استخدامها بدل ضبط الخط والهوامش في كل مرة.',
    product_production_profiles: 'قوالب المنتجات: المقاس، الأوجه، الصور، الحقول وحدود القص. لا تغيّر إعدادات القصص.',
};
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
        let pending = 0, failed = 0, dirty = false, submitting = false;
        const assetRefreshers = new Set();
        const updateState = () => {
            publish.disabled = pending > 0 || failed > 0;
            status.textContent = pending ? `جارٍ رفع ${pending} صورة؛ انتظر اكتمال الرفع قبل النشر.` : failed ? 'هناك صورة لم يكتمل رفعها. أعد المحاولة أو اختر «الاحتفاظ بالصورة السابقة».' : dirty ? 'لديك تغييرات غير منشورة. نشر الإعدادات يحفظ كل الأقسام معًا.' : 'لم تُجرَ تغييرات بعد.';
        };
        const sync = () => { output.value = JSON.stringify(settings); dirty = true; updateState(); };
        window.addEventListener('beforeunload', event => { if (!submitting && (dirty || pending || failed)) { event.preventDefault(); event.returnValue = ''; } });
        const node = (tag, text = '', className = '') => { const el = document.createElement(tag); el.textContent = text; el.className = className; return el; };
        const button = (text, action) => { const el = node('button', text, 'rounded-lg border px-4 py-2 text-indigo-700'); el.type = 'button'; el.addEventListener('click', action); return el; };
        const inputClass = 'mt-1 w-full rounded-lg border-slate-300';
        function render(parent, key, schema, container, path, changed = () => {}) {
            const scale = studioDisplayScale(path);
            const percentLabels = { x: 'الموضع الأفقي (%)', y: 'الموضع الرأسي (%)', widthPercent: 'عرض اللوجو من الغلاف (%)', opacity: 'ظهور اللوجو (%)', focalX: 'نقطة التركيز الأفقية (%)', focalY: 'نقطة التركيز الرأسية (%)', backgroundOpacity: 'ظهور الخلفية (%)' };
            const title = scale === 100 ? percentLabels[key] : labels[key] || key;
            if (['schemaVersion', 'createdAt', 'updatedAt', 'id'].includes(key)) return;
            if (typeof schema === 'object' && !Array.isArray(schema)) {
                const section = node('fieldset', '', 'min-w-0 space-y-4 rounded-xl border p-4'); section.append(node('legend', title, 'font-bold'));
                if (descriptions[path]) section.append(node('p', descriptions[path], 'text-sm leading-7 text-slate-500'));
                if (schema.asset_id) {
                    const select = node('select', '', inputClass); select.setAttribute('aria-label', `${path}: اختيار الصورة`);
                    const image = node('img'); image.alt = `معاينة ${title}`; image.className = 'h-36 max-w-full rounded object-contain';
                    const metadata = node('p', '', 'text-sm text-slate-500');
                    const preview = () => { const asset = options.assets.find(a => a.id === parent[key]?.asset_id); image.hidden = !asset; metadata.textContent = asset ? `${asset.width} × ${asset.height} بكسل · ${(asset.bytes / 1024 / 1024).toFixed(2)} ميجا · مختارة في هذه المسودة` : 'لم تُختَر صورة بعد.'; if (asset) image.src = asset.preview_url; else image.removeAttribute('src'); };
                    const fill = () => { select.replaceChildren(new Option('بدون صورة', '')); options.assets.forEach((a, i) => select.add(new Option(`صورة محفوظة ${i + 1} — ${a.width} × ${a.height}`, a.id))); select.value = parent[key]?.asset_id || ''; preview(); };
                    assetRefreshers.add(() => { if (select.isConnected) fill(); });
                    const children = node('div', '', 'grid gap-4 md:grid-cols-2');
                    const draw = () => { children.replaceChildren(); if (parent[key]) Object.entries(schema).filter(([k]) => !k.startsWith('@') && k !== 'asset_id').forEach(([k, s]) => render(parent[key], k, s, children, `${path}.${k}`, changed)); };
                    select.addEventListener('change', () => {
                        if (!select.value && parent[key] && !window.confirm('إزالة الصورة من النسخة الجديدة؟ ستظل النسخ المنشورة القديمة محفوظة.')) { select.value = parent[key].asset_id; return; }
                        parent[key] = select.value ? { ...(parent[key] || (key === 'default_back_cover' ? { crop: { focalX: 0.5, focalY: 0.5, zoom: 1 } } : key === 'brand_logo' ? { placement: { position: 'top-center', x: 0.36, y: 0.04, widthPercent: 0.28, opacity: 1 } } : {})), asset_id: select.value } : null;
                        changed(); sync(); draw(); preview();
                    });
                    const file = node('input', '', 'block w-full text-sm'); file.type = 'file'; file.accept = 'image/png,image/jpeg,image/webp'; file.setAttribute('aria-label', `${path}: رفع صورة`);
                    const uploadStatus = node('p', '', 'text-sm text-slate-600'); uploadStatus.setAttribute('role', 'status');
                    const progress = node('progress', '', 'w-full accent-indigo-600'); progress.max = 100; progress.hidden = true; progress.setAttribute('aria-label', `تقدم رفع ${title}`);
                    let selectedFile, task, hasFailed = false;
                    const clearFailure = () => { if (hasFailed) { failed--; hasFailed = false; } retry.hidden = discard.hidden = true; updateState(); };
                    const retry = button('إعادة المحاولة', () => startUpload()); retry.hidden = true;
                    const discard = button('الاحتفاظ بالصورة السابقة', () => { clearFailure(); selectedFile = null; uploadStatus.textContent = 'تم الاحتفاظ بالصورة السابقة؛ يمكنك متابعة التعديل.'; }); discard.hidden = true;
                    const cancel = button('إلغاء الرفع', () => task?.cancel()); cancel.hidden = true;
                    const startUpload = async () => {
                        clearFailure(); pending++; updateState(); file.disabled = select.disabled = true; cancel.hidden = false; progress.hidden = false; progress.value = 0;
                        const started = Date.now(); let percentage = 0;
                        const showProgress = () => { uploadStatus.textContent = percentage === 100 ? `وصل الملف للخادم؛ جارٍ فحص الصورة وحفظها… (${Math.floor((Date.now() - started) / 1000)} ثانية)` : `جارٍ رفع ${selectedFile.name} — ${percentage === null ? 'جارٍ الإرسال' : percentage + '%'} (${Math.floor((Date.now() - started) / 1000)} ثانية)`; };
                        showProgress(); const timer = setInterval(showProgress, 1000);
                        try {
                            task = uploadStudioAsset({ url: form.dataset.uploadUrl, csrf: form.elements._token.value, file: selectedFile, onProgress: value => { percentage = value; if (value === null) progress.removeAttribute('value'); else progress.value = value; showProgress(); } });
                            const data = await task.promise; options.assets.unshift(data); assetRefreshers.forEach(refresh => refresh()); select.value = data.id; select.dispatchEvent(new Event('change')); uploadStatus.textContent = 'تم رفع الصورة وحفظها. انشر الإعدادات لتصل للفريق.';
                        } catch (error) { hasFailed = true; failed++; retry.hidden = discard.hidden = false; uploadStatus.textContent = error.message; }
                        finally { clearInterval(timer); pending--; updateState(); file.disabled = select.disabled = false; file.value = ''; cancel.hidden = progress.hidden = true; task = null; }
                    };
                    file.addEventListener('change', () => {
                        const imageFile = file.files[0]; if (!imageFile) return;
                        const max = options.maxUploadBytes ?? 15 * 1024 * 1024;
                        if (imageFile.size > max || !['image/png', 'image/jpeg', 'image/webp'].includes(imageFile.type)) { uploadStatus.textContent = `اختر PNG أو JPEG أو WebP حتى ${max / 1024 / 1024} ميجا. لم تتغير الصورة السابقة.`; file.value = ''; return; }
                        selectedFile = imageFile; startUpload();
                    });
                    const library = node('details', '', 'rounded-lg border p-3'); library.append(node('summary', 'اختيار صورة مرفوعة سابقًا أو إزالة الصورة', 'cursor-pointer text-sm'), select);
                    const actions = node('div', '', 'flex flex-wrap gap-2'); actions.append(cancel, retry, discard);
                    section.append(image, metadata, node('p', `رفع أو استبدال الصورة (PNG / JPEG / WebP، حتى ${(options.maxUploadBytes ?? 15728640) / 1048576} ميجا)`, 'text-sm text-slate-600'), file, progress, uploadStatus, actions, library, children); fill(); draw();
                } else if (schema['@list']) {
                    const list = parent[key], entries = node('div', '', 'space-y-4');
                    const draw = () => {
                        entries.replaceChildren(); if (!list.length) entries.append(node('p', 'لا توجد عناصر محفوظة بعد.', 'text-sm text-slate-500'));
                        list.forEach((entry, index) => {
                            const card = node('details', '', 'rounded-xl border p-4'), summary = node('summary', entry.name || entry.label || `عنصر ${index + 1}`, 'cursor-pointer font-bold'); card.append(summary);
                            const fields = node('div', '', 'mt-4 grid gap-4 md:grid-cols-2');
                            const edit = () => { if ('updatedAt' in entry) entry.updatedAt = new Date().toISOString(); summary.textContent = entry.name || entry.label || `عنصر ${index + 1}`; changed(); };
                            Object.entries(schema['@list']).forEach(([k, s]) => render(entry, k, s, fields, `${path}.${index}.${k}`, edit));
                            card.append(fields, button('حذف هذا العنصر', () => { if (pending || failed) { updateState(); return; } if (window.confirm('حذف هذا العنصر من النسخة الجديدة؟ لن تتغير النسخة المنشورة قبل تأكيد النشر.')) { list.splice(index, 1); changed(); sync(); draw(); } })); entries.append(card);
                        });
                    };
                    section.append(entries, button(key === 'text_style_presets' ? 'إضافة نمط نص' : key === 'fields' ? 'إضافة حقل تخصيص' : 'إضافة قالب منتج', () => { if (pending || failed) { updateState(); return; } if (list.length >= schema['@max']) return; list.push(newStudioEntry(key)); changed(); sync(); draw(); entries.lastElementChild.open = true; })); draw();
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
            if (bool) input.checked = parent[key]; else input.value = numeric && parent[key] != null ? Number((parent[key] * scale).toFixed(6)) : parent[key] ?? '';
            if (numeric) { input.step = rules.includes('integer') ? '1' : 'any'; const between = rule('between')?.split(','); if (between) [input.min, input.max] = between; if (rule('max')) input.max = rule('max'); if (rule('gt')) input.min = '0.001'; }
            if (scale === 100) { input.min = Number(input.min) * scale; input.max = Number(input.max) * scale; input.step = '0.1'; }
            if (!numeric && rule('max')) input.maxLength = Number(rule('max'));
            if (!bool && !rules.includes('nullable') && !rules.includes('present')) input.required = true;
            input.addEventListener('input', () => { parent[key] = bool ? input.checked : numeric ? (input.value === '' ? null : Number(input.value) / scale) : (rules.includes('nullable') && input.value === '' ? null : input.value); changed(); sync(); });
            label.append(input);
            if (key === 'prompt_template' || key === 'coloring_book_prompt_template') label.append(node('small', 'المتغيرات المتاحة: {{story_title}}، {{story_theme}}، {{child_name}}، {{child_age}}، {{child_gender}}، {{language}}، {{dedication}}، {{cover_style_notes}}. استبدالها يتم عند التوليد.', 'mt-2 block text-slate-500'));
            container.append(label);
        }
        const root = form.querySelector('[data-studio-fields]'); root.replaceChildren();
        const navigation = form.querySelector('[data-studio-navigation]'), panels = [], tabs = [], providerHelp = form.querySelector('[data-studio-provider-help]');
        const groups = [
            ['الصور واللوجو', ['default_back_cover', 'brand_logo']],
            ['النص والطباعة', ['production_typography', 'export_preferences', 'ui_preferences', 'text_style_presets']],
            ['توليد الأغلفة', ['openai_cover']],
            ['قوالب المنتجات', ['product_production_profiles']],
        ];
        const activate = index => { panels.forEach((panel, i) => { panel.hidden = i !== index; tabs[i].setAttribute('aria-selected', String(i === index)); tabs[i].tabIndex = i === index ? 0 : -1; tabs[i].className = i === index ? 'rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white' : 'rounded-xl border bg-white px-5 py-3 text-slate-600'; }); if (providerHelp) providerHelp.hidden = index !== 2; };
        groups.forEach(([name, keys], index) => {
            const tab = button(name, () => activate(index)); tab.id = `studio-tab-${index}`; tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', `studio-panel-${index}`);
            tab.addEventListener('keydown', event => { if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return; event.preventDefault(); const next = event.key === 'Home' ? 0 : event.key === 'End' ? groups.length - 1 : (index + (event.key === 'ArrowLeft' ? 1 : -1) + groups.length) % groups.length; activate(next); tabs[next].focus(); });
            tabs.push(tab); navigation.append(tab);
            const panel = node('div', '', 'space-y-5'); panel.id = `studio-panel-${index}`; panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id); panels.push(panel); root.append(panel);
            keys.forEach(key => { const section = node('section', '', 'rounded-2xl border bg-white p-5'); render(settings, key, options.schema[key], section, key); panel.append(section); });
        });
        // Hidden tabs/details must reveal invalid inputs before native validation focuses them.
        form.addEventListener('invalid', event => { const index = panels.findIndex(panel => panel.contains(event.target)); if (index >= 0) activate(index); let parent = event.target.parentElement; while (parent && parent !== form) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; } }, true);
        form.noValidate = true;
        form.addEventListener('submit', event => {
            output.value = JSON.stringify(settings);
            if (pending || failed) { event.preventDefault(); updateState(); return; }
            const invalid = [...form.querySelectorAll('input,select,textarea')].find(input => input.willValidate && !input.validity.valid);
            if (invalid) { event.preventDefault(); invalid.reportValidity(); return; }
            submitting = true;
        });
        activate(0); updateState();
    } catch { status.textContent = 'تعذر فتح مسودة الإعدادات. أعد تحميل الصفحة؛ لن تُنشر إعدادات ناقصة.'; }
}
