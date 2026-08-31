<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OrderPaymentEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'previous_paid_amount_cents' => 'integer',
        'new_paid_amount_cents' => 'integer',
        'amount_delta_cents' => 'integer',
        'affects_collection_stats' => 'boolean',
        'occurred_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Payment ledger events are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Payment ledger events cannot be deleted.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
