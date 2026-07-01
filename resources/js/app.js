import './bootstrap';

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

    document.querySelectorAll('[data-faq-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const item = button.closest('[data-faq-item]');
            const answer = item?.querySelector('[data-faq-answer]');
            const icon = item?.querySelector('[data-faq-icon]');
            const indicator = item?.querySelector('[data-faq-indicator]');

            if (!answer) {
                return;
            }

            const isOpen = button.getAttribute('aria-expanded') === 'true';
            const nextOpen = !isOpen;

            button.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
            answer.hidden = !nextOpen;
            icon?.classList.toggle('rotate-180', nextOpen);

            if (indicator) {
                indicator.classList.toggle('bg-amber-500', nextOpen);
                indicator.classList.toggle('text-white', nextOpen);
                indicator.classList.toggle('bg-amber-100', !nextOpen);
                indicator.classList.toggle('text-amber-600', !nextOpen);
            }
        });
    });

    document.querySelectorAll('[data-status-log-toggle]').forEach((button) => {
        const targetId = button.getAttribute('aria-controls');
        const log = targetId ? document.getElementById(targetId) : null;
        const label = button.querySelector('[data-status-log-label]');

        if (!log) {
            return;
        }

        button.addEventListener('click', () => {
            const nextOpen = button.getAttribute('aria-expanded') !== 'true';

            button.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
            log.hidden = !nextOpen;

            if (label) {
                label.textContent = nextOpen ? '▲ إخفاء سجل التحديثات' : '▼ عرض سجل التحديثات';
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
