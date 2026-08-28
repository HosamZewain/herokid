<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\User;
use App\Support\AdminActivityLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class OrderAdminNoteService
{
    public function notesFor(Order $order): Collection
    {
        return OrderAdminNote::query()
            ->with('author:id,name')
            ->where('checkout_group_key', $order->checkoutGroupKey())
            ->latest('created_at')
            ->latest('id')
            ->get();
    }

    public function add(Order $order, User $author, string $body, Request $request): OrderAdminNote
    {
        $note = OrderAdminNote::query()->create([
            'checkout_group_key' => $order->checkoutGroupKey(),
            'representative_order_id' => $order->id,
            'author_user_id' => $author->id,
            'author_name' => $author->name,
            'body' => trim($body),
        ]);

        AdminActivityLogger::log(
            action: 'order.note_added',
            description: 'إضافة ملاحظة دائمة إلى عملية الشراء '.$order->checkoutGroupKey().'.',
            subject: $order,
            properties: [
                'note_id' => $note->id,
                'checkout_group_key' => $note->checkout_group_key,
                'body_length' => mb_strlen($note->body),
            ],
            admin: $author,
            request: $request,
        );

        return $note;
    }
}
