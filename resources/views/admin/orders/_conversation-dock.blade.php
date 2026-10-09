@can('orders.conversations.view')
<div data-conversation-dock class="hk-conversation-dock" dir="ltr" aria-label="محادثات واتساب العملاء"
    data-window-storage-key="hk-conversations:v1:{{ auth()->id() }}:{{ hash('sha256', (string) config('robodesk.conversations.account_key', 'primary')) }}"
    data-history-template="{{ route('admin.orders.conversation.show', '__ORDER__') }}"
    data-sync-template="{{ route('admin.orders.conversation.sync', '__ORDER__') }}"
    data-reply-template="{{ route('admin.orders.conversation.reply', '__ORDER__') }}"
    data-read-template="{{ route('admin.orders.conversation.read', '__ORDER__') }}"
    data-logout-url="{{ route('logout') }}"></div>
<div data-conversation-bubbles class="hk-conversation-bubbles" dir="ltr" aria-label="المحادثات المصغّرة" hidden></div>
<template id="hk-conversation-bubble-template">
    <div class="hk-chat-bubble">
        <button type="button" data-chat-reopen class="hk-chat-bubble-open" aria-label="فتح المحادثة">
            <x-front-icon name="user-circle" />
        </button>
        <button type="button" data-chat-bubble-close class="hk-chat-bubble-close" aria-label="إغلاق المحادثة">
            <x-front-icon name="x-mark" />
        </button>
    </div>
</template>
<template id="hk-conversation-template">
    <section class="hk-conversation" dir="rtl" role="region" aria-label="محادثة واتساب">
        <header class="hk-conversation-header">
            <button type="button" data-chat-minimize class="hk-conversation-toggle" aria-label="تصغير المحادثة" title="اضغط لتصغير المحادثة">
                <x-front-icon name="user-circle" class="hk-conversation-avatar" />
                <span class="hk-conversation-heading"><span data-chat-title></span><span class="hk-conversation-subtitle">واتساب · عرض المحادثة</span></span>
            </button>
            <a data-chat-order class="hk-conversation-order-link" dir="ltr" hidden></a>
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
            @can('orders.conversations.reply')
            <form data-chat-composer class="hk-chat-composer">
                <p data-chat-reply-window class="hk-chat-reply-window" role="status">جاري التحقق من مهلة الرد…</p>
                <div data-chat-image-preview class="hk-chat-image-preview" hidden><img alt="الصورة المختارة للإرسال"><button type="button" data-chat-image-remove aria-label="إزالة الصورة">×</button></div>
                <textarea data-chat-reply-text aria-label="الرسالة" maxlength="4096" rows="2" placeholder="اكتب رسالة…" autocomplete="off"></textarea>
                <div data-chat-emojis class="hk-chat-emojis" hidden aria-label="اختيار إيموجي"></div>
                <div class="hk-chat-composer-actions">
                    <button type="button" data-chat-emoji aria-label="إضافة إيموجي" aria-expanded="false">☺</button>
                    <label class="hk-chat-image-button" title="إضافة صورة"><span>＋ صورة</span><input data-chat-image type="file" accept="image/jpeg,image/png,image/webp" aria-label="إضافة صورة"></label>
                    <span class="hk-chat-image-limit">حتى 5 ميجا</span>
                    <button type="submit" data-chat-send class="hk-chat-send" disabled>إرسال</button>
                </div>
                <p data-chat-reply-error class="hk-chat-reply-error" role="alert" hidden></p>
            </form>
            @else
            <footer>عرض فقط — ليس لديك صلاحية الرد</footer>
            @endcan
        </div>
    </section>
</template>
@endcan
