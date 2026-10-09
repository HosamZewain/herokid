@can('orders.view')
@can('orders.conversations.view')
@php($notificationTitle = auth()->user()->hasPermission('orders.conversations.view-all') ? 'رسائل كل الطلبات النشطة — بانتظار الرد' : 'رسائل طلباتي النشطة — بانتظار الرد')
<div class="hk-chat-notifications" data-chat-notifications data-label="{{ $notificationTitle }}" data-url="{{ route('admin.orders.conversation.notifications') }}">
    <button type="button" data-chat-notifications-toggle aria-label="{{ $notificationTitle }}" aria-expanded="false" class="hk-chat-notification-toggle">
        <x-front-icon name="envelope" /><span data-chat-unread-count hidden>0</span>
    </button>
    <div data-chat-notifications-menu class="hk-chat-notifications-menu" hidden>
        <strong>{{ $notificationTitle }}</strong>
        <div data-chat-notifications-items></div>
        <p data-chat-notifications-empty>لا توجد محادثات بانتظار الرد.</p>
        <p data-chat-notifications-error role="status" hidden>تعذر تحديث الإشعارات حالياً.</p>
    </div>
</div>
@endcan
@endcan
