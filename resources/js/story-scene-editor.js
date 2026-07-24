const setStatus = (element, message, isError = false) => {
    if (!element) {
        return;
    }

    element.textContent = message;
    element.classList.remove('hidden', 'text-red-600', 'text-emerald-700');
    element.classList.add(isError ? 'text-red-600' : 'text-emerald-700');
};

const storyContent = () => {
    const editor = window.tinymce?.get('full_story');

    if (editor) {
        editor.save();
        return editor.getContent();
    }

    return document.getElementById('full_story')?.value ?? '';
};

const setCompletionBadge = (badge, complete, prefix) => {
    if (!badge) {
        return;
    }

    badge.textContent = `${prefix}: ${complete ? 'مكتمل' : 'غير مكتمل'}`;
    badge.classList.toggle('bg-emerald-100', complete);
    badge.classList.toggle('text-emerald-700', complete);
    badge.classList.toggle('bg-amber-100', !complete);
    badge.classList.toggle('text-amber-700', !complete);
};

const parseScenes = async (button, fullStory) => {
    const response = await fetch(button.dataset.importUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ full_story: fullStory }),
    });
    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.message || payload.errors?.full_story?.[0] || 'تعذر استيراد المشاهد.');
    }

    return payload.scenes;
};

export const initializeStorySceneEditor = () => {
    document.querySelectorAll('[data-story-scenes-editor]').forEach((root) => {
        const originalReadiness = root.querySelector('[data-scene-readiness-original]');
        const alternateReadiness = root.querySelector('[data-scene-readiness-alternate]');
        const guidance = root.querySelector('[data-scene-gender-guidance]');
        const genderSelect = document.getElementById('gender');
        const items = Array.from(root.querySelectorAll('[data-scene-editor-item]'));
        const importButton = root.querySelector('[data-scene-import]');
        const importStatus = root.querySelector('[data-scene-import-status]');
        const alternateImportButton = root.querySelector('[data-scene-import-alternate]');
        const alternateImportStatus = root.querySelector('[data-scene-import-alternate-status]');
        const alternateImportSource = root.querySelector('[data-scene-alternate-import-source]');

        const selectedGender = () => genderSelect?.value || root.dataset.storyGender || 'both';

        const updateGenderLabels = () => {
            const gender = selectedGender();
            const isNeutral = gender === 'both';
            const originalLabel = gender === 'girl'
                ? 'النص الأساسي — بنت'
                : gender === 'boy'
                    ? 'النص الأساسي — ولد'
                    : 'النص الأساسي — محايد للجنسين';
            const alternateLabel = gender === 'girl'
                ? 'النص البديل — ولد'
                : gender === 'boy'
                    ? 'النص البديل — بنت'
                    : 'النص البديل — غير مستخدم';

            alternateReadiness?.classList.toggle('hidden', isNeutral);
            root.querySelector('[data-scene-alternate-import-panel]')?.classList.toggle('hidden', isNeutral);

            if (guidance) {
                guidance.textContent = gender === 'girl'
                    ? 'القصة الأصلية للبنات. اكتب في النسخة البديلة صياغة الأولاد؛ وأي مشهد بديل ناقص سيستخدم النص الأساسي مع تنبيه واضح في الطلب.'
                    : gender === 'boy'
                        ? 'القصة الأصلية للأولاد. اكتب في النسخة البديلة صياغة البنات؛ وأي مشهد بديل ناقص سيستخدم النص الأساسي مع تنبيه واضح في الطلب.'
                        : 'النص الأساسي محايد ويُستخدم لكل الطلبات في القصة المناسبة للجنسين. النص البديل محفوظ لكنه غير مستخدم.';
            }

            items.forEach((item) => {
                const original = item.querySelector('[data-scene-original-label]');
                const alternate = item.querySelector('[data-scene-alternate-label]');

                if (original) {
                    original.textContent = originalLabel;
                }

                if (alternate) {
                    alternate.textContent = alternateLabel;
                }

                item.querySelector('[data-scene-alternate-status]')?.classList.toggle('hidden', isNeutral);
                item.querySelector('[data-scene-alternate-field]')?.classList.toggle('hidden', isNeutral);
            });
        };

        const updateReadiness = () => {
            const originalCompleted = items.filter((item) => item.querySelector('[data-scene-original-template]')?.value.trim()).length;
            const alternateCompleted = items.filter((item) => item.querySelector('[data-scene-alternate-template]')?.value.trim()).length;

            if (originalReadiness) {
                originalReadiness.textContent = `الأساسي: ${originalCompleted} من 13`;
            }

            if (alternateReadiness) {
                alternateReadiness.textContent = `البديل: ${alternateCompleted} من 13`;
            }

            items.forEach((item) => {
                setCompletionBadge(
                    item.querySelector('[data-scene-original-status]'),
                    Boolean(item.querySelector('[data-scene-original-template]')?.value.trim()),
                    'الأساسي',
                );
                setCompletionBadge(
                    item.querySelector('[data-scene-alternate-status]'),
                    Boolean(item.querySelector('[data-scene-alternate-template]')?.value.trim()),
                    'البديل',
                );
            });
        };

        root.querySelectorAll('[data-scene-original-template], [data-scene-alternate-template]').forEach((textarea) => {
            textarea.addEventListener('input', updateReadiness);
        });

        genderSelect?.addEventListener('change', updateGenderLabels);

        importButton?.addEventListener('click', async () => {
            const fullStory = storyContent().trim();

            if (!fullStory) {
                setStatus(importStatus, 'أدخل القصة الكاملة أولاً ثم أعد محاولة الاستيراد.', true);
                return;
            }

            if (!window.confirm('سيتم استبدال العناوين والنصوص الأساسية الحالية بالمعاينة المستخرجة. هل تريد المتابعة؟')) {
                return;
            }

            importButton.disabled = true;
            setStatus(importStatus, 'جارٍ اكتشاف المشاهد...');

            try {
                const scenes = await parseScenes(importButton, fullStory);

                scenes.forEach((scene) => {
                    const item = items[Number(scene.scene_number) - 1];
                    const title = item?.querySelector('[data-scene-title]');
                    const text = item?.querySelector('[data-scene-original-template]');

                    if (title) {
                        title.value = scene.title ?? '';
                    }

                    if (text) {
                        text.value = scene.text_template ?? '';
                    }
                });

                updateReadiness();
                setStatus(importStatus, 'تم ملء النص الأساسي والعناوين. راجعها ثم احفظ القصة.');
            } catch (error) {
                setStatus(importStatus, error.message || 'تعذر استيراد المشاهد.', true);
            } finally {
                importButton.disabled = false;
            }
        });

        alternateImportButton?.addEventListener('click', async () => {
            const fullStory = alternateImportSource?.value.trim() ?? '';

            if (!fullStory) {
                setStatus(alternateImportStatus, 'الصق القصة البديلة أولاً ثم أعد المحاولة.', true);
                return;
            }

            if (!window.confirm('سيتم استبدال النصوص البديلة الحالية فقط. لن تتغير العناوين ولن يتم الحفظ تلقائيًا. هل تريد المتابعة؟')) {
                return;
            }

            alternateImportButton.disabled = true;
            setStatus(alternateImportStatus, 'جارٍ اكتشاف المشاهد البديلة...');

            try {
                const scenes = await parseScenes(alternateImportButton, fullStory);

                scenes.forEach((scene) => {
                    const item = items[Number(scene.scene_number) - 1];
                    const text = item?.querySelector('[data-scene-alternate-template]');

                    if (text) {
                        text.value = scene.text_template ?? '';
                    }
                });

                updateReadiness();
                setStatus(alternateImportStatus, 'تم ملء 13 نصًا بديلًا فقط. راجعها ثم احفظ القصة.');
            } catch (error) {
                setStatus(alternateImportStatus, error.message || 'تعذر استيراد النسخة البديلة.', true);
            } finally {
                alternateImportButton.disabled = false;
            }
        });

        updateGenderLabels();
        updateReadiness();
    });
};
