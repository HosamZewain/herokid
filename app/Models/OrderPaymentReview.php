<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderPaymentReview extends Model
{
    protected $guarded = [];

    protected $casts = ['reviewed_at' => 'datetime', 'review_note' => 'encrypted'];

    /** A later staff-recorded receipt settles earlier attachment alerts, not later ones.
     * Read-only projection preserves the original signal and immutable payment ledger.
     */
    public function scopeActionable(Builder $query): void
    {
        $query->where('order_payment_reviews.status', 'pending')
            ->whereNotExists(function ($payment) {
                $payment->selectRaw('1')->from('order_payment_events as settled_payment')
                    ->whereColumn('settled_payment.checkout_group_key', 'order_payment_reviews.checkout_key')
                    ->where('settled_payment.event_type', 'payment_received')
                    ->whereNotNull('settled_payment.actor_user_id')
                    ->where('settled_payment.amount_delta_cents', '>', 0)
                    ->whereRaw('settled_payment.occurred_at > COALESCE((SELECT MAX(review_message.sent_at) FROM order_payment_review_signals AS review_signal JOIN whatsapp_conversation_messages AS review_message ON review_message.id = review_signal.message_id WHERE review_signal.review_id = order_payment_reviews.id), order_payment_reviews.created_at)')
                    ->whereExists(fn ($order) => $order->selectRaw('1')->from('orders as paid_review_order')
                        ->whereColumn('paid_review_order.checkout_group_key', 'order_payment_reviews.checkout_key')
                        ->whereNull('paid_review_order.deleted_at')->where('paid_review_order.paid_amount_cents', '>', 0));
            });
    }

    public function signals()
    {
        return $this->hasMany(OrderPaymentReviewSignal::class, 'review_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
