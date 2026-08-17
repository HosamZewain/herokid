import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist/build/pdf.mjs';
import PdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?worker';

GlobalWorkerOptions.workerPort = new PdfWorker();

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const sceneGroups = (pageCount) => {
    if (pageCount < 1) return [];

    const groups = [{ label: 'الغلاف', kind: 'cover', pages: [1] }];
    let sceneNumber = 1;

    for (let page = 2; page < pageCount; page += 2) {
        groups.push({
            label: `المشهد ${sceneNumber}`,
            kind: 'scene',
            sceneNumber,
            pages: [page, page + 1].filter((value) => value < pageCount),
        });
        sceneNumber += 1;
    }

    if (pageCount > 1) groups.push({ label: 'الغلاف الخلفي', kind: 'back-cover', pages: [pageCount] });

    return groups;
};

export async function initializeSceneReader(root) {
    if (!root || root.dataset.readerInitialized === 'true') return;
    root.dataset.readerInitialized = 'true';

    const documentUrl = root.dataset.documentUrl;
    const direction = root.dataset.readingDirection === 'ltr' ? 'ltr' : 'rtl';
    const sceneList = root.querySelector('[data-scene-list]');
    const loading = root.querySelector('[data-reader-loading]');
    const loadingProgress = root.querySelector('[data-loading-progress]');
    const errorPanel = root.querySelector('[data-reader-error]');
    const errorMessage = root.querySelector('[data-reader-error-message]');
    const currentLabel = root.querySelector('[data-current-scene]');
    const progressBar = root.querySelector('[data-scene-progress]');
    const pageUrls = new Map();
    const pagePromises = new Map();
    let pdf = null;
    let groups = [];

    const showError = (message) => {
        loading?.setAttribute('hidden', '');
        if (errorMessage) errorMessage.textContent = message;
        errorPanel?.removeAttribute('hidden');
    };

    const applyPageImage = (pageNumber, url) => {
        root.querySelectorAll(`[data-scene-page="${pageNumber}"] img`).forEach((image) => {
            if (image.src !== url) image.src = url;
        });
    };

    const renderPage = async (pageNumber) => {
        if (pageUrls.has(pageNumber)) {
            const url = pageUrls.get(pageNumber);
            applyPageImage(pageNumber, url);
            return url;
        }
        if (pagePromises.has(pageNumber)) return pagePromises.get(pageNumber);

        const promise = (async () => {
            const page = await pdf.getPage(pageNumber);
            const initial = page.getViewport({ scale: 1 });
            const targetWidth = window.matchMedia('(max-width: 767px)').matches ? 900 : 1200;
            const scale = clamp(targetWidth / initial.width, 0.75, 2.4);
            const viewport = page.getViewport({ scale });
            const canvas = document.createElement('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            const context = canvas.getContext('2d', { alpha: false });
            await page.render({ canvasContext: context, viewport, background: '#ffffff' }).promise;
            const blob = await new Promise((resolve, reject) => canvas.toBlob(
                (value) => value ? resolve(value) : reject(new Error('تعذر تجهيز صفحة المشهد.')),
                'image/jpeg',
                0.92,
            ));
            canvas.width = 1;
            canvas.height = 1;
            const url = URL.createObjectURL(blob);
            pageUrls.set(pageNumber, url);
            pagePromises.delete(pageNumber);
            applyPageImage(pageNumber, url);
            return url;
        })().catch((error) => {
            pagePromises.delete(pageNumber);
            throw error;
        });

        pagePromises.set(pageNumber, promise);
        return promise;
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
        groups = sceneGroups(pdf.numPages);

        sceneList.innerHTML = '';
        groups.forEach((group, groupIndex) => {
            const section = document.createElement('section');
            section.className = 'scene-reader__section';
            section.dataset.sceneGroup = String(groupIndex);
            section.dataset.sceneLabel = group.label;
            section.setAttribute('aria-label', group.label);

            const frame = document.createElement('div');
            frame.className = `scene-reader__frame scene-reader__frame--${group.kind}`;
            frame.dir = direction;

            group.pages.forEach((pageNumber) => {
                const page = document.createElement('div');
                page.className = 'scene-reader__page';
                page.dataset.scenePage = String(pageNumber);
                page.innerHTML = `<img alt="${group.label} — صفحة ${pageNumber}" draggable="false"><span>${pageNumber}</span>`;
                frame.appendChild(page);
            });

            const caption = document.createElement('p');
            caption.className = 'scene-reader__caption';
            caption.textContent = group.label;
            section.append(frame, caption);
            sceneList.appendChild(section);
        });

        const renderObserver = new IntersectionObserver((entries) => {
            entries.filter((entry) => entry.isIntersecting).forEach((entry) => {
                entry.target.querySelectorAll('[data-scene-page]').forEach((page) => {
                    renderPage(Number(page.dataset.scenePage)).catch(() => showError('تعذر تجهيز إحدى صفحات المشهد.'));
                });
            });
        }, { root: sceneList, rootMargin: '100% 0px' });

        const positionObserver = new IntersectionObserver((entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((first, second) => second.intersectionRatio - first.intersectionRatio)[0];
            if (!visible) return;
            const index = Number(visible.target.dataset.sceneGroup);
            const label = visible.target.dataset.sceneLabel;
            if (currentLabel) currentLabel.textContent = label;
            if (progressBar) progressBar.style.width = `${((index + 1) / groups.length) * 100}%`;
        }, { root: sceneList, threshold: [0.35, 0.55, 0.75] });

        sceneList.querySelectorAll('[data-scene-group]').forEach((section) => {
            renderObserver.observe(section);
            positionObserver.observe(section);
        });

        await Promise.all(groups[0]?.pages.map((page) => renderPage(page)) || []);
        loading?.setAttribute('hidden', '');
    } catch (error) {
        showError(error?.message?.includes('403')
            ? 'انتهت جلسة المعاينة. حدّث الصفحة لإعادة فتحها.'
            : 'تعذر تحميل ملف المعاينة. حاول تحديث الصفحة أو تواصل مع HeroKid.');
        return;
    }

    window.addEventListener('beforeunload', () => {
        pageUrls.forEach((url) => URL.revokeObjectURL(url));
    }, { once: true });
}
