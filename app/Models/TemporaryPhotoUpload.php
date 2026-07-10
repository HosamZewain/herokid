<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryPhotoUpload extends Model
{
    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'attached_order_id');
    }

    public function isAttachableFor(string $sessionHash, ?int $userId): bool
    {
        if ($this->status !== 'uploaded') {
            return false;
        }

        if ($this->expires_at->isPast()) {
            return false;
        }

        if ($this->session_hash !== $sessionHash) {
            return false;
        }

        return $this->user_id === null || $this->user_id === $userId;
    }
}
