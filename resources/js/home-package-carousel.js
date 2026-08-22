export function initializeHomePackageCarousels() {
    document.querySelectorAll('[data-home-package-carousel]').forEach((carousel) => {
        const track = carousel.querySelector('[data-home-package-track]');
        const slides = [...carousel.querySelectorAll('[data-home-package-slide]')];
        const dots = [...carousel.querySelectorAll('[data-home-package-dot]')];
        const previous = carousel.querySelector('[data-home-package-previous]');
        const next = carousel.querySelector('[data-home-package-next]');
        const status = carousel.querySelector('[data-home-package-status]');

        if (!track || slides.length === 0) {
            return;
        }

        let activeIndex = Math.min(
            Math.max(Number.parseInt(carousel.dataset.initialIndex || '0', 10), 0),
            slides.length - 1,
        );
        let scrollFrame = null;

        const centerSlide = (slide, behavior = 'smooth') => {
            const trackBounds = track.getBoundingClientRect();
            const slideBounds = slide.getBoundingClientRect();
            const horizontalOffset = (slideBounds.left + (slideBounds.width / 2))
                - (trackBounds.left + (trackBounds.width / 2));

            track.scrollBy({ left: horizontalOffset, behavior });
        };

        const activate = (index, shouldScroll = false, announce = false) => {
            activeIndex = (index + slides.length) % slides.length;

            slides.forEach((slide, slideIndex) => {
                const isActive = slideIndex === activeIndex;
                slide.classList.toggle('is-active', isActive);
                slide.setAttribute('aria-current', isActive ? 'true' : 'false');
            });
            dots.forEach((dot, dotIndex) => {
                const isActive = dotIndex === activeIndex;
                dot.classList.toggle('is-active', isActive);
                dot.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });

            if (shouldScroll) {
                centerSlide(
                    slides[activeIndex],
                    window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                );
            }

            if (announce && status) {
                status.textContent = slides[activeIndex].getAttribute('aria-label') || '';
            }
        };

        const activateNearestSlide = () => {
            const trackCenter = track.getBoundingClientRect().left + (track.clientWidth / 2);
            let nearestIndex = activeIndex;
            let nearestDistance = Number.POSITIVE_INFINITY;

            slides.forEach((slide, index) => {
                const bounds = slide.getBoundingClientRect();
                const distance = Math.abs((bounds.left + (bounds.width / 2)) - trackCenter);
                if (distance < nearestDistance) {
                    nearestDistance = distance;
                    nearestIndex = index;
                }
            });

            activate(nearestIndex);
        };

        track.addEventListener('scroll', () => {
            if (scrollFrame !== null) {
                window.cancelAnimationFrame(scrollFrame);
            }
            scrollFrame = window.requestAnimationFrame(() => {
                activateNearestSlide();
                scrollFrame = null;
            });
        }, { passive: true });

        previous?.addEventListener('click', () => activate(activeIndex - 1, true, true));
        next?.addEventListener('click', () => activate(activeIndex + 1, true, true));
        dots.forEach((dot) => dot.addEventListener('click', () => activate(Number(dot.dataset.index), true, true)));
        track.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                activate(activeIndex + 1, true, true);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                activate(activeIndex - 1, true, true);
            }
        });

        activate(activeIndex);
        window.requestAnimationFrame(() => centerSlide(slides[activeIndex], 'auto'));
    });
}
