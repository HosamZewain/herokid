@can('orders.conversations.view')
<div data-conversation-dock class="hk-conversation-dock" dir="ltr" aria-label="محادثات واتساب العملاء"></div>
<template id="hk-conversation-template">
    <section class="hk-conversation" dir="rtl" role="region" aria-label="محادثة واتساب">
        <header class="hk-conversation-header">
            <x-front-icon name="user-circle" class="hk-conversation-avatar" />
            <div class="hk-conversation-heading"><h2 data-chat-title></h2><p>واتساب · عرض المحادثة</p></div>
            <button type="button" data-chat-minimize aria-label="تصغير المحادثة">تصغير</button>
            <button type="button" data-chat-close aria-label="إغلاق المحادثة"><x-front-icon name="x-mark" /></button>
        </header>
        <div data-chat-content class="hk-conversation-content">
            <div class="hk-conversation-toolbar"><span data-chat-state role="status">تحميل الرسائل المحفوظة…</span><button type="button" data-chat-refresh>تحديث</button></div>
            <div data-chat-notice class="hk-conversation-notice" role="status" hidden></div>
            <div data-chat-scroll class="hk-conversation-scroll">
                <button type="button" data-chat-older class="hk-conversation-older" hidden>عرض رسائل أقدم</button>
                <div data-chat-messages class="hk-conversation-messages" aria-live="polite" aria-relevant="additions text"></div>
                <p data-chat-empty class="hk-conversation-empty" hidden>لا توجد رسائل محفوظة لهذا الرقم.</p>
            </div>
            <footer>عرض فقط — إرسال الرسائل سيتاح لاحقاً</footer>
        </div>
    </section>
</template>
@endcan
