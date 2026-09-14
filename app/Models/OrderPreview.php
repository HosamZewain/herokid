<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPreview extends Model
{
    protected $guarded = [];

    public function order(): BelongsTo
    {
        // Product preview galleries belong to the whole checkout. A single
        // child/order can later be soft-deleted while its siblings and the
        // gallery remain active, so the historical owner must stay resolvable.
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function productGallery(): BelongsTo
    {
        return $this->belongsTo(OrderProductPreviewGallery::class, 'product_gallery_id');
    }
}
