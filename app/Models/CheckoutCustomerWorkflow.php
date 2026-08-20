<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CheckoutCustomerWorkflow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'payment_requested_at' => 'datetime',
        'last_customer_activity_at' => 'datetime',
        'metadata' => 'array',
    ];
}
