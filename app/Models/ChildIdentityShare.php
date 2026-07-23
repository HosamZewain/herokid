<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChildIdentityShare extends Model
{
    use SoftDeletes;

    public const VARIANTS = ['feed', 'story', 'og'];

    protected $guarded = [];

    protected $hidden = [
        'guest_session_hash',
        'ip_hash',
        'feed_card_path',
        'story_card_path',
        'og_card_path',
    ];

    protected $casts = [
        'share_enabled' => 'boolean',
        'display_child_first_name' => 'boolean',
        'consent_accepted_at' => 'datetime',
        'cards_generated_at' => 'datetime',
        'last_shared_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function identityRequest(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityRequest::class, 'child_identity_request_id')->withTrashed();
    }

    public function generationAttempt(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityGenerationAttempt::class, 'generation_attempt_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChildIdentityShareEvent::class);
    }

    public function cardPath(string $variant): ?string
    {
        return match ($variant) {
            'feed' => $this->feed_card_path,
            'story' => $this->story_card_path,
            'og' => $this->og_card_path,
            default => null,
        };
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->share_enabled
            && $this->status === 'ready'
            && ! $this->trashed()
            && filled($this->og_card_path);
    }
}
