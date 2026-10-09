@can('orders.conversations.view')
    @if(!($conversationTrashed ?? false))
        <button type="button" class="hk-conversation-open" data-order-conversation
            data-history-url="{{ route('admin.orders.conversation.show', $conversationOrderId) }}"
            data-sync-url="{{ route('admin.orders.conversation.sync', $conversationOrderId) }}"
            data-contact-title="{{ $conversationCustomerName ?: 'محادثة العميل' }}"
            title="عرض محادثة واتساب العميل" aria-label="عرض محادثة واتساب العميل">
            <x-front-icon name="envelope" /><span class="sr-only">محادثة واتساب</span>
        </button>
    @endif
@endcan
