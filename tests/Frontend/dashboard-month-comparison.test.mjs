import {before,after,test} from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs/promises';
import {chromium} from 'playwright-core';
let browser,server,origin;
const months=Array.from({length:6},(_,i)=>({key:`2026-${i+5}`,label:`شهر ${i+5} 2026`,short_label:`شهر ${i+5}`,year:2026,partial:i===5,checkouts:100+i*15,story_pieces:180+i*10,product_pieces:240-i*10,shipped_checkouts:80+i*25,average_order_cents:i===0?null:50000+i*1000}));
before(async()=>{
    server=http.createServer(async(req,res)=>{
        if(req.url==='/chart.js'){res.setHeader('Content-Type','text/javascript');res.end(await fs.readFile('resources/js/dashboard-month-comparison.js'));return;}
        res.setHeader('Content-Type','text/html; charset=utf-8');
        res.end(`<style>body{margin:16px}button{max-width:100%}</style><section data-month-comparison data-months='${JSON.stringify(months)}'><div data-month-legend></div><div data-month-plot style="width:100%;height:320px"></div><div data-month-detail></div></section><script type="module">import {initializeMonthComparison} from '/chart.js';initializeMonthComparison(document.querySelector('section'));</script>`);
    });
    await new Promise(r=>server.listen(0,'127.0.0.1',r));origin=`http://127.0.0.1:${server.address().port}`;
    browser=await chromium.launch({executablePath:'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
});
after(async()=>{await browser?.close();await new Promise(r=>server.close(r));});
test('one dual-axis chart toggles series and supports keyboard month selection',async()=>{
    const page=await browser.newPage();await page.goto(origin);await page.waitForSelector('[data-ready=true]');
    assert.equal(await page.locator('svg').count(),1);assert.equal(await page.locator('svg path').count(),5);
    assert.equal(await page.locator('svg [role=button]').count(),6);
    await page.locator('[data-month-legend] button').first().click();
    assert.equal(await page.locator('svg path').count(),4);assert.equal(await page.locator('[data-month-legend] button').first().getAttribute('aria-pressed'),'false');
    await page.locator('svg [role=button]').first().focus();await page.keyboard.press('ArrowRight');
    assert.match(await page.locator('[data-month-detail]').innerText(),/شهر 6 2026/);
    await page.keyboard.press('End');assert.match(await page.locator('[data-month-detail]').innerText(),/حتى الآن/);
    assert.doesNotMatch(await page.locator('svg').innerHTML(),/NaN|Infinity/);await page.close();
});
test('mobile resizing keeps one chart inside the viewport',async()=>{
    const page=await browser.newPage({viewport:{width:390,height:844}});await page.goto(origin);await page.waitForSelector('[data-ready=true]');
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    await page.setViewportSize({width:320,height:720});await page.waitForTimeout(100);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
    assert.equal(await page.locator('svg').count(),1);await page.close();
});
