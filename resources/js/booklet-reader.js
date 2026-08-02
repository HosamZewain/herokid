import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist/build/pdf.mjs';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { PageFlip } from 'page-flip';

GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

export async function initializeBookletReader(root) {
    if (!root || root.dataset.readerInitialized === 'true') return;
    root.dataset.readerInitialized = 'true';

    const direction = root.dataset.readingDirection === 'ltr' ? 'ltr' : 'rtl';
    const documentUrl = root.dataset.documentUrl;
    const bookElement = root.querySelector('[data-book]');
    const fallbackElement = root.querySelector('[data-reader-fallback]');
    const fallbackImage = root.querySelector('[data-fallback-image]');
    const loading = root.querySelector('[data-reader-loading]');
    const loadingProgress = root.querySelector('[data-loading-progress]');
    const errorPanel = root.querySelector('[data-reader-error]');
    const errorMessage = root.querySelector('[data-reader-error-message]');
    const currentPageLabel = root.querySelector('[data-current-page]');
    const totalPagesLabel = root.querySelector('[data-total-pages]');
    const nextButton = root.querySelector('[data-next-page]');
    const previousButton = root.querySelector('[data-previous-page]');
    const bookStage = root.querySelector('[data-book-stage]');
    const zoomReset = root.querySelector('[data-zoom-reset]');
    const thumbnails = root.querySelector('[data-thumbnails]');
    const thumbnailList = root.querySelector('[data-thumbnail-list]');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const pageImages = new Map();
    const thumbnailImages = new Map();
    const pagePromises = new Map();
    let pdf = null;
    let pageFlip = null;
    let currentPage = 1;
    let pageCount = Number(root.dataset.pageCount || 0);
    let zoom = 1;
    let fallbackMode = reducedMotion;
    let fitBookToViewport = () => {};

    const showError = (message) => {
        loading?.setAttribute('hidden', '');
        if (errorMessage) errorMessage.textContent = message;
        errorPanel?.removeAttribute('hidden');
    };

    const updateControls = () => {
        if (currentPageLabel) currentPageLabel.textContent = String(currentPage);
        if (totalPagesLabel) totalPagesLabel.textContent = String(pageCount);
        if (previousButton) previousButton.disabled = currentPage <= 1;
        if (nextButton) nextButton.disabled = currentPage >= pageCount;
        root.querySelectorAll('[data-thumbnail-page]').forEach((button) => {
            const active = Number(button.dataset.thumbnailPage) === currentPage;
            button.classList.toggle('ring-2', active);
            button.classList.toggle('ring-indigo-400', active);
            button.setAttribute('aria-current', active ? 'page' : 'false');
        });
    };

    const applyPageImage = (pageNumber, url, variant) => {
        const selector = variant === 'thumbnail'
            ? `[data-thumbnail-page="${pageNumber}"] img`
            : `[data-pdf-page="${pageNumber}"] img`;
        root.querySelectorAll(selector).forEach((image) => {
            if (image.src !== url) image.src = url;
        });
    };

    const renderPage = async (pageNumber, variant = 'page') => {
        if (pageNumber < 1 || pageNumber > pageCount) return null;
        const cache = variant === 'thumbnail' ? thumbnailImages : pageImages;
        const promiseKey = `${variant}:${pageNumber}`;
        if (cache.has(pageNumber)) {
            const url = cache.get(pageNumber);
            applyPageImage(pageNumber, url, variant);
            return url;
        }
        if (pagePromises.has(promiseKey)) return pagePromises.get(promiseKey);

        const promise = (async () => {
            const page = await pdf.getPage(pageNumber);
            const initial = page.getViewport({ scale: 1 });
            const mobile = window.matchMedia('(max-width: 767px)').matches;
            const targetWidth = variant === 'thumbnail' ? 180 : (mobile ? 900 : 1300);
            const scale = clamp(targetWidth / initial.width, variant === 'thumbnail' ? 0.2 : 0.75, 2.4);
            const viewport = page.getViewport({ scale });
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            const context = canvas.getContext('2d', { alpha: false });
            await page.render({ canvasContext: context, viewport, background: '#ffffff' }).promise;
            const quality = variant === 'thumbnail' ? 0.78 : 0.92;
            const blob = await new Promise((resolve, reject) => canvas.toBlob((value) => value ? resolve(value) : reject(new Error('تعذر تجهيز صورة الصفحة.')), 'image/jpeg', quality));
            canvas.width = 1; canvas.height = 1;
            const url = URL.createObjectURL(blob);
            cache.set(pageNumber, url);
            pagePromises.delete(promiseKey);
            applyPageImage(pageNumber, url, variant);
            return url;
        })().catch((error) => {
            pagePromises.delete(promiseKey);
            throw error;
        });

        pagePromises.set(promiseKey, promise);
        return promise;
    };

    const renderAround = (pageNumber) => {
        pageImages.forEach((url, cachedPage) => {
            if (Math.abs(cachedPage - pageNumber) <= 4 || pageImages.size <= 9) return;
            URL.revokeObjectURL(url);
            pageImages.delete(cachedPage);
            root.querySelectorAll(`[data-pdf-page="${cachedPage}"] img`).forEach((image) => image.removeAttribute('src'));
        });
        [pageNumber - 2, pageNumber - 1, pageNumber, pageNumber + 1, pageNumber + 2]
            .filter((value) => value >= 1 && value <= pageCount)
            .forEach((value) => renderPage(value).catch(() => {}));
    };

    const logicalPageForIndex = (index) => direction === 'rtl' ? pageCount - index : index + 1;
    const indexForLogicalPage = (pageNumber) => direction === 'rtl' ? pageCount - pageNumber : pageNumber - 1;

    const showFallbackPage = async (pageNumber) => {
        const url = await renderPage(pageNumber);
        if (fallbackImage && url) fallbackImage.src = url;
        currentPage = pageNumber;
        renderAround(pageNumber);
        updateControls();
    };

    const goToPage = (pageNumber, animate = true) => {
        const target = clamp(pageNumber, 1, pageCount);
        if (fallbackMode || !pageFlip) {
            showFallbackPage(target).catch(() => showError('تعذر عرض هذه الصفحة.'));
            return;
        }

        const targetIndex = indexForLogicalPage(target);
        if (animate) pageFlip.flip(targetIndex, 'top');
        else pageFlip.turnToPage(targetIndex);
        currentPage = target;
        renderAround(target);
        updateControls();
    };

    try {
        const loadingTask = getDocument({
            url: documentUrl,
            withCredentials: true,
            rangeChunkSize: 65536,
            disableAutoFetch: false,
            disableStream: false,
        });
        loadingTask.onProgress = ({ loaded, total }) => {
            if (!loadingProgress) return;
            loadingProgress.textContent = total ? `تم تحميل ${Math.round((loaded / total) * 100)}٪` : 'جاري تحميل الصفحات';
        };
        pdf = await loadingTask.promise;
        pageCount = pdf.numPages;
        totalPagesLabel.textContent = String(pageCount);

        const firstPage = await pdf.getPage(1);
        const viewport = firstPage.getViewport({ scale: 1 });
        fitBookToViewport = () => {
            const availableHeight = Math.max(320, (bookStage?.clientHeight || window.innerHeight) - 48);
            const spreadWidth = Math.floor(availableHeight * (viewport.width / viewport.height) * 2);
            bookElement.style.setProperty('--reader-fit-width', `${spreadWidth}px`);
        };
        fitBookToViewport();
        window.addEventListener('resize', fitBookToViewport, { passive: true });
        const pageOrder = Array.from({ length: pageCount }, (_, index) => index + 1);
        if (direction === 'rtl') pageOrder.reverse();

        bookElement.innerHTML = '';
        pageOrder.forEach((pageNumber) => {
            const page = document.createElement('div');
            page.className = 'booklet-page relative bg-white';
            page.dataset.pdfPage = String(pageNumber);
            if (pageNumber === 1 || pageNumber === pageCount) page.dataset.density = 'hard';
            page.innerHTML = `<img class="booklet-page__image" alt="صفحة ${pageNumber}" draggable="false"><span class="booklet-page__placeholder">${pageNumber}</span>`;
            bookElement.appendChild(page);
        });

        await Promise.all([renderPage(1), pageCount > 1 ? renderPage(2) : Promise.resolve()]);

        if (fallbackMode) {
            bookElement.hidden = true;
            fallbackElement?.removeAttribute('hidden');
            await showFallbackPage(1);
        } else {
            try {
                pageFlip = new PageFlip(bookElement, {
                    width: Math.round(viewport.width),
                    height: Math.round(viewport.height),
                    size: 'stretch',
                    minWidth: 260,
                    maxWidth: 920,
                    minHeight: 360,
                    maxHeight: 1180,
                    showCover: true,
                    usePortrait: true,
                    autoSize: true,
                    drawShadow: true,
                    maxShadowOpacity: 0.45,
                    flippingTime: 720,
                    mobileScrollSupport: false,
                    swipeDistance: 24,
                    startPage: direction === 'rtl' ? pageCount - 1 : 0,
                });
                pageFlip.on('flip', (event) => {
                    currentPage = logicalPageForIndex(Number(event.data));
                    renderAround(currentPage);
                    updateControls();
                });
                pageFlip.loadFromHTML(bookElement.querySelectorAll('.booklet-page'));
                renderAround(1);
            } catch (_) {
                fallbackMode = true;
                bookElement.hidden = true;
                fallbackElement?.removeAttribute('hidden');
                await showFallbackPage(1);
            }
        }

        thumbnailList.innerHTML = '';
        const observer = new IntersectionObserver((entries) => {
            entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                const pageNumber = Number(entry.target.dataset.thumbnailPage);
                renderPage(pageNumber, 'thumbnail').catch(() => {});
                observer.unobserve(entry.target);
            });
        }, { root: thumbnails, rootMargin: '120px' });
        for (let pageNumber = 1; pageNumber <= pageCount; pageNumber += 1) {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.thumbnailPage = String(pageNumber);
            button.className = 'overflow-hidden rounded-lg bg-white/10 p-1 text-center text-[10px] font-black text-white';
            button.setAttribute('aria-label', `الانتقال إلى الصفحة ${pageNumber}`);
            button.innerHTML = `<img alt="" class="aspect-[3/4] w-full rounded bg-white object-contain"><span class="mt-1 block">${pageNumber}</span>`;
            button.addEventListener('click', () => { goToPage(pageNumber, false); thumbnails.setAttribute('hidden', ''); });
            thumbnailList.appendChild(button);
            observer.observe(button);
        }

        currentPage = 1;
        updateControls();
        loading?.setAttribute('hidden', '');
    } catch (error) {
        showError(error?.message?.includes('403') ? 'انتهت جلسة المعاينة. حدّث الصفحة لإعادة فتحها.' : 'تعذر تحميل ملف المعاينة. حاول تحديث الصفحة أو تواصل مع HeroKid.');
        return;
    }

    nextButton?.addEventListener('click', () => goToPage(currentPage + 1));
    previousButton?.addEventListener('click', () => goToPage(currentPage - 1));
    root.querySelector('[data-thumbnails-toggle]')?.addEventListener('click', () => thumbnails.toggleAttribute('hidden'));
    root.querySelector('[data-thumbnails-close]')?.addEventListener('click', () => thumbnails.setAttribute('hidden', ''));

    const applyZoom = (nextZoom) => {
        zoom = clamp(nextZoom, 0.75, 2);
        bookStage?.style.setProperty('--reader-zoom', String(zoom));
        if (zoomReset) zoomReset.textContent = `${Math.round(zoom * 100)}%`;
    };
    root.querySelector('[data-zoom-in]')?.addEventListener('click', () => applyZoom(zoom + 0.25));
    root.querySelector('[data-zoom-out]')?.addEventListener('click', () => applyZoom(zoom - 0.25));
    zoomReset?.addEventListener('click', () => applyZoom(1));
    root.querySelector('[data-fullscreen]')?.addEventListener('click', async () => {
        if (document.fullscreenElement) await document.exitFullscreen();
        else if (bookStage?.requestFullscreen) await bookStage.requestFullscreen();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') thumbnails?.setAttribute('hidden', '');
        if (event.key === (direction === 'rtl' ? 'ArrowRight' : 'ArrowLeft')) { event.preventDefault(); goToPage(currentPage + 1); }
        if (event.key === (direction === 'rtl' ? 'ArrowLeft' : 'ArrowRight')) { event.preventDefault(); goToPage(currentPage - 1); }
        if (event.key === 'Home') goToPage(1, false);
        if (event.key === 'End') goToPage(pageCount, false);
    });

    window.addEventListener('beforeunload', () => {
        window.removeEventListener('resize', fitBookToViewport);
        pageImages.forEach((url) => URL.revokeObjectURL(url));
        thumbnailImages.forEach((url) => URL.revokeObjectURL(url));
    }, { once: true });
}
