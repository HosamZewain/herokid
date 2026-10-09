import test from 'node:test';
import assert from 'node:assert/strict';
import { isAudioAttachment, audioBubbleSignature, updateAudioSource, createAudioAttachment, updateAudioAttachment, reconcileConversationNodes, pauseConversationAudio } from '../../resources/js/conversation-audio.js';

test('recognizes typed voice messages and legacy OGG filenames without classifying images/video as audio', () => {
    for (const kind of ['audio', 'voice', 'ptt', 'voice_note']) assert.equal(isAudioAttachment({kind}, {name:'opaque-file'}), true);
    for (const name of ['voice.ogg', 'VOICE.OGG', 'note.opus', 'music.mp3', 'clip.m4a', 'sample.wav']) assert.equal(isAudioAttachment({kind:'file'}, {name}), true);
    for (const kind of ['image', 'video', 'reaction']) assert.equal(isAudioAttachment({kind}, {name:'voice.ogg'}), false);
    for (const name of ['photo.jpg', 'file.pdf', 'text.txt', 'movie.webm', 'voice.ogg.exe', 'voice.ogg?x=1']) assert.equal(isAudioAttachment({kind:'file'}, {name}), false);
});

test('audio bubble identity ignores rotating/absent signed URLs and delivery changes but not content or contact changes', () => {
    const message = {id:1, kind:'audio', date:'2026-10-09T14:00:00Z', direction:'inbound', text:'', status:'sent', attachments:[{name:'voice.ogg', url:'https://example.test/a?signature=one'}]};
    const signature = audioBubbleSignature(message);
    for (const url of ['https://example.test/a?signature=two', null, undefined]) {
        assert.equal(audioBubbleSignature({...message, status:'read', attachments:[{name:'voice.ogg', url}]}), signature);
    }
    for (const change of [{id:2}, {text:'edited'}, {kind:'image'}, {direction:'outbound'}, {attachments:[{name:'other.ogg'}]}]) {
        assert.notEqual(audioBubbleSignature({...message, ...change}), signature);
    }
});

function audioFixture(changes = {}) {
    const attributes = new Map();
    const listeners = [];
    return {paused:true, ended:false, error:null, currentTime:0, duration:30, playbackRate:1, pauses:0, loads:0,
        getAttribute:key => attributes.get(key) ?? null, removeAttribute:key => attributes.delete(key),
        set src(value) { attributes.set('src', value); },
        pause() { this.paused = true; this.pauses++; }, load() { this.loads++; },
        addEventListener:(event, fn, options) => listeners.push({event, fn, options}), listeners, ...changes};
}

test('audio controls are lazy, do not autoplay, and recover from unavailable URLs or decode errors', () => {
    const original = globalThis.document;
    globalThis.document = {createElement(tag) {
        const attributes = new Map();
        const node = tag === 'audio' ? audioFixture() : {getAttribute:key => attributes.get(key) ?? null, removeAttribute:key => attributes.delete(key)};
        return Object.assign(node, {tag, dataset:{}, children:[], hidden:false,
            setAttribute(key, value) { attributes.set(key, value); },
            append(...children) { this.children.push(...children); },
            querySelector(tag) { return this.children.find(child => child.tag === tag); },
        });
    }};
    try {
        const wrapper = createAudioAttachment({name:'voice.ogg',url:null}, 2);
        const [audio, link, notice] = wrapper.children;
        assert.equal(audio.controls, true); assert.equal(audio.preload, 'none'); assert.notEqual(audio.autoplay, true);
        assert.equal(wrapper.dataset.audioAttachment, '2');
        assert.equal(audio.hidden, true); assert.equal(link.hidden, true); assert.equal(notice.hidden, false);
        updateAudioAttachment(wrapper, {name:'voice.ogg',url:'https://example.test/voice'});
        assert.equal(audio.hidden, false); assert.equal(link.hidden, false); assert.equal(notice.hidden, true);
        assert.equal(link.rel, 'noopener noreferrer'); assert.equal(link.referrerPolicy, 'no-referrer');
        audio.listeners.find(listener => listener.event === 'error').fn(); assert.equal(notice.hidden, false);
        audio.listeners.find(listener => listener.event === 'loadedmetadata').fn(); assert.equal(notice.hidden, true);
        updateAudioAttachment(wrapper, {name:'voice.ogg',url:'javascript:alert(1)'});
        assert.equal(audio.hidden, true); assert.equal(link.hidden, true); assert.equal(notice.hidden, false);
        assert.equal(link.getAttribute('href'), null);
    } finally { globalThis.document = original; }
});

