<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobilePushDelivery extends Model
{
    protected $guarded = [];

    protected $casts = ['attempts' => 'integer', 'sent_at' => 'datetime'];

    public function notification()
    {
        return $this->belongsTo(MobileNotification::class, 'mobile_notification_id');
    }

    public function device()
    {
        return $this->belongsTo(DeviceInstallation::class, 'device_installation_id');
    }
}
