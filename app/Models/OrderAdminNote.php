<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class OrderAdminNote extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order notes are append-only and cannot be edited.'));
        static::deleting(fn () => throw new LogicException('Order notes are permanent and cannot be deleted.'));
    }

    public function representativeOrder()
    {
        return $this->belongsTo(Order::class, 'representative_order_id')->withTrashed();
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
