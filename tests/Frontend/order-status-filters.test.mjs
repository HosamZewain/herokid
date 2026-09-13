import { before, after, test } from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const root = fileURLToPath(new URL('../../', import.meta.url));
let server, browser, origin;
before(async () => {
    // Render real Blade and load the production bundle; never inject Alpine.
    const html = execFileSync('docker', ['compose', 'exec', '-T', 'laravel.test', 'php', 'tests/Support/render-status-filters.php'], { cwd: root, encoding: 'utf8' });
    const assets = new Map();
    for (const name of await fs.readdir(root + 'public/build/assets')) {
        assets.set('/build/assets/' + name, await fs.readFile(root + 'public/build/assets/' + name));
    }
    server = http.createServer((request, response) => {
        response.setHeader('Content-Type', request.url.endsWith('.js') ? 'text/javascript' : request.url.endsWith('.css') ? 'text/css' : 'text/html');
        response.end(request.url === '/' ? html : assets.get(request.url) || '');
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({ executablePath: process.env.BROWSER_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: true });
});
after(async () => { await browser?.close(); if (server) await new Promise(resolve => server.close(resolve)); });

test('real application bundle controls all four filters without Alpine', async () => {
    const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(origin);
    await page.waitForFunction(() => Boolean(window.HeroKidImageUpload));
    assert.equal(await page.evaluate(() => typeof window.Alpine), 'undefined');
    for (const name of ['status', 'shipping_status', 'printing_status', 'payment_status']) {
        const filter = page.locator(`[data-status-filter="${name}"]`);
        const menu = filter.locator(`#${name}-options`);
        assert.equal(await menu.isVisible(), false);
        await filter.locator('summary').click();
        assert.equal(await menu.isVisible(), true);
        await filter.locator('input[value="two"]').check();
        assert.equal(await filter.locator('[data-status-summary]').textContent(), '2 حالات محددة');
        assert.deepEqual(await page.locator('form').evaluate((form, key) => new FormData(form).getAll(key + '[]'), name), ['one', 'two']);
        await filter.locator('summary').click();
        assert.equal(await menu.isVisible(), false);
        await filter.locator('summary').click();
        await page.keyboard.press('Escape');
        assert.equal(await menu.isVisible(), false);
        await filter.locator('summary').click();
        await filter.locator('[data-status-clear]').click();
        assert.equal(await filter.locator('input:checked').count(), 0);
        await page.locator('#outside').click();
        assert.equal(await menu.isVisible(), false);
    }
    await page.locator('[data-status-filter="status"] summary').click();
    await page.locator('[data-status-filter="payment_status"] summary').click();
    assert.equal(await page.locator('details[open]').count(), 1);
    await page.locator('#outside').click();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.locator('[data-status-filter="status"] summary').click();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.deepEqual(errors, []);
    await page.close();
});

test('native filters start closed and can toggle even without JavaScript', async () => {
    const page = await browser.newPage({ javaScriptEnabled: false });
    await page.goto(origin);
    assert.equal(await page.locator('details[open]').count(), 0);
    const filter = page.locator('[data-status-filter="status"]');
    await filter.locator('summary').click();
    assert.equal(await filter.locator('#status-options').isVisible(), true);
    await filter.locator('summary').click();
    assert.equal(await filter.locator('#status-options').isVisible(), false);
    await page.close();
});
