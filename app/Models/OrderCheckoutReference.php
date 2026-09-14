<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderCheckoutReference extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_month' => 'integer',
            'monthly_sequence' => 'integer',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(
            OrderTag::class,
            'order_checkout_reference_tag',
        );
    }
}
