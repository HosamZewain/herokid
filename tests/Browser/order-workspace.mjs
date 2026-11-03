// Run from repository root after HK_WORKSPACE_RENDER=1 AdminOrderAssignmentTest.
// Entire browser network is fulfilled from a synthetic local fixture or aborted.
import {chromium} from 'playwright-core';
import fs from 'node:fs/promises';
import assert from 'node:assert/strict';
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'});
const page=await browser.newPage({viewport:{width:1440,height:1100}});
let errors=[];page.on('pageerror',e=>errors.push(e.message));
let html=await fs.readFile('storage/framework/testing/workspace-preview.html','utf8');
await page.route('**/*',async route=>{
 const url=new URL(route.request().url());
 if(url.pathname==='/admin/orders/workspace/progress') return route.fulfill({contentType:'application/json',body:JSON.stringify({ids:[1],total:2,counts:{all:2,automation:1,team:1,attention:1},revoked:[],rows:[{id:1,assignee:'Assigned Employee',summary:{state:'intervention',label:'صفحة تحتاج مراجعة',reason:'فشل صفحة واحدة',running:true,tracking:true,parts:[{stage:'book_pages',label:'صفحات التلوين: 8 من 20',done:8,total:20}],updated_at:'2026-11-03',assignment_version:1},badges:{status:{label:'جاري التوليد',color:'bg-blue-100 text-blue-700'}}}]})});
 if(url.pathname.startsWith('/build/assets/')) {try{return route.fulfill({body:await fs.readFile('public'+url.pathname),contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript'});}catch{}}
 if(url.pathname==='/admin/orders') return route.fulfill({contentType:'text/html',body:html});
 return route.abort();
});
await page.goto('http://localhost/admin/orders');await page.waitForTimeout(700);
await page.screenshot({path:'/tmp/hk-workspace-desktop.png',fullPage:true});
console.log(JSON.stringify({desktopOverflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),errors}));
const check=page.locator('[data-bulk-order-checkbox]:visible').first();await check.check();
const row=page.locator('[data-assignment-block]:visible').first();await row.locator('details').first().locator('summary').click();
await row.locator('[name="reason"]').first().fill('keep my text');
await page.waitForTimeout(10500);
console.log(JSON.stringify({selectionPreserved:await check.isChecked(),editorPreserved:await row.locator('[name="reason"]').first().inputValue(),notice:await page.locator('[data-workspace-refresh]').isVisible(),redSummary:await page.locator('[data-automation-summary]:visible').first().getAttribute('data-state'),errors}));
assert.equal(await check.isChecked(),true);
assert.equal(await row.locator('[name=\"reason\"]').first().inputValue(),'keep my text');
assert.equal(await page.locator('[data-workspace-refresh]').isVisible(),true);
await page.setViewportSize({width:390,height:844});await page.goto('http://localhost/admin/orders');await page.evaluate(()=>scrollTo(0,0));await page.waitForTimeout(300);await page.screenshot({path:'/tmp/hk-workspace-mobile.png',fullPage:true});
console.log(JSON.stringify({mobileOverflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth)}));
assert.deepEqual(errors,[]);
assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
await browser.close();
