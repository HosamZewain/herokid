import '../css/sales-assistant-intake.css';
import { startSalesIntake } from './sales-assistant-intake.js';
// Same-origin native purchase forms. No child/contact/photo values are posted to the chat.
if (window.parent !== window && document.body.dataset.salesEmbedded === '1') {
    const allowed = /^\/(?:cart(?:\/|$)|checkout(?:\/|$)|stories\/[^/]+$|shop\/(?:product|package)\/[^/]+$|shopping-assistant\/cart-edit(?:\/|$))/;
    const report = (stage, metadata = {}) => window.parent.postMessage({channel:'herokid-sales',stage,...metadata},location.origin);
    function contextualUrl(value) {
        const url=new URL(value,location.origin);if(url.origin===location.origin && allowed.test(url.pathname)){url.searchParams.set('_sales_assistant_embed','1');const entry=new URL(location.href).searchParams.get('_sales_entry');if(entry)url.searchParams.set('_sales_entry',entry);return url.href;}return null;
    }
    for(const form of document.forms){const action=contextualUrl(form.action);if(!action)continue;form.action=action;
        if(!form.querySelector('[name="_sales_assistant_embed"]')){const flag=document.createElement('input');flag.type='hidden';flag.name='_sales_assistant_embed';flag.value='1';form.append(flag);}
    }
    document.querySelectorAll('option[value="cancel_previous"]').forEach(option=>option.remove());
    document.querySelectorAll('[data-order-choice="cancel_previous"],input[value="cancel_previous"]').forEach(input=> (input.closest('label') || input).remove());
    for(const link of document.querySelectorAll('a[href]')){const url=contextualUrl(link.href);if(url)link.href=url;else if(!link.hash || new URL(link.href).pathname!==location.pathname){link.target='_blank';link.rel='noopener noreferrer';}}
    document.querySelector('[data-cart-added-toast]')?.remove();
    const ready = () => { startSalesIntake(report); const review=document.querySelector('[data-sales-review]') || (location.pathname==='/checkout/success'?document.querySelector('main'):null);if(review)new ResizeObserver(()=>report('resize',{height:Math.ceil(review.getBoundingClientRect().height+32)})).observe(review); };
    if(document.readyState!=='complete')document.addEventListener('DOMContentLoaded',ready,{once:true});else ready();
    if(document.querySelector('[data-sales-review]'))report('review');
    else if(location.pathname==='/checkout/success')report('complete');
    else if(location.pathname==='/cart'){report('cart');try {if(sessionStorage.getItem('hk-sales-adding')==='1'){sessionStorage.removeItem('hk-sales-adding');report('added');}} catch {}}
    else report('personalization');
    document.addEventListener('submit',event=> {const form=event.target;if(/^\/cart\/(stories|products|packages)\//.test(new URL(form.action).pathname)){try{sessionStorage.setItem('hk-sales-adding','1');}catch{}}},true);
    document.querySelector('[data-sales-confirm]')?.addEventListener('submit',event=> {const button=event.target.querySelector('button[type="submit"]');button.disabled=true;button.textContent='بنسجّل طلبك…';});
}
