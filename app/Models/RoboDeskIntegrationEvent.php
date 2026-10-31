<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RoboDeskIntegrationEvent extends Model
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function confirmationWorkflow(): HasOne
    {
        return $this->hasOne(CheckoutCustomerWorkflow::class, 'checkout_group_key', 'checkout_group_key');
    }

    protected $table = 'robodesk_integration_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'response_payload' => 'array',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
