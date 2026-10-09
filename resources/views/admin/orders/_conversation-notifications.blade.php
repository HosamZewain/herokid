@can('orders.view')
@can('orders.conversations.view')
<div class="hk-chat-notifications" data-chat-notifications data-url="{{ route('admin.orders.conversation.notifications') }}">
    <button type="button" data-chat-notifications-toggle aria-label="محادثات طلباتي غير المقروءة" aria-expanded="false" class="hk-chat-notification-toggle">
        <x-front-icon name="envelope" /><span data-chat-unread-count hidden>0</span>
    </button>
    <div data-chat-notifications-menu class="hk-chat-notifications-menu" hidden>
        <strong>رسائل طلباتي النشطة</strong>
        <div data-chat-notifications-items></div>
        <p data-chat-notifications-empty>لا توجد محادثات غير مقروءة.</p>
        <p data-chat-notifications-error role="status" hidden>تعذر تحديث الإشعارات حالياً.</p>
    </div>
</div>
@endcan
@endcan
