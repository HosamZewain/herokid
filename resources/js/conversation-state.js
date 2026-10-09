export function mergeMessages(previous, incoming) {
    const byId = new Map(previous.map(message => [message.id, message]));
    for (const message of incoming) {
        const previous = byId.get(message.id);
        const attachments = (message.attachments ?? []).map((attachment, index) => Object.hasOwn(attachment, 'url')
            ? attachment : { ...attachment, url:previous?.attachments?.[index]?.url });
        byId.set(message.id, { ...message, attachments });
    }
    return [...byId.values()].sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id);
}

export function safeAttachmentUrl(url) {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'https:' && !parsed.username && !parsed.password ? url : null;
    } catch { return null; }
}

export function shouldPoll(state, visibility) {
    return Boolean(state.configured && !state.closed && !state.minimized && visibility === 'visible');
}
