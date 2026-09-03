<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPreview extends Model
{
    protected $guarded = [];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function productGallery(): BelongsTo
    {
        return $this->belongsTo(OrderProductPreviewGallery::class, 'product_gallery_id');
    }
}
