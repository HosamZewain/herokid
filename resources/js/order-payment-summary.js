const COLORS = Object.freeze({
    ink: '#0f172a',
    muted: '#64748b',
    border: '#e2e8f0',
    indigo: '#4f46e5',
    indigoDark: '#312e81',
    indigoSoft: '#eef2ff',
    rose: '#e11d48',
    emerald: '#059669',
    white: '#ffffff',
    surface: '#f8fafc',
});

const roundedRect = (context, x, y, width, height, radius, fill, stroke = null) => {
    context.beginPath();
    context.moveTo(x + radius, y);
    context.lineTo(x + width - radius, y);
    context.quadraticCurveTo(x + width, y, x + width, y + radius);
    context.lineTo(x + width, y + height - radius);
    context.quadraticCurveTo(x + width, y + height, x + width - radius, y + height);
    context.lineTo(x + radius, y + height);
    context.quadraticCurveTo(x, y + height, x, y + height - radius);
    context.lineTo(x, y + radius);
    context.quadraticCurveTo(x, y, x + radius, y);
    context.closePath();
    context.fillStyle = fill;
    context.fill();

    if (stroke) {
        context.strokeStyle = stroke;
        context.lineWidth = 2;
        context.stroke();
    }
};

const setFont = (context, size, weight = 700) => {
    context.font = `${weight} ${size}px Cairo, Arial, sans-serif`;
};

const drawRtlText = (context, value, x, y, size, weight = 700, color = COLORS.ink) => {
    context.save();
    context.direction = 'rtl';
    context.textAlign = 'right';
    context.textBaseline = 'alphabetic';
    context.fillStyle = color;
    setFont(context, size, weight);
    context.fillText(String(value ?? ''), x, y);
    context.restore();
};

const drawLtrText = (context, value, x, y, size, weight = 700, color = COLORS.ink) => {
    context.save();
    context.direction = 'ltr';
    context.textAlign = 'left';
    context.textBaseline = 'alphabetic';
    context.fillStyle = color;
    setFont(context, size, weight);
    context.fillText(String(value ?? ''), x, y);
    context.restore();
};

const drawLeftRtlText = (context, value, x, y, size, weight = 700, color = COLORS.ink) => {
    context.save();
    context.direction = 'rtl';
    context.textAlign = 'left';
    context.textBaseline = 'alphabetic';
    context.fillStyle = color;
    setFont(context, size, weight);
    context.fillText(String(value ?? ''), x, y);
    context.restore();
};

const wrapRtlText = (context, value, x, y, maxWidth, lineHeight, maxLines = 2) => {
    const words = String(value ?? '').split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';

    words.forEach((word) => {
        const candidate = line ? `${line} ${word}` : word;

        if (context.measureText(candidate).width > maxWidth && line) {
            lines.push(line);
            line = word;
        } else {
            line = candidate;
        }
    });

    if (line) {
        lines.push(line);
    }

    const visible = lines.slice(0, maxLines);
    if (lines.length > maxLines) {
        let last = visible[maxLines - 1] || '';
        while (last && context.measureText(`${last}…`).width > maxWidth) {
            last = last.slice(0, -1);
        }
        visible[maxLines - 1] = `${last}…`;
    }

    visible.forEach((text, index) => {
        context.fillText(text, x, y + (index * lineHeight));
    });

    return visible.length;
};

const loadLogo = () => new Promise((resolve) => {
    const logo = new Image();
    logo.onload = () => resolve(logo);
    logo.onerror = () => resolve(null);
    logo.src = '/images/logo-192.png';
});

