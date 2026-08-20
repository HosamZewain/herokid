import './bootstrap';
import { initializeIdentityHeicRecovery, initializeIdentityPhotoUploader } from './identity-photo-uploader';
import { prepareImageForUpload } from './image-upload-preparer';
import { initializeIdentitySharing } from './identity-sharing';
import { initializeStorySceneEditor } from './story-scene-editor';
import { initializeOrderSceneTexts } from './order-scene-texts';
import { initializeOrderPaymentSummaries } from './order-payment-summary';
import { initializeFootballStories, trackHeroKidEvent } from './football-stories';

window.HeroKidImageUpload = Object.freeze({
    prepare: prepareImageForUpload,
});
window.HeroKidAnalytics = Object.freeze({
    track: trackHeroKidEvent,
});

document.addEventListener('DOMContentLoaded', () => {
    const bookletReader = document.querySelector('[data-booklet-reader]');
    if (bookletReader) {
        import('./booklet-reader')
            .then(({ initializeBookletReader }) => initializeBookletReader(bookletReader))
            .catch(() => {
                bookletReader.querySelector('[data-reader-loading]')?.setAttribute('hidden', '');
                bookletReader.querySelector('[data-reader-error]')?.removeAttribute('hidden');
            });
    }

    const sceneReader = document.querySelector('[data-scene-reader]');
    if (sceneReader) {
        import('./scene-reader')
            .then(({ initializeSceneReader }) => initializeSceneReader(sceneReader))
            .catch(() => {
                sceneReader.querySelector('[data-reader-loading]')?.setAttribute('hidden', '');
                sceneReader.querySelector('[data-reader-error]')?.removeAttribute('hidden');
            });
    }

    initializeIdentityPhotoUploader();
    initializeIdentityHeicRecovery();
    initializeIdentitySharing();
    initializeStorySceneEditor();
    initializeOrderSceneTexts();
    initializeOrderPaymentSummaries();
    initializeFootballStories();

    document.querySelectorAll('[data-floating-whatsapp]').forEach((link) => {
        link.addEventListener('click', () => trackHeroKidEvent('WhatsAppClick', {
            page_location: window.location.pathname,
        }));
    });
    const adminSidebar = document.querySelector('[data-admin-sidebar]');
    const adminSidebarToggle = document.querySelector('[data-admin-sidebar-toggle]');
    const adminSidebarClose = document.querySelector('[data-admin-sidebar-close]');
    const adminSidebarOverlay = document.querySelector('[data-admin-sidebar-overlay]');
    const adminMobileQuery = window.matchMedia('(max-width: 1023px)');

    if (adminSidebar && adminSidebarToggle && adminSidebarOverlay) {
        const setAdminSidebarOpen = (isOpen) => {
            const mobileOpen = isOpen && adminMobileQuery.matches;

            adminSidebar.classList.toggle('translate-x-full', !mobileOpen && adminMobileQuery.matches);
            adminSidebar.classList.toggle('translate-x-0', mobileOpen);
            adminSidebarOverlay.classList.toggle('hidden', !mobileOpen);
            adminSidebarToggle.setAttribute('aria-expanded', mobileOpen ? 'true' : 'false');
            adminSidebar.toggleAttribute('inert', adminMobileQuery.matches && !mobileOpen);
            document.body.classList.toggle('overflow-hidden', mobileOpen);
        };

        adminSidebarToggle.addEventListener('click', () => {
            setAdminSidebarOpen(adminSidebarToggle.getAttribute('aria-expanded') !== 'true');
        });
        adminSidebarClose?.addEventListener('click', () => setAdminSidebarOpen(false));
        adminSidebarOverlay.addEventListener('click', () => setAdminSidebarOpen(false));
        adminSidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => setAdminSidebarOpen(false));
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && adminSidebarToggle.getAttribute('aria-expanded') === 'true') {
                setAdminSidebarOpen(false);
                adminSidebarToggle.focus();
            }
        });
        adminMobileQuery.addEventListener('change', () => setAdminSidebarOpen(false));
        setAdminSidebarOpen(false);
    }

    const toggle = document.querySelector('[data-front-menu-toggle]');
    const menu = document.querySelector('[data-front-mobile-menu]');
    const openIcon = document.querySelector('[data-front-menu-open-icon]');
    const closeIcon = document.querySelector('[data-front-menu-close-icon]');

    const errorSummary = document.querySelector('[data-scroll-on-load]');

    if (errorSummary) {
        window.requestAnimationFrame(() => {
            const fieldName = errorSummary.dataset.firstErrorField?.replace(/\.\d+.*$/, '');
            const field = fieldName
                ? document.querySelector(`[name="${CSS.escape(fieldName)}"], [name="${CSS.escape(fieldName)}[]"]`)
                : null;
            const stage = field?.closest('details');

            if (stage) {
                stage.open = true;
            }

            const target = field || errorSummary;
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.focus({ preventScroll: true });
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

    const guideMenu = document.querySelector('[data-front-guide-menu]');

    if (guideMenu) {
        document.addEventListener('click', (event) => {
            if (!guideMenu.contains(event.target)) {
                guideMenu.removeAttribute('open');
            }
        });

        guideMenu.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && guideMenu.open) {
                guideMenu.removeAttribute('open');
                guideMenu.querySelector('summary')?.focus();
            }
        });
    }

    const cartAddedToast = document.querySelector('[data-cart-added-toast]');

    if (cartAddedToast) {
        const dismissCartToast = () => {
            cartAddedToast.classList.add('opacity-0', 'translate-y-3', 'pointer-events-none');
            window.setTimeout(() => cartAddedToast.remove(), 200);
        };

        cartAddedToast.classList.add('transition', 'duration-200');
        cartAddedToast.querySelectorAll('[data-cart-toast-dismiss]').forEach((button) => {
            button.addEventListener('click', dismissCartToast);
        });
        cartAddedToast.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                dismissCartToast();
            }
        });
    }
});
