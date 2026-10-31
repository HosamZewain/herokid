import { after, before, test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs/promises';
import { chromium } from 'playwright-core';
import { newNode } from '../../resources/js/order-process-builder.js';

const escape = v => JSON.stringify(v).replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');
const statuses = {new:'طلب جديد غير مؤكد',new_confirmed:'طلب جديد مؤكد'};
const variables = {customer_name:'الاسم',order_reference:'رقم الطلب',order_details:'التفاصيل',order_total:'القيمة',story_names:'القصص',child_names:'الأطفال',product_names:'المنتجات',identity_preview_url:'المعاينة',remaining_amount:'المتبقي'};
const definition = {schema:2,scope:'checkout',trigger:'checkout_created',conditions:{type:'any',status:'new',story_ids:[],product_ids:[]},nodes:[newNode('end','finish')]};
let browser, server, origin;
before(async()=> {
    server=http.createServer(async(req,res)=> {
        if(req.url==='/builder.js') { res.setHeader('Content-Type','text/javascript'); res.end(await fs.readFile('resources/js/order-process-builder.js')); return; }
        res.setHeader('Content-Type','text/html; charset=utf-8');
        res.end(`<form><input name="definition_json" type="hidden" value="${escape(definition)}"><select data-scope><option value="checkout">checkout</option></select><select data-trigger><option value="checkout_created">new</option></select><select data-target><option value="any">any</option></select><select data-status><option value="new">new</option></select><select data-stories multiple><option value="12">قصة</option></select><select data-products multiple></select><div data-process-builder data-initial="${escape(definition)}" data-statuses="${escape(statuses)}" data-variables="${escape(variables)}"><div data-nodes></div><select data-new-type><option value="whatsapp">رسالة</option><option value="wait_response">انتظار</option><option value="condition">شرط</option><option value="generate_identity">توليد</option><option value="approve_identity">اعتماد</option></select><button type="button" data-add>إضافة</button></div><button data-save disabled>حفظ</button></form><script type="module">import {initializeProcessBuilder} from '/builder.js'; initializeProcessBuilder(document.querySelector('[data-process-builder]')); document.querySelector('form').addEventListener('submit',e=>{e.preventDefault();window.saved=JSON.parse(document.querySelector('[name=definition_json]').value);});</script>`);
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    origin=`http://127.0.0.1:${server.address().port}`;
    browser=await chromium.launch({executablePath:process.env.BROWSER_EXECUTABLE || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',headless:true});
});
after(async()=>{await browser?.close(); await new Promise(resolve=>server.close(resolve));});

test('new nodes own independent configs and never share mutable variables',()=> {
    const a=newNode('whatsapp','a'),b=newNode('whatsapp','b'); a.config.variables.pop(); assert.equal(b.config.variables.length,4);
});
test('builder adds connected steps, serializes selections, and escapes labels',async()=> {
    const page=await browser.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto(origin); await page.waitForSelector('[data-ready=true]');
    await page.locator('[data-add]').click();
    assert.equal(await page.locator('[data-node]').count(),2);
    await page.locator('[data-node]').first().locator('[data-field=label]').fill('<img src=x onerror="window.injected=true">');
    await page.locator('[data-node]').first().locator('[data-field=label]').blur();
    assert.equal(await page.locator('[data-nodes] img').count(),0);
    await page.selectOption('[data-stories]',['12']);
    await page.locator('[data-save]').click();
    const d=await page.evaluate(()=>window.saved);
    assert.deepEqual(d.conditions.story_ids,[12]);
    assert.equal(d.nodes[0].next,'finish'); assert.equal(d.nodes[1].type,'end');
    assert.equal(d.nodes[0].label,'<img src=x onerror="window.injected=true">');
    assert.deepEqual(errors,[]); assert.equal(await page.evaluate(()=>window.injected),undefined);
    await page.close();
});
test('adding a wait wires the send, supports new reply branches and timeout target',async()=> {
    const page=await browser.newPage();await page.goto(origin);await page.waitForSelector('[data-ready=true]');
    await page.locator('[data-add]').click();
    await page.selectOption('[data-new-type]','wait_response');await page.locator('[data-add]').click();
    const wait=page.locator('[data-node]').nth(1);
    await wait.locator('[data-command=case-add]').click();
    await wait.locator('[data-field="config.cases.1.text"]').fill('رفض الطلب');
    await wait.locator('[data-field="config.cases.1.next"]').selectOption('finish');
    await wait.locator('[data-field="config.timeout_hours"]').fill('48');
    await page.locator('[data-save]').click();
    const d=await page.evaluate(()=>window.saved);
    assert.equal(d.nodes[0].next,d.nodes[1].id);assert.equal(d.nodes[1].config.message_node,d.nodes[0].id);
    assert.equal(d.nodes[1].config.cases[1].text,'رفض الطلب');assert.equal(d.nodes[1].config.timeout_hours,48);
    assert.equal(d.nodes[1].config.timeout_next,'finish');await page.close();
});
test('condition field switches valid values and reordering preserves step identity',async()=> {
    const page=await browser.newPage();await page.goto(origin);await page.waitForSelector('[data-ready=true]');
    await page.selectOption('[data-new-type]','condition');await page.locator('[data-add]').click();
    await page.locator('[data-field="config.field"]').selectOption('type');
    await page.locator('[data-field="config.value"]').selectOption('mixed');
    const before=await page.locator('[data-node]').first().getAttribute('data-node');
    await page.locator('[data-node]').first().locator('[data-command=down]').click();
    assert.equal(await page.locator('[data-node]').nth(1).getAttribute('data-node'),before);
    await page.locator('[data-node]').nth(1).locator('[data-command=up]').click();
    await page.locator('[data-save]').click();
    const d=await page.evaluate(()=>window.saved);assert.equal(d.nodes[0].config.value,'mixed');await page.close();
});

test('identity steps retain fractional budget and exact eight-field template mapping',async()=> {
    const page=await browser.newPage();await page.goto(origin);await page.waitForSelector('[data-ready=true]');
    await page.selectOption('[data-new-type]','generate_identity');await page.locator('[data-add]').click();
    await page.locator('[data-field="config.budget_usd"]').fill('0.25');
    await page.selectOption('[data-new-type]','whatsapp');await page.locator('[data-add]').click();
    await page.locator('[data-field="config.template"]').fill('herokid_identity_review_payment');
    await page.locator('[data-field="config.template"]').blur();
    await page.locator('[data-save]').click();
    const d=await page.evaluate(()=>window.saved);
    assert.equal(d.nodes[0].config.budget_usd,0.25);
    assert.deepEqual(d.nodes[1].config.variables,['customer_name','order_reference','story_names','child_names','product_names','identity_preview_url','order_total','remaining_amount']);
    await page.close();
});
