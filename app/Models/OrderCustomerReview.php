<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCustomerReview extends Model
{
    public const TYPE_SERVICE_RATING = 'service_rating';

    public const VERSION_CHECKOUT = 'checkout';

    public const DECISION_SUBMITTED = 'submitted';

    public const SOURCE_PUBLIC_LINK = 'public_link';

    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function qualityRating(): ?int
    {
        $rating = data_get($this->metadata, 'quality_rating');

        return is_numeric($rating) ? (int) $rating : null;
    }
}
