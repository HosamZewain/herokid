<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaConversionEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_time' => 'integer',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'user_data_encrypted' => 'encrypted:array',
            'custom_data_json' => 'array',
            'sent_at' => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function representativeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'representative_order_id')->withTrashed();
    }
}
