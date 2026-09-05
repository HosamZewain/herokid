<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderAdminNote extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    public function representativeOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'representative_order_id')->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(OrderAttachment::class, 'attachment_id');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by_user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
