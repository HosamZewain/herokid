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

export const initializeStorySceneEditor = () => {
    document.querySelectorAll('[data-story-scenes-editor]').forEach((root) => {
        const readiness = root.querySelector('[data-scene-readiness]');
        const items = Array.from(root.querySelectorAll('[data-scene-editor-item]'));
        const importButton = root.querySelector('[data-scene-import]');
        const importStatus = root.querySelector('[data-scene-import-status]');

        const updateReadiness = () => {
            const completed = items.filter((item) => item.querySelector('[data-scene-template]')?.value.trim()).length;

            if (readiness) {
                readiness.textContent = `${completed} من 13 مشهد مكتمل`;
            }

            items.forEach((item) => {
                const complete = Boolean(item.querySelector('[data-scene-template]')?.value.trim());
                const badge = item.querySelector('[data-scene-item-status]');

                if (!badge) {
                    return;
                }

                badge.textContent = complete ? 'مكتمل' : 'غير مكتمل';
                badge.classList.toggle('bg-emerald-100', complete);
                badge.classList.toggle('text-emerald-700', complete);
                badge.classList.toggle('bg-amber-100', !complete);
                badge.classList.toggle('text-amber-700', !complete);
            });
        };

        root.querySelectorAll('[data-scene-template]').forEach((textarea) => {
            textarea.addEventListener('input', updateReadiness);
        });

        importButton?.addEventListener('click', async () => {
            const fullStory = storyContent().trim();

            if (!fullStory) {
                setStatus(importStatus, 'أدخل القصة الكاملة أولاً ثم أعد محاولة الاستيراد.', true);
                return;
            }

            if (!window.confirm('سيتم استبدال حقول المشاهد الحالية بمعاينة مستخرجة من القصة الكاملة. هل تريد المتابعة؟')) {
                return;
            }

            importButton.disabled = true;
            setStatus(importStatus, 'جارٍ اكتشاف المشاهد...');

            try {
                const response = await fetch(importButton.dataset.importUrl, {
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

                payload.scenes.forEach((scene) => {
                    const item = items[Number(scene.scene_number) - 1];
                    const title = item?.querySelector('[data-scene-title]');
                    const text = item?.querySelector('[data-scene-template]');

                    if (title) {
                        title.value = scene.title ?? '';
                    }

                    if (text) {
                        text.value = scene.text_template ?? '';
                    }
                });

                updateReadiness();
                setStatus(importStatus, 'تم اكتشاف 13 مشهدًا وملء الحقول. راجعها ثم احفظ القصة.');
            } catch (error) {
                setStatus(importStatus, error.message || 'تعذر استيراد المشاهد.', true);
            } finally {
                importButton.disabled = false;
            }
        });

        updateReadiness();
    });
};
