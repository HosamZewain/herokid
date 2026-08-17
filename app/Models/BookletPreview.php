<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class BookletPreview extends Model
{
    use SoftDeletes;

    public const SOURCE_TYPES = ['order', 'story', 'standalone'];

    protected $guarded = [];

    protected $hidden = [
        'public_token_hash',
        'public_token_encrypted',
    ];

    protected $casts = [
        'show_on_story' => 'boolean',
        'last_viewed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BookletPreviewVersion::class)->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(BookletPreviewVersion::class, 'current_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function plainPublicToken(): ?string
    {
        try {
            return Crypt::decryptString($this->public_token_encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function publicUrl(): ?string
    {
        $token = $this->plainPublicToken();

        return $token ? route('booklet-previews.show', ['token' => $token]) : null;
    }

    public function publicScenesUrl(): ?string
    {
        $token = $this->plainPublicToken();

        return $token ? route('booklet-previews.scenes', ['token' => $token]) : null;
    }

    public function isPubliclyAvailable(): bool
    {
        if ($this->trashed() || $this->status !== 'active' || ! $this->current_version_id) {
            return false;
        }

        if ($this->source_type === 'order') {
            return $this->order_id !== null && $this->order && ! $this->order->trashed();
        }

        if ($this->source_type === 'story') {
            return $this->story_id !== null && $this->story !== null;
        }

        return true;
    }
}
