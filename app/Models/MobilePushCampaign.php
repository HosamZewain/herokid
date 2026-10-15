<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobilePushCampaign extends Model
{
    protected $guarded = [];

    protected $casts = ['data' => 'array'];

    public function notifications()
    {
        return $this->hasMany(MobileNotification::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function target()
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
