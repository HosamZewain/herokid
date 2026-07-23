<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildIdentityShareEvent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function share(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityShare::class, 'child_identity_share_id')->withTrashed();
    }

    public function referredIdentity(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityRequest::class, 'referred_child_identity_request_id')->withTrashed();
    }

    public function referredOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'referred_order_id')->withTrashed();
    }
}
