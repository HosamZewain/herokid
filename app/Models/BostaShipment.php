<?php

namespace App\Models;

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
    ];

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

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
