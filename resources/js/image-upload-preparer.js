function isHeic(file) {
    const type = String(file?.type || '').toLowerCase();
    const name = String(file?.name || '').toLowerCase();

    return type.includes('heic')
        || type.includes('heif')
        || name.endsWith('.heic')
        || name.endsWith('.heif');
}

export async function prepareImageForUpload(file, options = {}) {
    const maxLongEdge = Number(options.maxLongEdge || 2560);
    const jpegQuality = Math.min(1, Math.max(0.5, Number(options.jpegQuality || 0.9)));

    if (isHeic(file)) {
        try {
            const { default: heic2any } = await import('heic2any');
            const converted = await heic2any({
                blob: file,
                toType: 'image/jpeg',
                quality: jpegQuality,
            });
            const jpeg = Array.isArray(converted) ? converted[0] : converted;

            if (!(jpeg instanceof Blob) || jpeg.size === 0) {
                throw new Error('empty_conversion');
            }

            return new File(
                [jpeg],
                String(file.name || 'child-photo.heic').replace(/\.(heic|heif)$/i, '.jpg'),
                { type: 'image/jpeg', lastModified: file.lastModified || Date.now() },
            );
        } catch {
            throw new Error('تعذر تجهيز صورة HEIC/HEIF داخل المتصفح. جرّب اختيار الصورة الأصلية مرة أخرى.');
        }
    }

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(String(file.type || '').toLowerCase())) {
        return file;
    }

    if (!window.createImageBitmap || !document.createElement('canvas').getContext) {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const longEdge = Math.max(bitmap.width, bitmap.height);

        if (longEdge <= maxLongEdge) {
            bitmap.close?.();

            return file;
        }

        const scale = maxLongEdge / longEdge;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close?.();
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', jpegQuality));

        if (!blob || blob.size > file.size) {
            return file;
        }

        return new File(
            [blob],
            String(file.name || 'child-photo.jpg').replace(/\.[^.]+$/, '.jpg'),
            { type: 'image/jpeg', lastModified: file.lastModified || Date.now() },
        );
    } catch {
        return file;
    }
}
