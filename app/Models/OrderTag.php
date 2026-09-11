<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderTag extends Model
{
    protected $guarded = [];

    public function checkoutReferences(): BelongsToMany
    {
        return $this->belongsToMany(
            OrderCheckoutReference::class,
            'order_checkout_reference_tag',
        );
    }
}
