import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-front-menu-toggle]');
    const menu = document.querySelector('[data-front-mobile-menu]');
    const openIcon = document.querySelector('[data-front-menu-open-icon]');
    const closeIcon = document.querySelector('[data-front-menu-close-icon]');

    const errorSummary = document.querySelector('[data-scroll-on-load]');

    if (errorSummary) {
        window.requestAnimationFrame(() => {
            errorSummary.scrollIntoView({ behavior: 'smooth', block: 'center' });
            errorSummary.focus({ preventScroll: true });
        });
    }

    document.querySelectorAll('[data-photo-input]').forEach((input) => {
        const label = input.closest('div')?.querySelector('[data-photo-label]');

        if (!label) {
            return;
        }

        input.addEventListener('change', () => {
            const count = input.files?.length ?? 0;

            if (count === 0) {
                label.textContent = 'لم يتم اختيار صور';
            } else if (count === 1) {
                label.textContent = 'تم اختيار صورة واحدة';
            } else {
                label.textContent = `تم اختيار ${count} صور`;
            }
        });
    });

    if (toggle && menu) {
        const setOpen = (isOpen) => {
            menu.classList.toggle('hidden', !isOpen);
            openIcon?.classList.toggle('hidden', isOpen);
            closeIcon?.classList.toggle('hidden', !isOpen);
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        };

        toggle.addEventListener('click', () => {
            setOpen(menu.classList.contains('hidden'));
        });
    }
});

try {
    Alpine.start();
} catch (error) {
    console.warn('Alpine failed to start.', error);
}
