import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs/promises';
import { chromium } from 'playwright-core';

let browser, server, origin;
const policy = "default-src 'self'; img-src 'self' https: data: blob:; worker-src 'self' blob:; script-src 'self' 'unsafe-inline' https:; style-src 'self' 'unsafe-inline' https:; font-src 'self' https: data:; connect-src 'self' https:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
before(async () => {
    const files = {
        '/preparer.js': 'resources/js/image-upload-preparer.js',
        '/transport.js': 'resources/js/photo-upload-transport.js',
        '/admin-uploads.js': 'resources/js/admin-file-uploads.js',
        '/heic.js': 'node_modules/heic-to/dist/csp/heic-to.js',
        '/fixture.heic': 'tests/Frontend/fixtures/synthetic.heic',
    };
    server = http.createServer(async (req, res) => {
        res.setHeader('Content-Security-Policy', policy);
        if (files[req.url]) {
            res.setHeader('Content-Type', req.url.endsWith('.heic') ? 'image/heic' : 'text/javascript');
            res.end(await fs.readFile(files[req.url]));
        } else {
            res.setHeader('Content-Type', 'text/html');
            res.end('<meta name="csrf-token" content="old"><form><input name="_token" value="old"><input name="upload_session_token" value="old-session"></form><script type="importmap">{"imports":{"heic-to/csp":"/heic.js"}}</script>');
        }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
});
after(async () => { await browser?.close(); await new Promise(resolve => server?.close(resolve)); });

async function pageFor(t) {
    const context = await browser.newContext();
    t.after(() => context.close());
    const page = await context.newPage();
    await page.goto(origin);
    return page;
}

test('real HEIC converts and resizes under production CSP without unsafe-eval', async t => {
    const page = await pageFor(t);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    const result = await page.evaluate(async () => {
        const { prepareImageForUpload } = await import('/preparer.js');
        const blob = await (await fetch('/fixture.heic')).blob();
        // Exercise the decoder directly too, so failures identify the CSP/codec cause.
        await (await import('/heic.js')).heicTo({ blob, type: 'image/jpeg', quality: 0.9 });
        const result = await prepareImageForUpload(new File([blob], 'صورة.heic', { type: 'image/heic' }), { maxLongEdge: 48, conversionTimeoutMs: 15000 });
        const image = await createImageBitmap(result);
        return { type: result.type, name: result.name, width: image.width, height: image.height, bytes: result.size };
    });
    assert.equal(result.type, 'image/jpeg'); assert.equal(result.name, 'صورة.jpg');
    assert.equal(result.width, 48); assert.equal(result.height, 32); assert.ok(result.bytes > 0); assert.deepEqual(errors, []);
});

test('419 refreshes session and retries exactly once, updating final form tokens', async t => {
    const page = await pageFor(t); let uploads = 0;
    await page.route('**/photo-uploads/session', route => route.fulfill({ json: { csrf_token: 'fresh', upload_session_token: 'fresh-session' } }));
    await page.route('**/photo-uploads', route => {
        uploads++;
        if (uploads === 1) return route.fulfill({ status: 419, json: {} });
        assert.equal(route.request().headers()['x-csrf-token'], 'fresh');
        assert.ok(route.request().postData().includes('fresh-session'));
        return route.fulfill({ status: 201, json: { id: 'created' } });
    });
    const result = await page.evaluate(async () => {
        const { uploadPhoto } = await import('/transport.js');
        const body = new FormData(); body.append('photo', new File(['image'], 'test.jpg'));
        const data = await uploadPhoto({ uploadUrl: '/photo-uploads', sessionToken: 'old-session' }, body);
        return { id: data.id, csrf: document.querySelector('[name="_token"]').value };
    });
    assert.deepEqual(result, { id: 'created', csrf: 'fresh' }); assert.equal(uploads, 2);
});

test('blocked browser storage cannot interrupt image intake', async t => {
    const page = await pageFor(t);
    assert.equal(await page.evaluate(async () => {
        const { safeStorage } = await import('/transport.js');
        Object.defineProperty(window, 'sessionStorage', { get() { throw new DOMException('Denied', 'SecurityError'); } });
        const storage = safeStorage('sessionStorage');
        storage.setItem('key', 'value'); storage.removeItem('key');
        return storage.getItem('key');
    }), null);
});

test('ordinary identity images preserve original without duplicate derivative upload', async t => {
    const page = await pageFor(t);
    assert.deepEqual(await page.evaluate(async () => {
        const { appendPhoto } = await import('/transport.js');
        const body = new FormData(); appendPhoto(body, new File(['original'], 'child.jpg', { type: 'image/jpeg' }), new File(['smaller'], 'small.jpg'));
        return { keys: [...body.keys()], original: await body.get('photo').text() };
    }), { keys: ['photo'], original: 'original' });
});

for (const status of [413, 429, 500]) test(`HTTP ${status} fails clearly without blind duplicate retries`, async t => {
    const page = await pageFor(t); let requests = 0;
    await page.route('**/photo-uploads', route => { requests++; return route.fulfill({ status, json: {} }); });
    const message = await page.evaluate(async () => {
        const { uploadPhoto } = await import('/transport.js');
        try { await uploadPhoto({ uploadUrl: '/photo-uploads' }, new FormData()); } catch (e) { return e.message; }
    });
    assert.ok(message.length > 10); assert.equal(requests, 1);
});

test('cancelled upload settles once and sends no retry', async t => {
    const page = await pageFor(t);
    assert.equal(await page.evaluate(async () => {
        const { uploadPhoto } = await import('/transport.js');
        const controller = new AbortController(); controller.abort();
        try { await uploadPhoto({ uploadUrl: '/photo-uploads' }, new FormData(), { signal: controller.signal }); } catch (e) { return e.name; }
    }), 'AbortError');
});

test('restoration drops stale or foreign ids using server response', async t => {
    const page = await pageFor(t);
    await page.route('**/photo-uploads/session?*', route => route.fulfill({ json: { csrf_token: 'fresh', upload_session_token: 'fresh-session', valid_upload_ids: ['live'] } }));
    const restored = await page.evaluate(async () => (await import('/transport.js')).restorePhotos({}, ['stale', 'live', 'foreign']));
    assert.deepEqual(restored, ['live']);
});

test('session replacement requeues local files and discards stale restored references', async t => {
    const page = await pageFor(t);
    const states = await page.evaluate(async () => {
        const { observePhotoSession } = await import('/transport.js');
        const items = [{ status: 'uploaded', uploadId: 'old', file: new File(['data'], 'test.jpg') }, { status: 'uploaded', uploadId: 'stale', file: null }];
        const config = { sessionToken: 'old' }; let pumps = 0;
        observePhotoSession(config, items, () => {}, () => pumps++);
        window.dispatchEvent(new CustomEvent('herokid:photo-session', { detail: { token: 'new' } }));
        return { token: config.sessionToken, states: items.map(i => i.status), ids: items.map(i => i.uploadId), pumps };
    });
    assert.deepEqual(states, { token: 'new', states: ['waiting'], ids: [null], pumps: 1 });
});

test('partial admin batches retain only unsuccessful files for retry', async t => {
    const page = await pageFor(t);
    await page.goto(origin + '/admin/orders/1');
    await page.setContent('<form action="/admin/upload" method="POST"><input type="file" name="attachments[]" multiple><button type="submit">Upload</button></form>');
    await page.evaluate(async () => (await import('/admin-uploads.js')).initializeAdminFileUploads());
    let requests = 0;
    await page.route('**/admin/upload', route => {
        requests++;
        return route.fulfill(requests === 2 ? { status: 422, json: { message: 'Invalid second file' } } : { json: { success: true } });
    });
    await page.locator('input[type="file"]').setInputFiles([
        { name: 'first.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('first') },
        { name: 'second.jpg', mimeType: 'image/jpeg', buffer: Buffer.from('second') },
    ]);
    await page.locator('button').click();
    await page.waitForFunction(() => document.querySelector('[data-upload-feedback]').textContent.includes('Invalid second file'));
    assert.deepEqual(await page.locator('input').evaluate(input => Array.from(input.files).map(file => file.name)), ['second.jpg']);
    await page.locator('button').click();
    await page.waitForFunction(() => document.querySelector('form').dataset.uploading !== '1');
    assert.equal(requests, 3);
    assert.equal(await page.locator('input').evaluate(input => input.files.length), 0);
});
