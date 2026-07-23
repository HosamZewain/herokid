<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChildIdentityEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    public function identityRequest(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityRequest::class, 'child_identity_request_id')->withTrashed();
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityGenerationAttempt::class, 'child_identity_generation_attempt_id');
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
