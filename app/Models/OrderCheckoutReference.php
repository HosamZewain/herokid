<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
