<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BostaPickup extends Model
{
    protected $guarded = [];

    protected $casts = ['scheduled_date' => 'date', 'provider_response' => 'array'];

    public function shipments()
    {
        return $this->belongsToMany(BostaShipment::class, 'bosta_pickup_shipment');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
