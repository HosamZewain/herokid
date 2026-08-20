<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChildProfile extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['profile_photo_disk', 'profile_photo_path'];

    protected $casts = [
        'birth_date' => 'date',
        'interests' => 'array',
        'photo_reuse_consent_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ChildProfile $profile): void {
            $profile->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(MobileDraft::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ChildProfilePhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activePhotos(): HasMany
    {
        return $this->photos()->where('status', 'active')->whereNull('deleted_at');
    }
}
