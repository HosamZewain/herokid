const A6_WIDTH = 1240;
const A6_HEIGHT = 1748;

const COLORS = Object.freeze({
    ink: '#111827',
    muted: '#64748b',
    border: '#e2e8f0',
    indigo: '#4f46e5',
    indigoDark: '#312e81',
    indigoSoft: '#eef2ff',
    fuchsia: '#c026d3',
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

const fittedText = (context, value, maxWidth) => {
    const text = String(value ?? '');
    if (context.measureText(text).width <= maxWidth) return text;

    let shortened = text;
    while (shortened.length > 1 && context.measureText(`${shortened}…`).width > maxWidth) {
        shortened = shortened.slice(0, -1);
    }

    return `${shortened}…`;
};

const drawRtlText = (context, value, x, y, size, weight = 700, color = COLORS.ink, maxWidth = null) => {
    context.save();
    context.direction = 'rtl';
    context.textAlign = 'right';
    context.textBaseline = 'alphabetic';
    context.fillStyle = color;
    setFont(context, size, weight);
    context.fillText(maxWidth ? fittedText(context, value, maxWidth) : String(value ?? ''), x, y);
    context.restore();
};

const drawLtrText = (context, value, x, y, size, weight = 700, color = COLORS.ink, align = 'left') => {
    context.save();
    context.direction = 'ltr';
    context.textAlign = align;
    context.textBaseline = 'alphabetic';
    context.fillStyle = color;
    setFont(context, size, weight);
    context.fillText(String(value ?? ''), x, y);
    context.restore();
};

const drawCenteredText = (context, value, x, y, size, weight = 700, color = COLORS.ink) => {
    context.save();
    context.direction = 'rtl';
    context.textAlign = 'center';
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
    if (line) lines.push(line);

    const visible = lines.slice(0, maxLines);
    if (lines.length > maxLines && visible.length) {
        visible[visible.length - 1] = fittedText(context, `${visible[visible.length - 1]}…`, maxWidth);
    }
    visible.forEach((text, index) => context.fillText(text, x, y + (index * lineHeight)));
};

const loadLogo = () => new Promise((resolve) => {
    const logo = new Image();
    logo.onload = () => resolve(logo);
    logo.onerror = () => resolve(null);
    logo.src = '/images/logo-320.png';
});

const createInvoiceImage = async (data) => {
    await document.fonts?.ready;

    const canvas = document.createElement('canvas');
    canvas.width = A6_WIDTH;
    canvas.height = A6_HEIGHT;
    const context = canvas.getContext('2d', { alpha: false });

    context.fillStyle = COLORS.surface;
    context.fillRect(0, 0, A6_WIDTH, A6_HEIGHT);

    const gradient = context.createLinearGradient(0, 0, A6_WIDTH, 0);
    gradient.addColorStop(0, COLORS.indigoDark);
    gradient.addColorStop(0.7, COLORS.indigo);
    gradient.addColorStop(1, COLORS.fuchsia);
    context.fillStyle = gradient;
    context.fillRect(0, 0, A6_WIDTH, 240);

    const logo = await loadLogo();
    if (logo) {
        roundedRect(context, 1010, 38, 155, 155, 28, COLORS.white);
        context.drawImage(logo, 1022, 50, 131, 131);
    }

    drawRtlText(context, 'فاتورة طلب', 970, 98, 52, 800, COLORS.white);
    drawRtlText(context, data.brand || 'HeroKid', 970, 157, 31, 800, '#e0e7ff');
    drawLtrText(context, data.reference, 70, 96, 31, 800, COLORS.white);
    drawLtrText(context, data.date, 70, 147, 23, 600, '#e0e7ff');
    drawLtrText(context, 'A6 • 105 × 148 mm • 300 DPI', 70, 190, 18, 600, '#c7d2fe');

    roundedRect(context, 55, 275, 1130, 112, 22, COLORS.white, COLORS.border);
    drawRtlText(context, 'مرجع عملية الشراء', 1140, 317, 17, 700, COLORS.muted);
    drawLtrText(context, data.checkout_reference, 1140, 358, 22, 800, COLORS.ink, 'right');
    drawRtlText(context, 'أرقام الطلبات', 720, 317, 17, 700, COLORS.muted);
    drawLtrText(context, data.order_numbers, 720, 358, 19, 700, COLORS.ink, 'right');
    drawRtlText(context, 'حالة الدفع', 305, 317, 17, 700, COLORS.muted);
    drawRtlText(context, data.payment_status, 305, 358, 22, 800, COLORS.indigoDark);

    roundedRect(context, 55, 415, 1130, 255, 24, COLORS.white, COLORS.border);
    drawRtlText(context, 'بيانات العميل والتوصيل', 1140, 462, 27, 800, COLORS.indigoDark);
    drawRtlText(context, 'اسم ولي الأمر', 1140, 508, 16, 700, COLORS.muted);
    drawRtlText(context, data.customer, 1140, 541, 22, 800, COLORS.ink, 500);
    drawRtlText(context, 'الهاتف', 545, 508, 16, 700, COLORS.muted);
    drawLtrText(context, data.phone, 545, 541, 21, 800, COLORS.ink, 'right');
    drawRtlText(context, 'العنوان', 1140, 585, 16, 700, COLORS.muted);
    context.save();
    context.direction = 'rtl';
    context.textAlign = 'right';
    context.fillStyle = COLORS.ink;
    setFont(context, 19, 700);
    wrapRtlText(context, `${data.location} — ${data.address}`, 1140, 620, 1050, 27, 2);
    context.restore();

    drawRtlText(context, 'تفاصيل الطلب', 1140, 725, 29, 800, COLORS.indigoDark);
    const tableX = 55;
    const tableWidth = 1130;
    const tableTop = 750;
    const tableHeaderHeight = 52;
    const items = data.items || [];
    const availableRowsHeight = 500;
    const rowHeight = items.length ? Math.min(68, availableRowsHeight / items.length) : 68;
    const rowFont = Math.max(10, rowHeight < 38 ? 14 : rowHeight < 52 ? 17 : 20);
    const detailFont = Math.max(9, rowFont - 5);

    roundedRect(context, tableX, tableTop, tableWidth, tableHeaderHeight, 16, COLORS.indigoSoft, '#c7d2fe');
    drawRtlText(context, 'المنتج', 1150, tableTop + 34, 17, 800, COLORS.indigoDark);
    drawCenteredText(context, 'العدد', 500, tableTop + 34, 17, 800, COLORS.indigoDark);
    drawCenteredText(context, 'سعر الوحدة', 345, tableTop + 34, 17, 800, COLORS.indigoDark);
    drawCenteredText(context, 'الإجمالي', 145, tableTop + 34, 17, 800, COLORS.indigoDark);

    if (!items.length) {
        drawCenteredText(context, 'لا توجد عناصر مسجلة', A6_WIDTH / 2, tableTop + 110, 22, 700, COLORS.muted);
    }

    let rowY = tableTop + tableHeaderHeight;
    items.forEach((item, index) => {
        context.fillStyle = index % 2 === 0 ? COLORS.white : '#f8fafc';
        context.fillRect(tableX, rowY, tableWidth, rowHeight);
        context.strokeStyle = COLORS.border;
        context.beginPath();
        context.moveTo(tableX, rowY + rowHeight);
        context.lineTo(tableX + tableWidth, rowY + rowHeight);
        context.stroke();

        drawRtlText(context, item.title, 1150, rowY + Math.max(21, rowHeight * 0.48), rowFont, 800, COLORS.ink, 560);
        if (rowHeight >= 44) drawRtlText(context, item.type, 1150, rowY + rowHeight - 9, detailFont, 700, COLORS.muted, 560);
        drawCenteredText(context, item.quantity, 500, rowY + (rowHeight / 2) + (rowFont / 3), rowFont, 800);
        drawCenteredText(context, item.unit_price, 345, rowY + (rowHeight / 2) + (rowFont / 3), rowFont, 700);
        drawCenteredText(context, item.line_total, 145, rowY + (rowHeight / 2) + (rowFont / 3), rowFont, 800, COLORS.indigoDark);
        rowY += rowHeight;
    });

    const totalsY = Math.max(1325, rowY + 28);
    roundedRect(context, 55, totalsY, 1130, 245, 24, COLORS.indigoSoft, '#c7d2fe');
    drawRtlText(context, 'ملخص الدفع', 1140, totalsY + 45, 25, 800, COLORS.indigoDark);

    const summaryRows = [
        ['العناصر', data.items_total, COLORS.indigoDark],
        ['التوصيل', data.delivery_total, COLORS.indigoDark],
    ];
    if (data.discount_cents > 0) summaryRows.push(['الخصم', `- ${data.discount_total}`, COLORS.rose]);

    summaryRows.forEach(([label, value, color], index) => {
        const x = index % 2 === 0 ? 1140 : 590;
        const y = totalsY + 91 + (Math.floor(index / 2) * 44);
        drawRtlText(context, label, x, y, 18, 700, color);
        drawRtlText(context, value, x - 190, y, 20, 800, color);
    });

    drawRtlText(context, 'الإجمالي', 1140, totalsY + 190, 24, 800, COLORS.indigoDark);
    drawRtlText(context, data.grand_total, 940, totalsY + 190, 28, 800, COLORS.indigoDark);
    drawRtlText(context, 'المدفوع', 655, totalsY + 190, 20, 700, COLORS.emerald);
    drawRtlText(context, data.paid_total, 505, totalsY + 190, 22, 800, COLORS.emerald);
    drawRtlText(context, 'المتبقي', 280, totalsY + 190, 20, 700, data.due_cents > 0 ? COLORS.rose : COLORS.emerald);
    drawRtlText(context, data.due_total, 135, totalsY + 190, 22, 800, data.due_cents > 0 ? COLORS.rose : COLORS.emerald);

    const contactParts = [data.brand_host, data.brand_email, data.brand_phone, data.brand_address].filter(Boolean);
    drawRtlText(context, `طريقة الدفع: ${data.payment_method}`, 1165, 1642, 18, 700, COLORS.muted);
    drawRtlText(context, contactParts.join(' • '), 1165, 1683, 17, 700, COLORS.muted, 1090);
    drawRtlText(context, 'فاتورة طلب صادرة من HeroKid — ليست فاتورة ضريبية', 1165, 1720, 16, 700, COLORS.muted);

    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('تعذر إنشاء صورة الفاتورة.')), 'image/png');
    });
};

