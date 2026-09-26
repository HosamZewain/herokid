<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsitePromoCodeRedemption extends Model
{
    protected $guarded = [];

    protected $casts = [
        'discount_cents' => 'integer',
    ];

    public function promoCode()
    {
        return $this->belongsTo(MobilePromoCode::class, 'mobile_promo_code_id');
    }
}
