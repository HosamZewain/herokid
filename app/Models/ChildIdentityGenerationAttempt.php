<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChildIdentityGenerationAttempt extends Model
{
    public const STATUSES = ['pending', 'processing', 'succeeded', 'failed', 'rejected', 'cancelled'];

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'request_metadata' => 'array',
        'response_metadata' => 'array',
        'cost_usd' => 'decimal:6',
        'usd_to_egp_rate' => 'decimal:6',
        'cost_egp' => 'decimal:4',
    ];

    public function identityRequest(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityRequest::class, 'child_identity_request_id')->withTrashed();
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(
            ChildIdentityPhoto::class,
            'child_identity_attempt_photos',
            'child_identity_generation_attempt_id',
            'child_identity_photo_id'
        )->withPivot(['disk', 'path', 'checksum', 'sort_order'])->withTimestamps();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'منتظرة',
            'processing' => 'جارية',
            'succeeded' => 'ناجحة',
            'failed' => 'فاشلة',
            'rejected' => 'مرفوضة',
            'cancelled' => 'ملغاة',
            default => $this->status,
        };
    }
}
