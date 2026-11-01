<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderPaymentReview extends Model
{
    protected $guarded = [];

    protected $casts = ['reviewed_at' => 'datetime', 'review_note' => 'encrypted'];

    public function signals()
    {
        return $this->hasMany(OrderPaymentReviewSignal::class, 'review_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
