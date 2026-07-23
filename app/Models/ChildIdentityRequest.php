<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChildIdentityRequest extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'incomplete',
        'photos_uploaded',
        'queued',
        'processing',
        'generated',
        'generation_failed',
        'approved',
        'story_selected',
        'in_cart',
        'converted',
        'cancelled',
    ];

    protected $guarded = [];

    protected $hidden = ['resume_token_hash'];

    protected $casts = [
        'consent_accepted_at' => 'datetime',
        'marketing_consent_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'converted_at' => 'datetime',
        'total_cost_usd' => 'decimal:6',
        'total_cost_egp' => 'decimal:4',
        'deleted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ChildIdentityPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function validPhotos(): HasMany
    {
        return $this->photos()->where('validation_status', 'valid')->where('upload_status', 'uploaded');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ChildIdentityGenerationAttempt::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ChildIdentityEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function approvedAttempt(): BelongsTo
    {
        return $this->belongsTo(ChildIdentityGenerationAttempt::class, 'approved_attempt_id');
    }

    public function selectedCategory(): BelongsTo
    {
        return $this->belongsTo(StoryCategory::class, 'selected_story_category_id');
    }

    public function selectedStory(): BelongsTo
    {
        return $this->belongsTo(Story::class, 'selected_story_id');
    }

    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id')->withTrashed();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->withTrashed();
    }

    public function successfulOutputsUsed(): int
    {
        return $this->attempts()->whereNotNull('output_storage_path')->count();
    }

    public function statusDuringGeneration(string $generationStatus): string
    {
        if ($this->converted_at) {
            return 'converted';
        }

        if ($this->approved_attempt_id
            && in_array($this->status, ['approved', 'story_selected', 'in_cart'], true)) {
            return $this->status;
        }

        return $generationStatus;
    }

    public function statusLabel(?string $status = null): string
    {
        return self::statusLabelFor($status ?? $this->status);
    }

    public static function statusLabelFor(string $status): string
    {
        return match ($status) {
            'incomplete' => 'غير مكتمل',
            'photos_uploaded' => 'تم رفع الصور',
            'queued' => 'في قائمة الانتظار',
            'processing' => 'جاري التوليد',
            'generated' => 'تم التوليد',
            'generation_failed' => 'فشل التوليد',
            'approved' => 'تم الاعتماد',
            'story_selected' => 'تم اختيار القصة',
            'in_cart' => 'في السلة',
            'converted' => 'تحول إلى طلب',
            'cancelled' => 'ملغي',
            default => $status,
        };
    }

    public function genderLabel(): string
    {
        return match ($this->gender) {
            'boy' => 'ولد',
            'girl' => 'بنت',
            default => 'غير محدد',
        };
    }

    public function displayChildName(): string
    {
        return $this->child_name ?: 'الطفل';
    }
}