test('a playing audio stream is not restarted when a signed URL rotates', () => {
    const audio = audioFixture();
    updateAudioSource(audio, 'https://example.test/one');
    audio.paused = false; audio.currentTime = 12;
    updateAudioSource(audio, 'https://example.test/two');
    assert.equal(audio.getAttribute('src'), 'https://example.test/one');
    assert.equal(audio.currentTime, 12); assert.equal(audio.pauses, 0); assert.equal(audio.loads, 0);
});

test('paused playback resumes at its previous position and rate after a URL refresh, without automatic play', () => {
    const audio = audioFixture({currentTime:12, playbackRate:1.5});
    updateAudioSource(audio, 'https://example.test/new');
    audio.currentTime = 0; audio.playbackRate = 1;
    assert.equal(audio.listeners[0].options.once, true);
    audio.listeners[0].fn();
    assert.equal(audio.currentTime, 12); assert.equal(audio.playbackRate, 1.5); assert.equal(audio.paused, true);
    updateAudioSource(audio, 'https://example.test/new');
    assert.equal(audio.listeners.length, 1);
});

test('an unavailable or unsafe live URL releases playback instead of retaining access', () => {
    for (const value of [null, undefined, 'javascript:alert(1)', 'http://example.test/voice', 'https://user:pass@example.test/voice']) {
        const audio = audioFixture(); audio.src = 'https://example.test/old'; audio.paused = false;
        updateAudioSource(audio, value);
        assert.equal(audio.getAttribute('src'), null); assert.equal(audio.paused, true);
        assert.equal(audio.pauses, 1); assert.equal(audio.loads, 1);
    }
});

test('source changes while paused cannot apply a stale metadata callback or seek beyond a shorter file', () => {
    const audio = audioFixture({currentTime:12});
    updateAudioSource(audio, 'https://example.test/one');
    updateAudioSource(audio, 'https://example.test/two');
    audio.currentTime = 0; audio.duration = 5;
    for (const {fn} of audio.listeners) fn();
    assert.equal(audio.currentTime, 0);
});

test('reconciliation does not remove or move existing audio bubbles on polling or earlier-history pagination', () => {
    const list = {childNodes:[], moves:[], insertBefore(node, before) {
        this.moves.push(node.name); const old = this.childNodes.indexOf(node);
        if (old >= 0) this.childNodes.splice(old, 1);
        this.childNodes.splice(before ? this.childNodes.indexOf(before) : this.childNodes.length, 0, node);
    }, get firstChild() { return this.childNodes[0] ?? null; }};
    const node = name => ({name, remove() { list.childNodes.splice(list.childNodes.indexOf(this), 1); },
        get nextSibling() { return list.childNodes[list.childNodes.indexOf(this) + 1] ?? null; }});
    const oldDay = node('old-day'), audio = node('audio'), oldText = node('old-text');
    list.childNodes = [oldDay, audio, oldText];
    const day = node('day'), text = node('text');
    reconcileConversationNodes(list, [day, audio, text]);
    assert.deepEqual(list.childNodes, [day, audio, text]); assert.ok(!list.moves.includes('audio'));
    list.moves = []; const earlier = node('earlier');
    reconcileConversationNodes(list, [earlier, day, audio, text]);
    assert.ok(!list.moves.includes('audio')); assert.deepEqual(list.childNodes, [earlier, day, audio, text]);
});

test('minimizing pauses audio; closing also releases the signed source', () => {
    const audio = audioFixture(); audio.src = 'https://example.test/voice';
    const panel = {querySelectorAll:() => [audio]};
    pauseConversationAudio(panel);
    assert.equal(audio.pauses, 1); assert.equal(audio.loads, 0); assert.equal(audio.getAttribute('src'), 'https://example.test/voice');
    pauseConversationAudio(panel, true);
    assert.equal(audio.pauses, 2); assert.equal(audio.loads, 1); assert.equal(audio.getAttribute('src'), null);
});
