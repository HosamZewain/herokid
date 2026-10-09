<?php

namespace App\Services\RoboDesk\Conversations;

use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\DB;

class ConversationNotifications
{
    public function __construct(private ConversationAccess $access, private OrderConversationService $history) {}

    public function awaitingReply(User $user): array
    {
        $account = (string) config('robodesk.conversations.account_key', 'primary');
        $contacts = [];
        foreach ($this->access->notificationOrders($user)->with('checkoutReference:id,checkout_group_key,short_reference')
            ->orderByDesc('id')->get(['id', 'checkout_group_key', 'parent_name', 'order_number', 'delivery_details']) as $order) {
            $phone = ConversationPhone::canonical(data_get($order->delivery_details, 'phone'));
            if ($phone) {
                $contacts[hash('sha256', $phone)] ??= $order;
            }
        }
        // Read state is per employee; awaiting a human response is shared by the
        // contact. Opening a conversation must not dismiss an unanswered message.
        $query = WhatsAppConversation::query()->where('account_key', $account)->whereIn('phone_hash', array_keys($contacts))
            ->whereExists(fn ($inbound) => $inbound->selectRaw('1')->from('whatsapp_conversation_messages as customer')
                ->whereColumn('customer.conversation_id', 'whatsapp_conversations.id')
                ->where('customer.direction', 'inbound')->where('customer.kind', '!=', 'reaction')
                ->where('customer.id', '=', fn ($latest) => $latest->select('latest.id')->from('whatsapp_conversation_messages as latest')
                    ->whereColumn('latest.conversation_id', 'whatsapp_conversations.id')
                    ->where('latest.direction', 'inbound')->where('latest.kind', '!=', 'reaction')
                    ->orderByDesc('latest.sent_at')->orderByDesc('latest.id')->limit(1))
                ->whereNotExists(fn ($reply) => $reply->selectRaw('1')->from('whatsapp_conversation_messages as reply')
                    ->whereColumn('reply.conversation_id', 'customer.conversation_id')->where('reply.direction', 'outbound')
                    ->where('reply.kind', '!=', 'reaction')
                    ->where(fn ($human) => $human->whereNotNull('reply.employee_name')->orWhere('reply.sender_type', 'agent'))
                    ->where(fn ($accepted) => $accepted->whereNull('reply.delivery_status')->orWhereNotIn('reply.delivery_status', ['failed', 'rejected', 'undelivered']))
                    ->where(fn ($later) => $later->whereColumn('reply.sent_at', '>', 'customer.sent_at')
                        ->orWhere(fn ($tie) => $tie->whereColumn('reply.sent_at', 'customer.sent_at')->whereColumn('reply.id', '>', 'customer.id')))));
        $count = (clone $query)->count();
        $items = $query->addSelect(['read_version' => DB::table('whatsapp_conversation_reads')->select('inbound_version')
            ->whereColumn('conversation_id', 'whatsapp_conversations.id')->where('user_id', $user->id)->limit(1)])
            ->withMax(['messages as latest_inbound_at' => fn ($q) => $q->where('direction', 'inbound')->where('kind', '!=', 'reaction')], 'sent_at')
            ->orderByDesc('latest_inbound_at')->orderByDesc('id')->limit(20)->get()->map(function ($conversation) use ($contacts) {
                $order = $contacts[$conversation->phone_hash];

                return ['order_id' => $order->id, 'contact_title' => $order->parent_name,
                    'order_reference' => $order->checkoutReference?->short_reference ?: $order->order_number,
                    'date' => $conversation->latest_inbound_at,
                    'unread' => (int) $conversation->read_version < (int) $conversation->inbound_version];
            })->all();

        return ['count' => $count, 'items' => $items];
    }

    public function markRead(User $user, Order $order, int $version): void
    {
        [$phone, $account, $hash] = $this->history->contact($order);
        DB::transaction(function () use ($user, $account, $hash, $version): void {
            $conversation = WhatsAppConversation::where('account_key', $account)->where('phone_hash', $hash)->lockForUpdate()->first();
            abort_unless($conversation && $version <= $conversation->inbound_version, 422);
            $previous = DB::table('whatsapp_conversation_reads')->where('conversation_id', $conversation->id)->where('user_id', $user->id)->first();
            DB::table('whatsapp_conversation_reads')->updateOrInsert(['conversation_id' => $conversation->id, 'user_id' => $user->id], [
                'inbound_version' => max($version, (int) ($previous?->inbound_version ?? 0)),
                'created_at' => $previous?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });
    }
}
