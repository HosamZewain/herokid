// XHR exposes upload progress; fetch does not. Uploading is separate from publishing.
export function studioUploadError(status) {
    if ([401, 419].includes(status)) return 'انتهت جلسة الدخول. افتح تسجيل الدخول في تبويب آخر ثم أعد المحاولة هنا حتى لا تفقد تعديلاتك.';
    if (status === 403) return 'حسابك لا يملك صلاحية رفع صور Studio. راجع مسؤول النظام.';
    if (status === 413) return 'الخادم رفض حجم الملف. اختر صورة أصغر أو اطلب مراجعة حد الرفع في الاستضافة.';
    if (status === 422) return 'الصورة غير مقبولة. استخدم PNG أو JPEG أو WebP ضمن حد الحجم الموضح، حتى 6000 بكسل لكل ضلع و25 مليون بكسل إجمالًا.';
    if (status === 429) return 'تم رفع ملفات كثيرة خلال وقت قصير. انتظر دقيقة ثم أعد المحاولة.';
    return 'تعذر حفظ الصورة على الخادم. أعد المحاولة؛ الصورة المنشورة لم تتغير.';
}

export function uploadStudioAsset({ url, csrf, file, onProgress = () => {}, xhrFactory = () => new XMLHttpRequest() }) {
    const xhr = xhrFactory();
    const promise = new Promise((resolve, reject) => {
        xhr.open('POST', url);
        xhr.timeout = 180000;
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
        xhr.upload.onprogress = event => onProgress(event.lengthComputable ? Math.min(100, Math.round(event.loaded / event.total * 100)) : null);
        xhr.upload.onload = () => onProgress(100);
        xhr.onerror = () => reject(new Error('انقطع الاتصال أثناء الرفع. تحقق من الإنترنت واضغط إعادة المحاولة.'));
        xhr.ontimeout = () => reject(new Error('انتهت مهلة الرفع بعد 3 دقائق. أعد المحاولة أو اختر صورة أصغر. لم تُنشر الصورة.'));
        xhr.onabort = () => reject(new Error('تم إلغاء الرفع. لم تتغير الصورة المختارة.'));
        xhr.onload = () => {
            if (xhr.status < 200 || xhr.status >= 300) { reject(new Error(studioUploadError(xhr.status))); return; }
            try {
                const { data } = JSON.parse(xhr.responseText);
                if (!data?.id || !data?.preview_url) throw new Error('invalid');
                resolve(data);
            } catch { reject(new Error('لم يصل تأكيد حفظ الصورة. قد تكون جلسة الدخول انتهت؛ سجّل الدخول ثم أعد المحاولة.')); }
        };
        const body = new FormData(); body.append('file', file); xhr.send(body);
    });
    return { promise, cancel: () => xhr.abort() };
}
