<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'delivery_details' => 'array',
        'uploaded_photos' => 'array',
        'discount_cents' => 'integer',
        'paid_amount_cents' => 'integer',
        'payment_updated_at' => 'datetime',
        'preview_approved_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (Order $order): void {
            if (filled($order->checkout_group_key)) {
                return;
            }

            $order->forceFill([
                'checkout_group_key' => data_get($order->delivery_details, 'checkout_group') ?: 'ORDER-'.$order->id,
            ])->saveQuietly();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdByAdmin()
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function paymentUpdatedBy()
    {
        return $this->belongsTo(User::class, 'payment_updated_by_user_id');
    }

    public function story()
    {
        return $this->belongsTo(Story::class);
    }

    public function statusLogs()
    {
        return $this->hasMany(OrderStatusLog::class);
    }

    public function previews()
    {
        return $this->hasMany(OrderPreview::class);
    }

    public function attachments()
    {
        return $this->hasMany(OrderAttachment::class)->latest();
    }

    public function bookletPreview()
    {
        return $this->hasOne(BookletPreview::class);
    }

    public function approvedBookletPreviewVersion()
    {
        return $this->belongsTo(BookletPreviewVersion::class, 'approved_booklet_preview_version_id');
    }

    public function previewDecisions()
    {
        return $this->hasMany(BookletPreviewDecision::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function productionPromptOverride()
    {
        return $this->hasOne(OrderProductionPromptOverride::class);
    }

    public function productionPromptSnapshots()
    {
        return $this->hasMany(OrderProductionPromptSnapshot::class)->latest();
    }

    public function childIdentityPromptOverride()
    {
        return $this->hasOne(OrderChildIdentityPromptOverride::class);
    }

    public function childIdentityPromptSnapshots()
    {
        return $this->hasMany(OrderChildIdentityPromptSnapshot::class)->latest();
    }

    public function productionProject()
    {
        return $this->hasOne(ProductionProject::class);
    }

    public function sceneTextSnapshots()
    {
        return $this->hasMany(OrderSceneTextSnapshot::class)->orderBy('scene_number');
    }

    public function childIdentityRequest()
    {
        return $this->belongsTo(ChildIdentityRequest::class)->withTrashed();
    }

    public function childIdentityApprovedAttempt()
    {
        return $this->belongsTo(ChildIdentityGenerationAttempt::class, 'child_identity_approved_attempt_id');
    }

    public function referredByChildIdentityShare()
    {
        return $this->belongsTo(ChildIdentityShare::class, 'referred_by_child_identity_share_id')->withTrashed();
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }

    public function checkoutGroupKey(): string
    {
        return $this->checkout_group_key
            ?: (string) (data_get($this->delivery_details, 'checkout_group') ?: 'ORDER-'.$this->id);
    }
}
