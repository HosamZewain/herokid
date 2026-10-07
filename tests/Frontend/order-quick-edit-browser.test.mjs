import { before, after, test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const root = fileURLToPath(new URL('../../', import.meta.url));
let server, browser, origin, html;
const options = {
    can_upload_photos: true,
    products: [{ id: 2, name: 'ستيكر شخصي', price: 200, linked: false, variants: [], schema: { fields: {
        child_name: { enabled: true, required: true, label: 'اسم الطفل', type: 'text' },
        school_name: { enabled: true, label: 'اسم المدرسة', type: 'text' },
        photos: { enabled: true, required: true, label: 'صور الطفل', type: 'photos' },
    } } }, { id: 3, name: 'كتاب تلوين', price: 300, linked: false, variants: [], schema: { fields: {} } }],
    children: [{ order_id: 1, name: 'ليلى أحمد', story: true, values: { child_name: 'ليلى أحمد', school_name: 'الأمل' }, photos: ['/photo-test'] }],
    items: [{ id: 9, order_id: 1, title: 'ستيكر', linked: false, values: { child_name: 'ليلى أحمد', school_name: 'الأمل' }, schema: { fields: {
        child_name: { enabled: true, required: true, label: 'اسم الطفل', type: 'text' },
        school_name: { enabled: true, label: 'اسم المدرسة', type: 'text' },
        photos: { enabled: true, label: 'صور الطفل', type: 'photos' },
    } } }],
    stories: [{ order_id: 1, values: { child_name: 'ليلى أحمد', child_age: 7, child_gender: 'girl', language: 'ar', lesson: '', interests: '', gift_note: '', parent_notes: '' } }],
};

before(async () => {
    const assets = new Map();
    for (const name of await fs.readdir(root + 'public/build/assets')) assets.set('/build/assets/' + name, await fs.readFile(root + 'public/build/assets/' + name));
    server = http.createServer((req, res) => {
        res.setHeader('Content-Type', req.url.endsWith('.js') ? 'text/javascript' : req.url.endsWith('.css') ? 'text/css' : 'text/html');
        res.end(req.url === '/' ? html : assets.get(req.url) || '');
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    html = execFileSync('docker', ['run', '--rm', '--entrypoint', 'php', '-e', `QUICK_EDIT_TEST_URL=${origin}`,
        '-e', 'APP_ENV=testing', '-e', 'CACHE_STORE=array',
        '-v', `${root}:/var/www/html`, '-v', `${process.env.HEROKID_TEST_VENDOR_DIR || root + 'vendor'}:/var/www/html/vendor:ro`,
        '-w', '/var/www/html', process.env.HEROKID_TEST_IMAGE || 'sail-8.5/app', 'tests/Support/render-order-quick-edit.php'], { encoding: 'utf8' });
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
});
after(async () => { await browser?.close(); if (server) await new Promise(resolve => server.close(resolve)); });

async function pageFixture(width = 1200) {
    const page = await browser.newPage({ viewport: { width, height: 844 } });
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route('**/quick-edit-options', (route) => route.fulfill({ json: options }));
    await page.route('**/photo-test', (route) => route.fulfill({ contentType: 'image/png', body: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0S8AAAAASUVORK5CYII=', 'base64') }));
    await page.goto(origin);
    await page.waitForFunction(() => document.querySelector('[data-order-quick-edit]')?.dataset.initialized === '1');
    return { page, errors };
}

test('contact can be edited directly; dialog starts closed and closes with Escape', async () => {
    const { page, errors } = await pageFixture();
    const dialog = page.locator('[data-quick-dialog]');
    assert.equal(await dialog.isVisible(), false);
    await page.locator('[data-quick-open="contact"]').click();
    assert.equal(await page.locator('input[name="phone"]').inputValue(), '01012345678');
    await page.keyboard.press('Escape');
    assert.equal(await dialog.isVisible(), false);
    await page.locator('[data-quick-open="contact"]').click();
    await page.locator('input[name="parent_name"]').fill('اسم صحيح');
    await page.locator('#quick-change-reason').fill('تصحيح اسم العميل.');
    let body;
    await page.route('**/contact', route => { body = route.request().postData(); return route.fulfill({ json: { message: 'تم الحفظ' } }); });
    await page.locator('[data-quick-save]').click();
    await page.waitForFunction(() => !document.querySelector('[data-quick-dialog]').open);
    assert.ok(body.includes('اسم صحيح'));
    assert.ok(body.includes('PATCH'));
    assert.ok(!body.includes('product_id'));
    assert.deepEqual(errors, []);
    await page.close();
});

test('mobile product search, child photo reuse and validation retry retain one operation key', async () => {
    const { page, errors } = await pageFixture(390);
    await page.locator('[data-quick-open="add"]').click();
    await page.locator('select[name="product_id"]').waitFor();
    await page.locator('input[type="search"]').fill('ستيكر');
    assert.equal(await page.locator('select[name="product_id"] option').count(), 2);
    await page.locator('select[name="product_id"]').selectOption('2');
    await page.locator('select[name="reuse_child_order_id"]').selectOption('1');
    assert.equal(await page.locator('input[name="personalization[child_name]"]').inputValue(), 'ليلى أحمد');
    assert.equal(await page.locator('[data-quick-fields] img').count(), 1);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    const saveBox = await page.locator('[data-quick-save]').boundingBox();
    assert.ok(saveBox.y + saveBox.height <= 844);
    if (process.env.HEROKID_UI_SCREENSHOT) await page.screenshot({ path: process.env.HEROKID_UI_SCREENSHOT });
    const bodies = [];
    await page.route('**/groups/1/products', route => {
        bodies.push(route.request().postData());
        return route.fulfill({ status: bodies.length === 1 ? 422 : 200,
            json: bodies.length === 1 ? { errors: { school_name: ['راجع اسم المدرسة'] } } : { message: 'تم الحفظ' } });
    });
    await page.locator('#quick-change-reason').fill('إضافة منتج للطفل الموجود.');
    await page.locator('[data-quick-save]').click();
    await page.getByText('راجع اسم المدرسة').waitFor();
    assert.equal(await page.locator('[data-quick-dialog]').isVisible(), true);
    await page.locator('[data-quick-save]').click();
    await page.waitForFunction(() => !document.querySelector('[data-quick-dialog]').open);
    assert.equal(bodies.length, 2);
    const key = body => body.match(/name="request_key"\r\n\r\n([^\r]+)/)?.[1];
    assert.ok(key(bodies[0]));
    assert.equal(key(bodies[0]), key(bodies[1]));
    assert.ok(!bodies[0].includes('name="photos[]"'));
    assert.deepEqual(errors, []);
    await page.close();
});

test('single item save submits only changed fields and does not reupload existing photos', async () => {
    const { page, errors } = await pageFixture();
    await page.locator('[data-quick-open="item"]').click();
    await page.locator('input[name="personalization[school_name]"]').fill('مدرسة جديدة');
    await page.locator('#quick-change-reason').fill('تعديل اسم المدرسة فقط.');
    let body;
    await page.route('**/products/9', route => { body = route.request().postData(); return route.fulfill({ json: { message: 'تم الحفظ' } }); });
    await page.locator('[data-quick-save]').click();
    await page.waitForFunction(() => !document.querySelector('[data-quick-dialog]').open);
    assert.ok(body.includes('personalization[school_name]'));
    assert.ok(!body.includes('personalization[child_name]'));
    assert.ok(!body.includes('name="photos[]"'));
    assert.deepEqual(errors, []);
    await page.close();
});

test('story language edit submits only language, leaving child and checkout contact alone', async () => {
    const { page, errors } = await pageFixture();
    await page.locator('[data-quick-open="story"]').click();
    await page.locator('select[name="language"]').selectOption('en');
    await page.locator('#quick-change-reason').fill('طلب العميل قصة إنجليزية.');
    let body;
    await page.route('**/quick-story-details', route => { body = route.request().postData(); return route.fulfill({ json: { message: 'تم الحفظ' } }); });
    await page.locator('[data-quick-save]').click();
    await page.waitForFunction(() => !document.querySelector('[data-quick-dialog]').open);
    assert.ok(body.includes('name="language"'));
    assert.ok(!body.includes('name="child_name"'));
    assert.ok(!body.includes('name="phone"'));
    assert.deepEqual(errors, []);
    await page.close();
});
