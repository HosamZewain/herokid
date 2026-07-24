const fallbackCopy = (text) => {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
};

const copyText = async (text) => {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return;
        } catch {
            fallbackCopy(text);
            return;
        }
    }

    fallbackCopy(text);
};

const showStatus = (element, message) => {
    if (!element) {
        return;
    }

    element.textContent = message;
    element.classList.remove('hidden');
    window.clearTimeout(element.sceneCopyTimeout);
    element.sceneCopyTimeout = window.setTimeout(() => element.classList.add('hidden'), 2500);
};

export const initializeOrderSceneTexts = () => {
    document.querySelectorAll('[data-order-scene-texts]').forEach((root) => {
        const sceneItems = Array.from(root.querySelectorAll('[data-scene-text-item]'));
        const globalStatus = root.querySelector('[data-scene-text-global-status]');

        root.querySelector('[data-scene-text-open-all]')?.addEventListener('click', () => {
            sceneItems.forEach((item) => {
                item.open = true;
            });
        });

        root.querySelector('[data-scene-text-close-all]')?.addEventListener('click', () => {
            sceneItems.forEach((item) => {
                item.open = false;
            });
        });

        root.querySelectorAll('[data-scene-text-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const item = button.closest('[data-scene-text-item]');
                const textarea = item?.querySelector('[data-scene-text-value]');

                if (!textarea) {
                    return;
                }

                await copyText(textarea.value);
                showStatus(item.querySelector('[data-scene-text-copy-status]'), 'تم نسخ نص المشهد');
            });
        });

        root.querySelector('[data-scene-text-copy-all]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;

            if (button.disabled) {
                return;
            }

            const combined = Array.from(root.querySelectorAll('[data-scene-text-value]'))
                .map((textarea) => {
                    const title = textarea.dataset.sceneTitle?.trim();
                    const heading = `المشهد ${textarea.dataset.sceneNumber}${title ? ` — ${title}` : ''}`;

                    return `${heading}\n${textarea.value.trim()}`;
                })
                .join('\n\n');

            await copyText(combined);
            showStatus(globalStatus, 'تم نسخ نصوص المشاهد الـ13 بالترتيب');
        });
    });
};
