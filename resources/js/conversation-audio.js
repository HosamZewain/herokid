import { safeAttachmentUrl } from './conversation-state.js';

export function isAudioAttachment(message, attachment) {
    const kind = String(message.kind || '').toLowerCase();
    if (['audio', 'voice', 'ptt', 'voice_note'].includes(kind)) return true;
    if (['image', 'video', 'reaction'].includes(kind)) return false;
    return /\.(ogg|oga|opus|mp3|m4a|aac|wav|flac)$/i.test(attachment.name || '');
}

// Delivery updates and rotating signed audio URLs must not recreate a player.
export function audioBubbleSignature(message) {
    const { status, attachments, ...content } = message;
    return JSON.stringify({ ...content, attachments:(attachments || []).map(attachment =>
        isAudioAttachment(message, attachment) ? { name:attachment.name } : attachment) });
}

export function updateAudioSource(audio, value) {
    const url = safeAttachmentUrl(value);
    if (audio.getAttribute('src') === url) return;
    if (!url) {
        audio.pause(); audio.removeAttribute('src'); audio.load();
        return;
    }
    // Finish the current stream without resetting its position on every poll.
    if (!audio.paused && !audio.ended && !audio.error) return;
    const position = audio.currentTime || 0;
    const rate = audio.playbackRate;
    audio.src = url;
    if (position > 0) audio.addEventListener('loadedmetadata', () => {
        if (audio.getAttribute('src') !== url) return;
        if (Number.isFinite(audio.duration) && position < audio.duration) audio.currentTime = position;
        audio.playbackRate = rate;
    }, { once:true });
}

export function updateAudioAttachment(wrapper, attachment) {
    const audio = wrapper.querySelector('audio');
    const link = wrapper.querySelector('a');
    const notice = wrapper.querySelector('p');
    const url = safeAttachmentUrl(attachment.url);
    updateAudioSource(audio, url);
    audio.hidden = link.hidden = !url;
    if (url) { link.href = url; link.title = attachment.name || 'ملف صوتي'; }
    else link.removeAttribute('href');
    notice.hidden = Boolean(url) && !audio.error;
    notice.textContent = url ? 'تعذر تشغيل الصوت. حدّث المحادثة أو افتح الملف.' : 'حدّث المحادثة لتحميل الملف الصوتي.';
}

export function createAudioAttachment(attachment, index) {
    const wrapper = document.createElement('div');
    wrapper.className = 'hk-chat-audio'; wrapper.dataset.audioAttachment = String(index);
    const audio = document.createElement('audio');
    audio.controls = true; audio.preload = 'none'; audio.setAttribute('aria-label', 'رسالة صوتية');
    const link = document.createElement('a');
    link.textContent = 'فتح الملف الصوتي'; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.referrerPolicy = 'no-referrer';
    const notice = document.createElement('p'); notice.className = 'hk-chat-audio-notice';
    audio.addEventListener('error', () => { notice.hidden = false; });
    audio.addEventListener('loadedmetadata', () => { notice.hidden = Boolean(safeAttachmentUrl(audio.getAttribute('src'))) && !audio.error; });
    wrapper.append(audio, link, notice);
    updateAudioAttachment(wrapper, attachment);
    return wrapper;
}

// Keep existing audio bubbles in the DOM while adding/updating other messages.
export function reconcileConversationNodes(list, nodes) {
    const wanted = new Set(nodes);
    for (const child of [...list.childNodes]) if (!wanted.has(child)) child.remove();
    let cursor = list.firstChild;
    for (const node of nodes) {
        if (node === cursor) cursor = cursor.nextSibling;
        else list.insertBefore(node, cursor);
    }
}

export function pauseConversationAudio(panel, release = false) {
    for (const audio of panel.querySelectorAll('audio')) {
        audio.pause();
        if (release) { audio.removeAttribute('src'); audio.load(); }
    }
}
