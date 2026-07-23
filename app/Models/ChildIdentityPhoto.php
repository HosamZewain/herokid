<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ChildIdentityPhoto extends Model
{
    protected $guarded = [];

    public function identityRequest(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityRequest::class, 'child_identity_request_id')->withTrashed();
    }

    public function attempts(): BelongsToMany
    {
        return $this->belongsToMany(
            ChildIdentityGenerationAttempt::class,
            'child_identity_attempt_photos',
            'child_identity_photo_id',
            'child_identity_generation_attempt_id'
        );
    }
}