export const initializeOrderInvoices = () => {
    document.querySelectorAll('[data-order-invoice]').forEach((root) => {
        const openButton = root.querySelector('[data-order-invoice-open]');
        const modal = root.querySelector('[data-order-invoice-modal]');
        const preview = root.querySelector('[data-order-invoice-preview]');
        const status = root.querySelector('[data-order-invoice-status]');
        const download = root.querySelector('[data-order-invoice-download]');
        const newTab = root.querySelector('[data-order-invoice-new-tab]');
        const dataNode = root.querySelector('[data-order-invoice-data]');
        let imageUrl = null;

        if (!openButton || !modal || !preview || !status || !download || !newTab || !dataNode) return;

        const close = () => {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
            openButton.focus();
        };

        root.querySelectorAll('[data-order-invoice-close]').forEach((button) => button.addEventListener('click', close));

        openButton.addEventListener('click', async () => {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');

            if (imageUrl) return;

            openButton.disabled = true;
            status.classList.remove('hidden');
            status.textContent = 'جاري تجهيز الفاتورة…';

            try {
                const data = JSON.parse(dataNode.textContent);
                const blob = await createInvoiceImage(data);
                imageUrl = URL.createObjectURL(blob);
                preview.src = imageUrl;
                preview.classList.remove('hidden');
                status.classList.add('hidden');
                download.href = imageUrl;
                download.download = data.file_name || 'HeroKid-invoice.png';
                download.classList.remove('hidden');
                newTab.href = imageUrl;
                newTab.classList.remove('hidden');
            } catch (error) {
                status.textContent = 'تعذر تجهيز الفاتورة. حاول مرة أخرى.';
                status.classList.remove('hidden');
            } finally {
                openButton.disabled = false;
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) close();
        });

        window.addEventListener('beforeunload', () => {
            if (imageUrl) URL.revokeObjectURL(imageUrl);
        }, { once: true });
    });
};
