const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const copyText = async (text) => {
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    document.body.appendChild(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();
    if (!copied) throw new Error('clipboard_unavailable');
};

const download = (url, filename) => {
    const link = document.createElement('a');
    link.href = `${url}${url.includes('?') ? '&' : '?'}download=1`;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
};

const isMobileDevice = () => {
    if (navigator.userAgentData?.mobile === true) return true;

    const userAgent = navigator.userAgent ?? '';

    return /Android|iPhone|iPad|iPod|Mobile/i.test(userAgent)
        || (/Macintosh/i.test(userAgent) && navigator.maxTouchPoints > 1);
};

const facebookPublicUrl = (data) => {
    try {
        return new URL(data.facebook).searchParams.get('u') || data.publicUrl;
    } catch (_) {
        return data.publicUrl;
    }
};

const shareToFacebook = async (data) => {
    if (isMobileDevice() && navigator.share) {
        try {
            await navigator.share({
                title: 'HeroKid',
                text: data.caption,
                url: facebookPublicUrl(data),
            });

            return 'shared';
        } catch (error) {
            if (error?.name === 'AbortError') return 'cancelled';

            window.location.assign(data.facebook);
            return 'fallback';
        }
    }

    const facebookWindow = window.open(data.facebook, '_blank');
    if (facebookWindow) facebookWindow.opener = null;
    else window.location.assign(data.facebook);

    return 'opened';
};

const shareFile = async (url, filename, caption, publicUrl) => {
    const response = await fetch(url, {credentials: 'same-origin'});
    if (!response.ok) throw new Error('share_image_unavailable');
    const blob = await response.blob();
    const file = new File([blob], filename, {type: 'image/jpeg'});
    const payload = {title: 'HeroKid', text: caption, url: publicUrl, files: [file]};

    if (!navigator.share || !navigator.canShare?.({files: [file]})) {
        return false;
    }

    await navigator.share(payload);
    return true;
};

const emitExternalAnalytics = (eventName, channel, variant = null) => {
    const properties = {channel, identity_type: 'child_identity', share_card_variant: variant, campaign_name: 'free_child_identity'};
    if (typeof window.gtag === 'function') window.gtag('event', eventName, properties);
    if (typeof window.fbq === 'function') window.fbq('trackCustom', eventName, properties);
};

export const initializeIdentitySharing = () => {
    document.querySelectorAll('[data-identity-share]').forEach((root) => {
        const toast = root.querySelector('[data-share-toast]');
        const showToast = (message) => {
            if (!toast) return;
            toast.textContent = message;
            toast.classList.remove('hidden');
            window.clearTimeout(toast._timer);
            toast._timer = window.setTimeout(() => toast.classList.add('hidden'), 3200);
        };

        const statusUrl = root.dataset.shareStatusUrl;
        if (statusUrl) {
            const poll = async () => {
                try {
                    const response = await fetch(statusUrl, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
                    const status = await response.json();
                    if (status.ready || status.status === 'failed') window.location.reload();
                    else if (status.refresh) window.setTimeout(poll, 2500);
                } catch (_) {
                    window.setTimeout(poll, 5000);
                }
            };
            window.setTimeout(poll, 2000);
        }

        emitExternalAnalytics('child_identity_share_section_viewed', 'section');
        if (root.dataset.shareCreated === '1') {
            emitExternalAnalytics('child_identity_share_created', 'consent');
        }
        root.querySelectorAll('form[data-share-bootstrap-action="facebook"]').forEach((form) => {
            if (isMobileDevice()) form.removeAttribute('target');
        });
        if (!root.dataset.sharePayload) return;
        const data = JSON.parse(root.dataset.sharePayload);

        const record = (eventType, channel, variant = null) => fetch(data.eventUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: true,
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken()},
            body: JSON.stringify({event_type: eventType, channel, variant}),
        }).catch(() => null);

        root.querySelectorAll('[data-share-action]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (button.disabled) return;
                button.disabled = true;
                const action = button.dataset.shareAction;

                try {
                    if (action === 'copy-link') {
                        await copyText(data.copyUrl);
                        await record('share.link_copied', 'copy_link');
                        emitExternalAnalytics('child_identity_share_clicked', 'copy_link');
                        showToast('تم نسخ الرابط بنجاح');
                    } else if (action === 'copy-caption') {
                        await copyText(data.caption);
                        await record('share.caption_copied', 'copy_caption');
                        emitExternalAnalytics('child_identity_share_clicked', 'copy_caption');
                        showToast('تم نسخ نص المشاركة والهاشتاجات');
                    } else if (action === 'whatsapp') {
                        window.open(data.whatsapp, '_blank', 'noopener,noreferrer');
                        record('share.whatsapp_clicked', 'whatsapp');
                        emitExternalAnalytics('child_identity_share_clicked', 'whatsapp');
                    } else if (action === 'facebook') {
                        record('share.facebook_clicked', 'facebook');
                        emitExternalAnalytics('child_identity_share_clicked', 'facebook');
                        const result = await shareToFacebook(data);
                        if (result === 'opened') {
                            await copyText(data.caption);
                            showToast('تم نسخ النص والهاشتاجات. يمكنك لصقهم داخل منشور فيسبوك.');
                        }
                    } else if (action === 'download-feed' || action === 'download-story') {
                        const variant = action === 'download-story' ? 'story' : 'feed';
                        download(data.cards[variant], `herokid-child-identity-${variant}.jpg`);
                        await record('share.image_saved', `download_${variant}`, variant);
                        emitExternalAnalytics('child_identity_share_clicked', 'download', variant);
                        showToast('تم تجهيز الصورة للمشاركة');
                    } else if (action === 'instagram-feed' || action === 'instagram-story') {
                        const variant = action.endsWith('story') ? 'story' : 'feed';
                        try {
                            const shared = await shareFile(data.cards[variant], `herokid-child-identity-${variant}.jpg`, data.caption, data.publicUrl);
                            if (!shared) {
                                download(data.cards[variant], `herokid-child-identity-${variant}.jpg`);
                                await copyText(data.caption);
                                showToast('تم حفظ الصورة ونسخ النص. افتح إنستجرام واختر الصورة ثم الصق النص.');
                            }
                            await record('share.instagram_clicked', `instagram_${variant}`, variant);
                            emitExternalAnalytics('child_identity_share_clicked', 'instagram', variant);
                        } catch (error) {
                            if (error?.name !== 'AbortError') throw error;
                        }
                    } else if (action === 'native') {
                        try {
                            const shared = await shareFile(data.cards.feed, 'herokid-child-identity-feed.jpg', data.caption, data.publicUrl);
                            if (!shared) {
                                await copyText(data.caption);
                                download(data.cards.feed, 'herokid-child-identity-feed.jpg');
                                showToast('تم نسخ النص وتجهيز الصورة. اختر تطبيق المشاركة يدويًا.');
                            }
                            await record('share.native_opened', 'native', 'feed');
                            emitExternalAnalytics('child_identity_share_clicked', 'native', 'feed');
                        } catch (error) {
                            if (error?.name !== 'AbortError') throw error;
                        }
                    }
                } catch (_) {
                    showToast('تعذر تنفيذ الخطوة تلقائيًا. حاول مرة أخرى.');
                } finally {
                    window.setTimeout(() => { button.disabled = false; }, 700);
                }
            });
        });
    });
};
