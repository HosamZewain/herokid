<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BostaShipmentEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    public function shipment()
    {
        return $this->belongsTo(BostaShipment::class, 'bosta_shipment_id');
    }
}
