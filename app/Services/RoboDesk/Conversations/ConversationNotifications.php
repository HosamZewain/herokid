<?php

namespace App\Services\RoboDesk\Conversations;

use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Support\Facades\DB;

class ConversationNotifications
{
    public function __construct(private ConversationAccess $access, private OrderConversationService $history) {}

    public function unread(User $user): array
    {
        $account = (string) config('robodesk.conversations.account_key', 'primary');
        $contacts = [];
        foreach ($this->access->assignedActive($user)->with('checkoutReference:id,checkout_group_key,short_reference')
            ->orderByDesc('id')->get(['id', 'checkout_group_key', 'parent_name', 'order_number', 'delivery_details']) as $order) {
            $phone = ConversationPhone::canonical(data_get($order->delivery_details, 'phone'));
            if ($phone) {
                $contacts[hash('sha256', $phone)] ??= $order;
            }
        }
        $query = WhatsAppConversation::query()->where('account_key', $account)->whereIn('phone_hash', array_keys($contacts))
            ->where('inbound_version', '>', 0)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('whatsapp_conversation_reads')
            ->whereColumn('conversation_id', 'whatsapp_conversations.id')->where('user_id', $user->id)
            ->whereColumn('whatsapp_conversation_reads.inbound_version', '>=', 'whatsapp_conversations.inbound_version'));
        $count = (clone $query)->count();
        $items = $query->withMax(['messages as latest_inbound_at' => fn ($q) => $q->where('direction', 'inbound')], 'sent_at')
            ->orderByDesc('latest_inbound_at')->orderByDesc('id')->limit(20)->get()->map(function ($conversation) use ($contacts) {
                $order = $contacts[$conversation->phone_hash];

                return ['order_id' => $order->id, 'contact_title' => $order->parent_name,
                    'order_reference' => $order->checkoutReference?->short_reference ?: $order->order_number,
                    'date' => $conversation->latest_inbound_at];
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
