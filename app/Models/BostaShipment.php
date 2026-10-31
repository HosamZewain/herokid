<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BostaShipment extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'provider_response' => 'array',
        'allow_open_package' => 'boolean',
        'is_confirmed_delivery' => 'boolean',
        'delivery_promise_date' => 'datetime',
        'last_event_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $shipment): void {
            $shipment->active_checkout_key = $shipment->superseded_at || $shipment->deleted_at
                ? null : $shipment->checkout_group_key;
        });
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function events()
    {
        return $this->hasMany(BostaShipmentEvent::class);
    }

    public function pickups()
    {
        return $this->belongsToMany(BostaPickup::class, 'bosta_pickup_shipment');
    }

    public function activePickups()
    {
        return $this->belongsToMany(BostaPickup::class, 'bosta_pickup_shipment')->active();
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeAwaitingPickup(Builder $query): Builder
    {
        return $query->current()->where('creation_status', 'created')->whereNull('cancellation_status')
            ->whereDoesntHave('activePickups')
            ->where(fn (Builder $status) => $status
                ->whereNull('shipping_status')
                ->orWhereNotIn('shipping_status', ['shipped', 'delivered', 'returned', 'cancelled']))
            ->where(fn (Builder $state) => $state
                ->whereNull('state_code')
                ->orWhere('state_code', '<', 20));
    }

    public function scopeWithProviderPickupEvidence(Builder $query): Builder
    {
        return $query
            ->whereDoesntHave('activePickups')
            ->where(function (Builder $evidence): void {
                $evidence->where('state_code', '>=', 20)
                    ->orWhereIn('shipping_status', ['shipped', 'delivered', 'returned', 'cancelled']);
            });
    }

    public function isAwaitingPickup(): bool
    {
        $hasPickup = $this->relationLoaded('activePickups')
            ? $this->activePickups->isNotEmpty()
            : $this->activePickups()->exists();

        return ! $this->superseded_at && ! $this->cancellation_status && $this->creation_status === 'created'
            && ! $hasPickup && ! $this->hasProviderPickupEvidence();
    }

    public function hasProviderPickupEvidence(): bool
    {
        return ($this->state_code !== null && $this->state_code >= 20)
            || in_array($this->shipping_status, ['shipped', 'delivered', 'returned', 'cancelled'], true);
    }

    public function pickupState(): string
    {
        $pickups = $this->relationLoaded('activePickups') ? $this->activePickups : $this->activePickups()->get();

        if ($pickups->isNotEmpty()) {
            return $pickups->contains(fn (BostaPickup $pickup): bool => $pickup->created_by_user_id !== null)
                ? 'herokid'
                : 'bosta_dashboard';
        }

        return $this->hasProviderPickupEvidence() ? 'provider_progress' : 'awaiting';
    }
}
