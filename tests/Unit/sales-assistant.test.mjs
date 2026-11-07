import test from 'node:test';
import assert from 'node:assert/strict';
import { shouldPoll, trustedFrameMessage, publicUrl, terminalMessage, newClientNonce, checkoutAction } from '../../resources/js/sales-assistant-state.js';
test('polling stops while hidden, closed, idle or already polling',()=>{
    assert.equal(shouldPoll({open:true,hidden:false,pending:true,working:false}),true);
    for(const change of [{open:false},{hidden:true},{pending:false},{working:true}]) assert.equal(shouldPoll({open:true,hidden:false,pending:true,working:false,...change}),false);
});
test('frame messages require exact origin and window, no arbitrary action',()=>{
    const frame={contentWindow:{}};const event={origin:'https://store.test',source:frame.contentWindow,data:{channel:'herokid-sales',stage:'complete'}};
    assert.equal(trustedFrameMessage(event,frame,event.origin),true);
    assert.equal(trustedFrameMessage({...event,source:{}},frame,event.origin),false);
    assert.equal(trustedFrameMessage({...event,origin:'https://attacker.test'},frame,event.origin),false);
    assert.equal(trustedFrameMessage({...event,data:{channel:'herokid-sales',stage:'admin'}},frame,event.origin),false);
});
test('no javascript/data URLs, arbitrary hosts or model-created purchase destinations',()=>{
    for(const url of ['javascript:alert(1)','data:text/html,bad','https://attacker.test/cart']) assert.equal(publicUrl(url,'https://store.test'),null);
    assert.equal(publicUrl('/cart','https://store.test'),'https://store.test/cart');
    assert.equal(publicUrl('data:image/png;base64,abc','https://store.test',true),null);
});
test('uncertain provider replies explain no automatic paid retry',()=>{assert.match(terminalMessage('uncertain'),/تلقائيًا/);});

test('secure UUID fallback works when randomUUID is unavailable and rejects missing crypto',()=>{
    assert.match(newClientNonce({getRandomValues:bytes=>bytes.fill(42)}),/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.throws(()=>newClientNonce({}));
});

test('checkout action opens only a current populated cart and cannot submit an order',()=>{
 const turn={status:'completed',result:{next:'cart',url:'https://attacker.test/admin'}};
 assert.equal(checkoutAction(turn,{line_count:1}),true);
 for(const cart of [undefined,{line_count:0},{line_count:-1},{line_count:'1'}])assert.equal(checkoutAction(turn,cart),false);
 for(const next of ['discover','choose','handoff','confirm','admin'])assert.equal(checkoutAction({...turn,result:{next}},{line_count:1}),false);
 assert.equal(checkoutAction({...turn,status:'queued'},{line_count:1}),false);
});
