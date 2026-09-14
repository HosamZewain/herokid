import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { chromium } from 'playwright-core';

test('local Laravel accepts a browser-converted HEIC and serves its private preview', { skip: !process.env.HEROKID_LOCAL_E2E }, async () => {
    // Deliberately cannot target production: this test creates/deletes a synthetic upload.
    const origin = 'http://localhost';
    const browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
    try {
        const page = await browser.newPage();
        await page.goto(origin + '/shop');
        await page.waitForFunction(() => Boolean(window.HeroKidImageUpload?.upload));
        const fixture = await fs.readFile('tests/Frontend/fixtures/synthetic.heic');
        const result = await page.evaluate(async base64 => {
            const session = await (await fetch('/photo-uploads/session', { headers: { Accept: 'application/json' } })).json();
            const original = new File([Uint8Array.from(atob(base64), c => c.charCodeAt(0))], 'صورة-test.heic', { type: 'image/heic' });
            const prepared = await window.HeroKidImageUpload.prepare(original);
            const config = { uploadUrl: '/photo-uploads', sessionToken: session.upload_session_token, batchToken: session.upload_batch_token };
            const body = new FormData(); body.append('photo', original); body.append('prepared_photo', prepared);
            let saved;
            try {
                saved = await window.HeroKidImageUpload.upload(config, body);
                const preview = await fetch(saved.preview_url);
                const restored = await window.HeroKidImageUpload.restore(config, [saved.id]);
                return { previewStatus: preview.status, previewType: preview.headers.get('content-type'), restored: restored.length, preparedType: prepared.type };
            } finally {
                if (saved?.id) {
                    const response = await fetch('/photo-uploads/' + saved.id, { method: 'DELETE', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }, body: JSON.stringify({ upload_session_token: config.sessionToken }) });
                    if (!response.ok) throw new Error('Synthetic upload cleanup failed: ' + response.status);
                }
            }
        }, fixture.toString('base64'));
        assert.deepEqual(result, { previewStatus: 200, previewType: 'image/jpeg', restored: 1, preparedType: 'image/jpeg' });
    } finally { await browser.close(); }
});
