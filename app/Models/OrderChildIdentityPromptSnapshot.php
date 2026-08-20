<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderChildIdentityPromptSnapshot extends Model
{
    protected $guarded = [];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