const createSummaryImage = async (data) => {
    await document.fonts?.ready;

    const width = 1600;
    const itemRows = Math.max(1, data.items?.length ?? 0);
    const height = Math.max(980, 650 + (itemRows * 92));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d', { alpha: false });

    context.fillStyle = COLORS.surface;
    context.fillRect(0, 0, width, height);

    const headerGradient = context.createLinearGradient(0, 0, width, 0);
    headerGradient.addColorStop(0, '#312e81');
    headerGradient.addColorStop(1, '#4f46e5');
    context.fillStyle = headerGradient;
    context.fillRect(0, 0, width, 170);

    const logo = await loadLogo();
    if (logo) {
        roundedRect(context, 1400, 28, 118, 118, 24, COLORS.white);
        context.drawImage(logo, 1409, 37, 100, 100);
    }

    drawRtlText(context, 'ملخص طلب HeroKid', 1365, 78, 42, 800, COLORS.white);
    drawRtlText(context, 'بيانات الطلب والمبلغ المطلوب للدفع', 1365, 124, 24, 700, '#c7d2fe');
    drawLtrText(context, data.reference, 80, 76, 25, 800, COLORS.white);
    drawLtrText(context, data.date, 80, 119, 20, 600, '#c7d2fe');

    const margin = 70;
    const gap = 34;
    const totalsWidth = 410;
    const contentWidth = width - (margin * 2) - gap - totalsWidth;
    const totalsX = margin;
    const contentX = margin + totalsWidth + gap;
    const top = 205;
    const contentHeight = height - top - 55;

    roundedRect(context, contentX, top, contentWidth, contentHeight, 30, COLORS.white, COLORS.border);
    roundedRect(context, totalsX, top, totalsWidth, 610, 30, COLORS.indigoSoft, '#c7d2fe');

    const right = contentX + contentWidth - 42;
    drawRtlText(context, 'العميل والتوصيل', right, top + 60, 30, 800);
    drawRtlText(context, 'اسم ولي الأمر', right, top + 110, 17, 700, COLORS.muted);
    drawRtlText(context, data.customer, right, top + 143, 23, 800);
    drawRtlText(context, 'الهاتف / واتساب', right - 470, top + 110, 17, 700, COLORS.muted);
    drawLtrText(context, data.phone, contentX + 42, top + 143, 22, 800);

    context.save();
    context.direction = 'rtl';
    context.textAlign = 'right';
    context.fillStyle = COLORS.ink;
    setFont(context, 20, 700);
    drawRtlText(context, 'العنوان', right, top + 195, 17, 700, COLORS.muted);
    wrapRtlText(context, `${data.location} — ${data.address}`, right, top + 230, contentWidth - 84, 31, 2);
    context.restore();

    context.strokeStyle = COLORS.border;
    context.lineWidth = 2;
    context.beginPath();
    context.moveTo(contentX + 42, top + 300);
    context.lineTo(contentX + contentWidth - 42, top + 300);
    context.stroke();

    drawRtlText(context, 'محتويات الطلب', right, top + 350, 27, 800);

    let rowY = top + 378;
    (data.items || []).forEach((item, index) => {
        roundedRect(context, contentX + 36, rowY, contentWidth - 72, 78, 16, index % 2 === 0 ? '#f8fafc' : COLORS.white, COLORS.border);
        drawRtlText(context, item.title, right - 20, rowY + 34, 21, 800);
        drawRtlText(context, [item.type, item.details].filter(Boolean).join(' — '), right - 20, rowY + 61, 15, 700, COLORS.muted);
        drawLtrText(context, `${item.quantity} ×`, contentX + 58, rowY + 31, 16, 700, COLORS.muted);
        drawLeftRtlText(context, item.total, contentX + 58, rowY + 60, 20, 800, COLORS.indigoDark);
        rowY += 92;
    });

    const totalsRight = totalsX + totalsWidth - 34;
    drawRtlText(context, 'ملخص القيمة', totalsRight, top + 58, 29, 800, COLORS.indigoDark);
    const totalRows = [
        ['العناصر', data.items_total, COLORS.indigoDark],
        ['التوصيل', data.delivery, COLORS.indigoDark],
    ];

    if (data.discount_cents > 0) {
        totalRows.push(['الخصم', `- ${data.discount}`, COLORS.rose]);
    }

    let totalY = top + 125;
    totalRows.forEach(([label, value, color]) => {
        drawRtlText(context, label, totalsRight, totalY, 20, 700, color);
        drawLeftRtlText(context, value, totalsX + 34, totalY, 20, 800, color);
        totalY += 52;
    });

    context.strokeStyle = '#c7d2fe';
    context.beginPath();
    context.moveTo(totalsX + 30, totalY - 18);
    context.lineTo(totalsX + totalsWidth - 30, totalY - 18);
    context.stroke();
    drawRtlText(context, 'الإجمالي', totalsRight, totalY + 25, 25, 800, COLORS.indigoDark);
    drawLeftRtlText(context, data.total, totalsX + 34, totalY + 25, 25, 800, COLORS.indigoDark);

    roundedRect(context, totalsX + 28, totalY + 72, totalsWidth - 56, 180, 22, COLORS.white);
    drawRtlText(context, 'المبلغ المطلوب للدفع', totalsRight - 22, totalY + 115, 18, 800, COLORS.muted);
    drawRtlText(context, data.due_cents > 0 ? data.due : 'مدفوع بالكامل', totalsRight - 22, totalY + 170, 38, 800, data.due_cents > 0 ? COLORS.rose : COLORS.emerald);
    drawRtlText(context, data.payment_status, totalsRight - 22, totalY + 218, 17, 800, COLORS.indigo);

    drawRtlText(context, `تم دفع: ${data.paid}`, totalsRight, top + 565, 18, 700, COLORS.emerald);
    drawRtlText(context, 'HeroKid • hero-kid.com', width - 70, height - 22, 16, 700, COLORS.muted);

    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('تعذر إنشاء صورة الملخص.')), 'image/png');
    });
};

export const initializeOrderPaymentSummaries = () => {
    document.querySelectorAll('[data-order-payment-summary]').forEach((root) => {
        const button = root.querySelector('[data-order-payment-summary-download]');
        const status = root.querySelector('[data-order-payment-summary-status]');
        const dataNode = root.querySelector('[data-order-payment-summary-data]');

        if (!button || !dataNode) {
            return;
        }

        button.addEventListener('click', async () => {
            button.disabled = true;
            const originalLabel = button.textContent;
            button.textContent = 'جاري تجهيز الصورة…';
            status.textContent = '';

            try {
                const data = JSON.parse(dataNode.textContent);
                const blob = await createSummaryImage(data);
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = data.file_name || 'HeroKid-order-summary.png';
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
                status.textContent = 'تم تنزيل صورة الملخص بنجاح.';
            } catch (error) {
                status.textContent = 'تعذر تجهيز الصورة. حاول مرة أخرى.';
            } finally {
                button.disabled = false;
                button.textContent = originalLabel;
            }
        });
    });
};
