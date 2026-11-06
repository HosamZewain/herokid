export function shouldPoll({ open, hidden, pending, working }) {
    return open && !hidden && pending && !working;
}
export function trustedFrameMessage(event, frame, origin) {
    return event.origin === origin && event.source === frame.contentWindow && event.data?.channel === 'herokid-sales'
        && ['personalization', 'cart', 'review', 'complete', 'added'].includes(event.data.stage);
}
export function publicUrl(value, origin, image = false) {
    try {
        const url = new URL(value, origin);
        return image ? (url.protocol === 'https:' || (url.protocol === 'http:' && url.origin === origin) ? url.href : null)
            : (url.origin === origin && ['http:', 'https:'].includes(url.protocol) ? url.href : null);
    } catch { return null; }
}
export function terminalMessage(status) {
    return ({ unavailable: 'المساعد الذكي مش متاح دلوقتي. تقدر تختار من الكتالوج وتكمل الطلب، أو تكلم الفريق.',
        uncertain: 'الرد ماوصلش بشكل مؤكد. مش هنعيد الطلب المدفوع تلقائيًا؛ تقدر تكمل بالنماذج أو تكلم الفريق.',
        invalid_response: 'ماقدرتش أجهز ترشيح موثوق للرسالة دي. تقدر تتصفح الاختيارات أو تكلم الفريق.',
        expired: 'الرد ما اتنفذش في الوقت المتاح. تقدر تبعت سؤال جديد أو تكمل من الكتالوج.' })[status] || 'بجهز لك الرد…';
}

export function newClientNonce(source = globalThis.crypto) {
    if (typeof source?.randomUUID === 'function') return source.randomUUID();
    if (typeof source?.getRandomValues !== 'function') throw new Error('متصفحك محتاج تحديث لاستكمال المحادثة بأمان. السلة متاحة كالمعتاد.');
    const bytes = source.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(b => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
}
